<?php

namespace App\Services\Inventory;

use App\Jobs\ContinueInventoryVarianceScan;
use App\Jobs\ScanInventoryVariance;
use App\Services\CompanySettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An explainable stock integrity monitor. The movement ledger is the expected
 * balance; product_stocks is the counted/operational balance. A new baseline is
 * established when monitoring begins or an authorised reconciliation is posted.
 */
class InventoryVarianceAgentService
{
    private const ENABLED = 'inventory_variance_agent_enabled';
    private const QUANTITY_THRESHOLD = 'inventory_variance_quantity_threshold';
    private const VALUE_THRESHOLD = 'inventory_variance_value_threshold';
    private const SCAN_INTERVAL = 'inventory_variance_scan_interval_minutes';

    public function __construct(private readonly CompanySettingService $settings) {}

    public function settings(string $companyId): array
    {
        return [
            'enabled' => $this->settings->enabled($companyId, self::ENABLED),
            'quantity_threshold' => max(0.000001, (float) $this->settings->get($companyId, self::QUANTITY_THRESHOLD, 0.0001)),
            'value_threshold' => max(0, (float) $this->settings->get($companyId, self::VALUE_THRESHOLD, 0)),
            'scan_interval_minutes' => max(1, (int) $this->settings->get($companyId, self::SCAN_INTERVAL, 5)),
        ];
    }

    /**
     * The overview only queues a scan; a queue worker evaluates the ledger so
     * management pages stay responsive even with a large product catalogue.
     * The queued job is unique per company and branch selection.
     *
     * @param array<int, string> $branchIds
     */
    public function queueScan(
        string $companyId, array $branchIds, ?string $actorId = null, string $trigger = 'overview'
    ): bool {
        $settings = $this->settings($companyId);
        if (!$settings['enabled'] || $branchIds === []) {
            return false;
        }
        $branchIds = array_values(array_unique(array_map('strval', $branchIds)));
        sort($branchIds);

        if (in_array($trigger, ['overview', 'scheduled'], true)) {
            $last = $this->lastScan($companyId, $branchIds);
            if ($last && $last->status === 'completed'
                && Carbon::parse($last->completed_at)->greaterThanOrEqualTo(
                    now()->subMinutes($settings['scan_interval_minutes'])
                )) {
                return false;
            }
        }

        $running = DB::table('inventory_variance_scan_runs')
            ->where('company_id', $companyId)
            ->where('branch_scope_key', hash('sha256', implode(',', $branchIds)))
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subHours(2))
            ->exists();
        if ($running) {
            return false;
        }

        $runId = (string) Str::uuid();
        DB::table('inventory_variance_scan_runs')->insert([
            'id' => $runId, 'company_id' => $companyId, 'status' => 'queued',
            'trigger' => $trigger, 'branch_ids' => json_encode($branchIds),
            'branch_scope_key' => hash('sha256', implode(',', $branchIds)),
            'triggered_by' => $actorId, 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            ScanInventoryVariance::dispatch($companyId, $branchIds, $actorId, $trigger, $runId)
                ->onConnection('database');
        } catch (Throwable $e) {
            DB::table('inventory_variance_scan_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
        return true;
    }

    /** @param array{enabled?: bool, quantity_threshold?: float, value_threshold?: float, scan_interval_minutes?: int} $values */
    public function updateSettings(string $companyId, array $values, ?string $actorId = null): array
    {
        $before = $this->settings($companyId);
        $map = [
            'enabled' => [self::ENABLED, 'boolean'],
            'quantity_threshold' => [self::QUANTITY_THRESHOLD, 'decimal'],
            'value_threshold' => [self::VALUE_THRESHOLD, 'decimal'],
            'scan_interval_minutes' => [self::SCAN_INTERVAL, 'integer'],
        ];

        DB::transaction(function () use ($companyId, $values, $map, $actorId, $before): void {
            foreach ($values as $field => $value) {
                if (!isset($map[$field])) {
                    continue;
                }
                [$key, $type] = $map[$field];
                $stored = $type === 'boolean' ? ($value ? 'true' : 'false') : (string) $value;
                $query = DB::table('general_settings')
                    ->where('company_id', $companyId)
                    ->whereNull('branch_id')
                    ->where('setting_key', $key);
                if ($query->exists()) {
                    $query->update([
                        'setting_value' => $stored,
                        'setting_type' => $type,
                        'is_active' => true,
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('general_settings')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'branch_id' => null,
                        'setting_key' => $key,
                        'setting_value' => $stored,
                        'setting_type' => $type,
                        'module' => 'inventory',
                        'description' => 'Inventory Variance Agent setting',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($values !== []) {
                $this->event($companyId, null, null, null, null, 'settings_changed', 'configuration', null, $actorId, [
                    'before' => $before,
                    'changed_fields' => array_keys($values),
                ]);
            }
        });

        return $this->settings($companyId);
    }

    /**
     * Called inside the same transaction as the adjustment/stocktake/import.
     * Closing the incident and writing the new baseline are atomic with stock.
     */
    public function resetProduct(
        string $companyId,
        string $branchId,
        string $productId,
        string $reason,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $actorId = null,
        string $stockArea = 'front'
    ): void {
        $stockArea = $this->area($stockArea);
        if (!$this->settings($companyId)['enabled']) {
            return;
        }

        $stock = $this->stockQuery($companyId, $branchId, $productId, $stockArea)
            ->lockForUpdate()->first();
        $actual = (float) ($stock->quantity_on_hand ?? 0);
        $ledger = $this->ledger($companyId, $branchId, $productId, $stockArea);
        $cycle = $this->activeCycle($companyId, $branchId, $productId, $stockArea)->lockForUpdate()->first();

        // Retried stock writes can invoke this hook twice. The source identifies
        // a posted document and must never create a second reset episode.
        if ($cycle && $sourceId !== null && $cycle->source_type === $sourceType
            && $cycle->source_id === $sourceId
            && abs((float) $cycle->baseline_quantity - $actual) < 0.000001
            && abs((float) $cycle->baseline_ledger_quantity - $ledger['net']) < 0.000001) {
            return;
        }

        if ($cycle) {
            $open = DB::table('inventory_variances')
                ->where('cycle_id', $cycle->id)->where('status', 'open')
                ->lockForUpdate()->first();
            if ($open) {
                DB::table('inventory_variances')->where('id', $open->id)->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolution_reason' => $reason,
                    'resolution_source_type' => $sourceType,
                    'resolution_source_id' => $sourceId,
                    'resolved_by' => $actorId,
                    'updated_at' => now(),
                ]);
                $this->event($companyId, $branchId, $productId, $stockArea, $cycle->id,
                    'variance_resolved', $sourceType, $sourceId, $actorId,
                    ['variance_id' => $open->id, 'reason' => $reason, 'new_balance' => $actual], $open->id);
            }
            DB::table('inventory_variance_cycles')->where('id', $cycle->id)->update([
                'status' => 'closed', 'active_key' => null,
                'closed_at' => now(), 'close_reason' => $reason,
                'closed_source_type' => $sourceType, 'closed_source_id' => $sourceId,
                'closed_by' => $actorId, 'updated_at' => now(),
            ]);
        }

        $this->newCycle($companyId, $branchId, $productId, $stockArea, $actual,
            $ledger, $reason, $sourceType, $sourceId, $actorId);
    }

    /** @param array<int, string> $branchIds */
    public function scanCompany(
        string $companyId,
        array $branchIds,
        ?string $actorId = null,
        string $trigger = 'overview',
        ?string $runId = null,
        ?string $afterStockId = null
    ): array {
        $settings = $this->settings($companyId);
        if (!$settings['enabled']) {
            if ($runId !== null) {
                DB::table('inventory_variance_scan_runs')->where('id', $runId)
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['queued', 'running'])
                    ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
            }
            return ['enabled' => false, 'scanned' => 0, 'opened' => 0, 'updated' => 0, 'baselines_created' => 0];
        }

        $branchIds = array_values(array_unique(array_filter(array_map('strval', $branchIds))));
        sort($branchIds);
        if ($branchIds === []) {
            if ($runId !== null) {
                DB::table('inventory_variance_scan_runs')->where('id', $runId)
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['queued', 'running'])
                    ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
            }
            return ['enabled' => true, 'scanned' => 0, 'opened' => 0, 'updated' => 0, 'baselines_created' => 0];
        }

        if ($runId === null && in_array($trigger, ['overview', 'scheduled'], true)) {
            $last = $this->lastScan($companyId, $branchIds);
            if ($last && $last->status === 'completed'
                && Carbon::parse($last->completed_at)->greaterThanOrEqualTo(
                    now()->subMinutes($settings['scan_interval_minutes'])
                )) {
                return ['enabled' => true, 'skipped' => true, 'scanned' => 0, 'opened' => 0, 'updated' => 0, 'baselines_created' => 0];
            }
        }

        if ($runId === null) {
            $runId = (string) Str::uuid();
            DB::table('inventory_variance_scan_runs')->insert([
                'id' => $runId, 'company_id' => $companyId, 'status' => 'running',
                'trigger' => $trigger, 'branch_ids' => json_encode($branchIds),
                'branch_scope_key' => hash('sha256', implode(',', $branchIds)),
                'triggered_by' => $actorId, 'started_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($afterStockId === null) {
            $run = DB::table('inventory_variance_scan_runs')->where('id', $runId)
                ->where('company_id', $companyId)->where('status', 'queued')->first();
            if (!$run || $run->branch_scope_key !== hash('sha256', implode(',', $branchIds))) {
                return ['enabled' => true, 'skipped' => true, 'scanned' => 0,
                    'opened' => 0, 'updated' => 0, 'baselines_created' => 0];
            }
            DB::table('inventory_variance_scan_runs')->where('id', $runId)->update([
                'status' => 'running', 'started_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $run = DB::table('inventory_variance_scan_runs')->where('id', $runId)
                ->where('company_id', $companyId)->where('status', 'running')->first();
            if (!$run || $run->last_stock_id !== $afterStockId
                || $run->branch_scope_key !== hash('sha256', implode(',', $branchIds))) {
                return ['enabled' => true, 'skipped' => true, 'scanned' => 0,
                    'opened' => 0, 'updated' => 0, 'baselines_created' => 0];
            }
        }
        $counts = ['enabled' => true, 'scanned' => 0, 'opened' => 0, 'updated' => 0, 'baselines_created' => 0];

        try {
            $stockScope = DB::table('product_stocks')
                ->where('company_id', $companyId)->whereIn('branch_id', $branchIds)
                ->when($afterStockId !== null, fn ($query) => $query->where('id', '>', $afterStockId));
            $stocks = (clone $stockScope)
                ->select('id', 'branch_id', 'product_id', 'stock_area')
                ->orderBy('id')->limit(250)->get();
            foreach ($stocks as $stock) {
                $result = DB::transaction(fn () => $this->scanProduct(
                    $companyId, (string) $stock->branch_id, (string) $stock->product_id,
                    $this->area($stock->stock_area), $settings
                ));
                $counts['scanned']++;
                if (isset($counts[$result])) {
                    $counts[$result]++;
                }
            }
            $lastStockId = $stocks->last()?->id;
            $hasMore = $lastStockId !== null && (clone $stockScope)
                ->where('id', '>', $lastStockId)->exists();
            $previous = DB::table('inventory_variance_scan_runs')->where('id', $runId)->first();
            DB::table('inventory_variance_scan_runs')->where('id', $runId)->update([
                'status' => $hasMore ? 'running' : 'completed',
                'last_stock_id' => $lastStockId ?? $afterStockId,
                'products_scanned' => (int) $previous->products_scanned + $counts['scanned'],
                'baselines_created' => (int) $previous->baselines_created + $counts['baselines_created'],
                'variances_opened' => (int) $previous->variances_opened + $counts['opened'],
                'variances_updated' => (int) $previous->variances_updated + $counts['updated'],
                'completed_at' => $hasMore ? null : now(), 'updated_at' => now(),
            ]);
            if ($hasMore) {
                ContinueInventoryVarianceScan::dispatch($companyId, $branchIds, $actorId,
                    $trigger, $runId, (string) $lastStockId)->onConnection('database');
            }
        } catch (Throwable $e) {
            DB::table('inventory_variance_scan_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }

        return $counts + ['scan_id' => $runId];
    }

    /** @param array<string, mixed> $settings */
    private function scanProduct(
        string $companyId,
        string $branchId,
        string $productId,
        string $stockArea,
        array $settings
    ): string {
        $stock = $this->stockQuery($companyId, $branchId, $productId, $stockArea)
            ->lockForUpdate()->first();
        if (!$stock) {
            return 'unchanged';
        }
        $actual = (float) $stock->quantity_on_hand;
        $ledger = $this->ledger($companyId, $branchId, $productId, $stockArea);
        $cycle = $this->activeCycle($companyId, $branchId, $productId, $stockArea)->lockForUpdate()->first();
        if (!$cycle) {
            $this->newCycle($companyId, $branchId, $productId, $stockArea, $actual,
                $ledger, 'monitoring_enabled', null, null, null);
            return 'baselines_created';
        }

        $expected = round((float) $cycle->baseline_quantity
            + $ledger['net'] - (float) $cycle->baseline_ledger_quantity, 6);
        $variance = round($actual - $expected, 6);
        $existing = DB::table('inventory_variances')->where('cycle_id', $cycle->id)
            ->where('status', 'open')->lockForUpdate()->first();
        $now = now();
        DB::table('inventory_variance_cycles')->where('id', $cycle->id)->update([
            'last_scanned_at' => $now, 'updated_at' => $now,
        ]);

        if (abs($variance) < (float) $settings['quantity_threshold']) {
            // An observed case remains in the audit queue until a posted stock
            // adjustment or stocktake reconciles its baseline.
            if (!$existing) {
                DB::table('inventory_variance_cycles')->where('id', $cycle->id)
                    ->update(['last_consistent_at' => $now, 'updated_at' => $now]);
            }
            return 'unchanged';
        }

        $cost = DB::table('product_costs')
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('product_id', $productId)->first();
        $unitCost = (float) (($cost->average_cost ?? 0) ?: ($cost->last_cost ?? 0));
        $exposure = round(abs($variance) * $unitCost, 6);
        $valueThreshold = (float) $settings['value_threshold'];
        $quantityRatio = abs($variance) / max(abs($expected), 1);
        $severity = ($valueThreshold > 0 && $exposure >= $valueThreshold * 2) || $quantityRatio >= 0.2
            ? 'high'
            : ((($valueThreshold > 0 && $exposure >= $valueThreshold) || $quantityRatio >= 0.05)
                ? 'medium' : 'low');
        $recentMovements = $this->movementQuery($companyId, $branchId, $productId, $stockArea)
            ->select('id', 'movement_type', 'quantity_in', 'quantity_out', 'balance_after',
                'reference_type', 'reference_id', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()->toArray();
        $firstCandidate = $this->movementQuery($companyId, $branchId, $productId, $stockArea)
            ->where('created_at', '>', $cycle->last_consistent_at ?? $cycle->baseline_at)
            ->select('id', 'movement_type', 'reference_type', 'reference_id', 'created_at')
            ->orderBy('created_at')->orderBy('id')->first();
        $evidence = json_encode([
            'explanation' => 'Operational stock differs from the opening balance plus posted movement ledger.',
            'baseline_at' => $cycle->baseline_at,
            'baseline_source' => ['type' => $cycle->source_type, 'id' => $cycle->source_id],
            'detection_window' => [
                'last_consistent_at' => $cycle->last_consistent_at ?? $cycle->baseline_at,
                'first_detected_at' => $existing->first_detected_at ?? $now,
            ],
            'earliest_candidate_movement' => $firstCandidate,
            'candidate_is_confirmed_cause' => false,
            'baseline_quantity' => (float) $cycle->baseline_quantity,
            'baseline_movement_count' => (int) $cycle->baseline_movement_count,
            'movement_count' => $ledger['count'],
            'ledger_delta_since_baseline' => round($ledger['net'] - (float) $cycle->baseline_ledger_quantity, 6),
            'recent_movements' => $recentMovements,
        ], JSON_THROW_ON_ERROR);

        if ($existing) {
            DB::table('inventory_variances')->where('id', $existing->id)->update([
                'direction' => $variance < 0 ? 'shortage' : 'surplus',
                'severity' => $severity, 'expected_quantity' => $expected,
                'actual_quantity' => $actual, 'variance_quantity' => $variance,
                'absolute_variance_quantity' => abs($variance),
                'unit_cost' => $unitCost, 'exposure_value' => $exposure,
                'currency_code' => $cost->currency_code ?? $cost->currency ?? null,
                'last_detected_at' => $now, 'detection_count' => $existing->detection_count + 1,
                'evidence' => $evidence, 'updated_at' => $now,
            ]);
            return 'updated';
        }

        $varianceId = (string) Str::uuid();
        DB::table('inventory_variances')->insert([
            'id' => $varianceId, 'cycle_id' => $cycle->id,
            'company_id' => $companyId, 'branch_id' => $branchId,
            'product_id' => $productId, 'stock_area' => $stockArea,
            'status' => 'open', 'direction' => $variance < 0 ? 'shortage' : 'surplus',
            'severity' => $severity, 'expected_quantity' => $expected,
            'actual_quantity' => $actual, 'variance_quantity' => $variance,
            'absolute_variance_quantity' => abs($variance),
            'unit_cost' => $unitCost, 'exposure_value' => $exposure,
            'currency_code' => $cost->currency_code ?? $cost->currency ?? null,
            'started_at' => $now, 'first_detected_at' => $now,
            'last_detected_at' => $now, 'detection_count' => 1,
            'evidence' => $evidence, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->event($companyId, $branchId, $productId, $stockArea, $cycle->id,
            'variance_opened', null, null, null,
            ['expected_quantity' => $expected, 'actual_quantity' => $actual,
                'variance_quantity' => $variance, 'exposure_value' => $exposure], $varianceId);

        return 'opened';
    }

    /** @param array{net: float, count: int} $ledger */
    private function newCycle(
        string $companyId, string $branchId, string $productId, string $stockArea,
        float $actual, array $ledger, string $reason,
        ?string $sourceType, ?string $sourceId, ?string $actorId
    ): void {
        $id = (string) Str::uuid();
        DB::table('inventory_variance_cycles')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId,
            'product_id' => $productId, 'stock_area' => $stockArea,
            'status' => 'active', 'active_key' => true,
            'baseline_quantity' => $actual, 'baseline_ledger_quantity' => $ledger['net'],
            'baseline_movement_count' => $ledger['count'],
            'baseline_at' => now(), 'baseline_reason' => $reason,
            'last_consistent_at' => now(),
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'opened_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($companyId, $branchId, $productId, $stockArea, $id,
            'baseline_started', $sourceType, $sourceId, $actorId,
            ['reason' => $reason, 'baseline_quantity' => $actual,
                'baseline_movement_count' => $ledger['count']]);
    }

    private function ledger(string $companyId, string $branchId, string $productId, string $stockArea): array
    {
        $row = $this->movementQuery($companyId, $branchId, $productId, $stockArea)
            ->selectRaw('COUNT(*) as movement_count, COALESCE(SUM(quantity_in - quantity_out), 0) as net_quantity')
            ->first();

        return ['count' => (int) ($row->movement_count ?? 0), 'net' => (float) ($row->net_quantity ?? 0)];
    }

    private function stockQuery(string $companyId, string $branchId, string $productId, string $stockArea)
    {
        return DB::table('product_stocks')->where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('product_id', $productId)
            ->where('stock_area', $stockArea);
    }

    private function movementQuery(string $companyId, string $branchId, string $productId, string $stockArea)
    {
        return DB::table('stock_movements')->where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('product_id', $productId)
            ->where(function ($query) use ($stockArea) {
                $query->where('stock_area', $stockArea);
                if ($stockArea === 'front') {
                    $query->orWhereNull('stock_area');
                }
            });
    }

    private function activeCycle(string $companyId, string $branchId, string $productId, string $stockArea)
    {
        return DB::table('inventory_variance_cycles')->where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('product_id', $productId)
            ->where('stock_area', $stockArea)->where('status', 'active');
    }

    /** @param array<int, string> $branchIds */
    public function lastScan(string $companyId, array $branchIds): ?object
    {
        sort($branchIds);
        return DB::table('inventory_variance_scan_runs')
            ->where('company_id', $companyId)->whereIn('status', ['completed', 'failed'])
            ->where('branch_scope_key', hash('sha256', implode(',', $branchIds)))
            ->orderByDesc('started_at')->first();
    }

    private function area(?string $area): string
    {
        $area = strtolower(trim((string) $area));
        return $area === '' ? 'front' : $area;
    }

    private function event(
        string $companyId, ?string $branchId, ?string $productId, ?string $stockArea,
        ?string $cycleId, string $eventType, ?string $sourceType, ?string $sourceId,
        ?string $actorId, array $payload, ?string $varianceId = null
    ): void {
        DB::table('inventory_variance_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'branch_id' => $branchId, 'product_id' => $productId,
            'stock_area' => $stockArea, 'cycle_id' => $cycleId,
            'variance_id' => $varianceId, 'event_type' => $eventType,
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'actor_id' => $actorId, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
