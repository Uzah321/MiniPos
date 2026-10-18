<?php

namespace App\Services\Sync;

use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Models\SyncOutboxEvent;
use App\Services\Inventory\InventoryVarianceAgentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Product\Models\FrontStockDailyClosing;
use Modules\Product\Models\FrontStockDailyClosingLine;
use RuntimeException;

/**
 * Durable branch-level transport for Hospitality front-stock closings.
 *
 * A closing is operational state, not till configuration.  The till id is
 * retained only as provenance; company + branch + closing date + stock area
 * is the business identity used by Day End on every terminal.
 */
class SyncFrontStockClosingService
{
    private const EVENT_TYPE = 'front_stock.closing.completed';

    private const HEADER_COLUMNS = [
        'id', 'till_id', 'closed_by', 'closing_date', 'stock_area', 'status',
        'total_lines', 'variance_lines', 'expected_total_quantity',
        'counted_total_quantity', 'variance_total_quantity',
        'variance_total_value', 'notes', 'metadata', 'created_at', 'updated_at',
    ];

    private const LINE_COLUMNS = [
        'id', 'front_stock_daily_closing_id', 'product_id', 'product_code',
        'product_sku', 'product_name', 'product_type', 'uom_code', 'stock_area',
        'expected_quantity', 'counted_quantity', 'variance_quantity', 'unit_cost',
        'variance_value', 'notes', 'metadata', 'created_at', 'updated_at',
    ];

    private const MOVEMENT_COLUMNS = [
        'id', 'warehouse_id', 'location_id', 'batch_id', 'till_id',
        'shift_session_id', 'product_id', 'stock_area', 'from_stock_area',
        'to_stock_area', 'issue_destination', 'stock_usage_type',
        'expense_account_code', 'movement_type', 'quantity_in', 'quantity_out',
        'balance_after', 'unit_cost', 'total_cost', 'currency', 'currency_code',
        'reference_type', 'reference_id', 'notes', 'metadata', 'created_by',
        'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly SyncPayloadHasher $hasher,
        private readonly SyncEventSetProof $eventSetProof
    ) {}

    /**
     * Recovery scan used by the always-on edge worker.  Scanning recent rows
     * as well as appending at save time means a process crash cannot lose the
     * closing: the database row itself is the recovery source.
     */
    public function queueCompletedClosings(SyncLocalState $state, int $limit = 100): int
    {
        $this->assertPairedState($state);
        if (! Schema::hasTable('front_stock_daily_closings')) {
            return 0;
        }

        // Do not replay historical physical-count adjustments into today's
        // cloud stock. Day End can only consume the branch's presently open
        // date, so only that closure belongs in this recovery scan.
        $activeDates = Schema::hasTable('trading_days')
            ? DB::table('trading_days')
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->whereIn('status', ['open', 'day_end_running'])
                ->pluck('trading_date')
                ->map(fn ($date): ?string => is_scalar($date)
                    ? Carbon::parse((string) $date)->toDateString()
                    : null)
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
        if ($activeDates === []) {
            return 0;
        }

        $closings = FrontStockDailyClosing::query()
            ->where('company_id', $state->company_id)
            ->where('branch_id', $state->branch_id)
            ->whereRaw("LOWER(COALESCE(stock_area, '')) = 'front'")
            ->where(fn ($query) => $query
                ->whereRaw("LOWER(COALESCE(status, 'closed')) = 'closed'")
                ->orWhereNull('status'))
            ->whereIn('closing_date', $activeDates)
            ->latest('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $queued = 0;
        foreach ($closings as $closing) {
            if ($this->queueClosing($state, $closing)?->wasRecentlyCreated) {
                $queued++;
            }
        }

        return $queued;
    }

    public function queueClosing(
        SyncLocalState $state,
        FrontStockDailyClosing $closing
    ): ?SyncOutboxEvent {
        $this->assertPairedState($state);
        if ((string) $closing->company_id !== (string) $state->company_id
            || (string) $closing->branch_id !== (string) $state->branch_id) {
            throw new InvalidArgumentException('Front-stock closing is outside the paired branch.');
        }

        $payload = $this->bundle(
            (string) $state->company_id,
            (string) $state->branch_id,
            (string) $closing->id
        );
        $payloadHash = $this->hasher->hash($payload);
        $deduplicationKey = self::EVENT_TYPE.':'.(string) $closing->id.':'.substr($payloadHash, 0, 24);

        return DB::transaction(function () use (
            $state,
            $closing,
            $payload,
            $payloadHash,
            $deduplicationKey
        ): SyncOutboxEvent {
            $lockedState = SyncLocalState::query()
                ->whereKey($state->id)
                ->lockForUpdate()
                ->firstOrFail();
            $event = SyncOutboxEvent::query()->firstOrCreate(
                [
                    'source_node_id' => $lockedState->node_id,
                    'deduplication_key' => $deduplicationKey,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'company_id' => $lockedState->company_id,
                    'branch_id' => $lockedState->branch_id,
                    'aggregate_type' => 'front_stock_closing',
                    'aggregate_id' => (string) $closing->id,
                    'event_type' => self::EVENT_TYPE,
                    'schema_version' => 1,
                    'payload' => $payload,
                    'payload_hash' => $payloadHash,
                    'occurred_at' => $closing->updated_at ?: $closing->created_at ?: now(),
                    'available_at' => now(),
                    'attempt_count' => 0,
                ]
            );
            if ($event->wasRecentlyCreated) {
                $this->recordOutboxProof($lockedState, $event);
            }

            return $event;
        }, 3);
    }

    public function bundle(string $companyId, string $branchId, string $closingId): array
    {
        $closing = DB::table('front_stock_daily_closings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', $closingId)
            ->when(
                Schema::hasColumn('front_stock_daily_closings', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->first($this->columns('front_stock_daily_closings', self::HEADER_COLUMNS));
        if (! $closing
            || strtolower((string) ($closing->stock_area ?? '')) !== 'front'
            || ! in_array(strtolower((string) ($closing->status ?? 'closed')), ['closed', ''], true)) {
            throw new RuntimeException('Only a completed FRONT stock closing can enter the sync outbox.');
        }

        $lines = DB::table('front_stock_daily_closing_lines')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('front_stock_daily_closing_id', $closingId)
            ->orderBy('id')
            ->get($this->columns('front_stock_daily_closing_lines', self::LINE_COLUMNS))
            ->map(fn ($row): array => (array) $row)
            ->all();
        $movements = Schema::hasTable('stock_movements')
            ? DB::table('stock_movements')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('reference_type', 'front_stock_daily_closing')
                ->where('reference_id', $closingId)
                ->orderBy('created_at')
                ->get($this->columns('stock_movements', self::MOVEMENT_COLUMNS))
                ->map(fn ($row): array => (array) $row)
                ->all()
            : [];

        return [
            'closing' => (array) $closing,
            'lines' => $lines,
            'stock_movements' => $movements,
            // Keep the recovery payload immutable. Using now() here would
            // create a different hash (and therefore a new outbox event) on
            // every worker scan even though the closing never changed.
            'captured_at' => Carbon::parse(
                $closing->updated_at ?: $closing->created_at
            )->toIso8601String(),
        ];
    }

    /**
     * Import an edge closure under the authenticated node scope.
     *
     * The first branch/date closure wins if two disconnected devices race.
     * This prevents silent last-writer-wins stock corruption while still
     * making the already committed branch closure satisfy Day End everywhere.
     */
    public function import(SyncNode $node, array $payload): array
    {
        $header = $payload['closing'] ?? null;
        $lines = $payload['lines'] ?? null;
        $movements = $payload['stock_movements'] ?? [];
        if (! is_array($header) || ! is_array($lines) || ! is_array($movements)) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_PAYLOAD_INVALID');
        }

        $sourceClosingId = $this->uuid($header['id'] ?? null, 'closing');
        $closingDate = $this->date($header['closing_date'] ?? null);
        if (strtolower(trim((string) ($header['stock_area'] ?? ''))) !== 'front'
            || strtolower(trim((string) ($header['status'] ?? 'closed'))) !== 'closed') {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_STATE_INVALID');
        }

        $existing = FrontStockDailyClosing::query()
            ->withTrashed()
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->whereDate('closing_date', $closingDate)
            ->whereRaw("LOWER(COALESCE(stock_area, '')) = 'front'")
            ->lockForUpdate()
            ->first();

        $existingIsLiveClosed = $existing
            && ! $existing->trashed()
            && in_array(
                strtolower(trim((string) ($existing->status ?? 'closed'))),
                ['', 'closed'],
                true
            );
        if ($existingIsLiveClosed && (string) $existing->id !== $sourceClosingId) {
            // A branch/day has exactly one closing. Retain the first committed
            // authority instead of merging counts from two offline writers.
            return [
                'closing_id' => (string) $existing->id,
                'status' => 'already_closed',
                'incoming_closing_id' => $sourceClosingId,
                'conflict_resolution' => 'first_branch_closure_retained',
            ];
        }

        $incomingUpdatedAt = $this->timestamp($header['updated_at'] ?? null);
        if ($existingIsLiveClosed && $existing->updated_at && $incomingUpdatedAt
            && $existing->updated_at->greaterThanOrEqualTo($incomingUpdatedAt)) {
            return [
                'closing_id' => (string) $existing->id,
                'status' => 'already_imported',
            ];
        }

        // A deleted or draft record still owns the natural unique key. Reuse
        // and restore it instead of treating it as a completed close or
        // colliding while importing the real branch reconciliation.
        $closingId = $existing ? (string) $existing->id : $sourceClosingId;
        $normalisedLines = $this->normaliseLines($node, $closingId, $lines);
        $summary = $this->summary($normalisedLines);
        $tillId = $this->scopedReference('pos_tills', $header['till_id'] ?? null, $node);
        $closedBy = $this->scopedReference('pos_users', $header['closed_by'] ?? null, $node, false);
        $metadata = $this->metadata($header['metadata'] ?? null);
        $headerValues = [
            'id' => $closingId,
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'till_id' => $tillId,
            'closed_by' => $closedBy,
            'closing_date' => $closingDate,
            'stock_area' => 'front',
            'status' => 'closed',
            ...$summary,
            'notes' => isset($header['notes']) ? (string) $header['notes'] : null,
            'metadata' => [
                ...$metadata,
                'sync_origin_node_id' => (string) $node->id,
                'sync_imported_at' => now()->toIso8601String(),
                'branch_wide' => true,
                ...($closingId !== $sourceClosingId
                    ? ['source_closing_id' => $sourceClosingId]
                    : []),
            ],
            'created_at' => $this->timestamp($header['created_at'] ?? null) ?: now(),
            'updated_at' => $incomingUpdatedAt ?: now(),
            'deleted_at' => null,
        ];

        $closing = $existing ?: new FrontStockDailyClosing;
        $closing->forceFill($this->filterColumns('front_stock_daily_closings', $headerValues))->save();
        FrontStockDailyClosingLine::query()
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('front_stock_daily_closing_id', $closing->id)
            ->delete();
        foreach ($normalisedLines as $line) {
            FrontStockDailyClosingLine::query()->create($line);
        }
        foreach ($movements as $movement) {
            $this->importMovement(
                $node,
                $sourceClosingId,
                $closingId,
                $normalisedLines,
                $movement
            );
        }
        // A counted line is a new stock authority even when its delta is zero
        // and therefore has no movement. Keep the cloud variance baseline in
        // the same inbox transaction as the imported closing and projections.
        foreach ($normalisedLines as $line) {
            app(InventoryVarianceAgentService::class)->resetProduct(
                (string) $node->company_id,
                (string) $node->branch_id,
                (string) $line['product_id'],
                'front_stock_closing',
                'front_stock_daily_closing',
                (string) $closingId,
                $closedBy,
                'front'
            );
        }

        return [
            'closing_id' => (string) $closing->id,
            'status' => $existing ? 'updated' : 'imported',
            'line_count' => count($normalisedLines),
        ];
    }

    private function normaliseLines(SyncNode $node, string $closingId, array $lines): array
    {
        $normalised = [];
        $seenProducts = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_LINE_INVALID');
            }
            $lineId = $this->uuid($line['id'] ?? null, 'closing line');
            $productId = $this->uuid($line['product_id'] ?? null, 'closing product');
            if (isset($seenProducts[$productId])) {
                throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_PRODUCT_DUPLICATED');
            }
            $seenProducts[$productId] = true;
            if (! DB::table('products')->where('id', $productId)
                ->where('company_id', $node->company_id)->exists()) {
                throw new RuntimeException("SYNC_DEPENDENCY_MISSING: products:{$productId}");
            }

            $expected = round((float) ($line['expected_quantity'] ?? 0), 6);
            $counted = round((float) ($line['counted_quantity'] ?? 0), 6);
            if ($counted < 0) {
                throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_QUANTITY_INVALID');
            }
            $variance = round($counted - $expected, 6);
            $unitCost = round((float) ($line['unit_cost'] ?? 0), 6);
            $normalised[] = $this->filterColumns('front_stock_daily_closing_lines', [
                'id' => $lineId,
                'front_stock_daily_closing_id' => $closingId,
                'company_id' => (string) $node->company_id,
                'branch_id' => (string) $node->branch_id,
                'product_id' => $productId,
                'product_code' => $line['product_code'] ?? null,
                'product_sku' => $line['product_sku'] ?? null,
                'product_name' => (string) ($line['product_name'] ?? 'Product'),
                'product_type' => $line['product_type'] ?? null,
                'uom_code' => $line['uom_code'] ?? null,
                'stock_area' => 'front',
                'expected_quantity' => $expected,
                'counted_quantity' => $counted,
                'variance_quantity' => $variance,
                'unit_cost' => $unitCost,
                'variance_value' => round($variance * $unitCost, 6),
                'notes' => $line['notes'] ?? null,
                'metadata' => $this->metadata($line['metadata'] ?? null),
                'created_at' => $this->timestamp($line['created_at'] ?? null) ?: now(),
                'updated_at' => $this->timestamp($line['updated_at'] ?? null) ?: now(),
            ]);
        }

        return $normalised;
    }

    private function importMovement(
        SyncNode $node,
        string $sourceClosingId,
        string $targetClosingId,
        array $lines,
        mixed $movement
    ): void {
        if (! is_array($movement)) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_MOVEMENT_INVALID');
        }
        $movementId = $this->uuid($movement['id'] ?? null, 'stock movement');
        if ((string) ($movement['reference_type'] ?? '') !== 'front_stock_daily_closing'
            || (string) ($movement['reference_id'] ?? '') !== $sourceClosingId) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_MOVEMENT_REFERENCE_INVALID');
        }
        $productId = $this->uuid($movement['product_id'] ?? null, 'movement product');
        if (! collect($lines)->contains(fn (array $line): bool => (string) $line['product_id'] === $productId)) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_MOVEMENT_PRODUCT_INVALID');
        }
        $existingMovement = DB::table('stock_movements')->where('id', $movementId)->first();
        if ($existingMovement) {
            if ((string) $existingMovement->company_id !== (string) $node->company_id
                || (string) $existingMovement->branch_id !== (string) $node->branch_id
                || (string) $existingMovement->reference_type !== 'front_stock_daily_closing'
                || (string) $existingMovement->reference_id !== $targetClosingId
                || (string) $existingMovement->product_id !== $productId) {
                throw new InvalidArgumentException('SYNC_FRONT_STOCK_MOVEMENT_ID_CONFLICT');
            }

            return;
        }
        $quantityIn = round(max(0, (float) ($movement['quantity_in'] ?? 0)), 6);
        $quantityOut = round(max(0, (float) ($movement['quantity_out'] ?? 0)), 6);
        if ($quantityIn > 0 && $quantityOut > 0) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_MOVEMENT_QUANTITY_INVALID');
        }
        $delta = round($quantityIn - $quantityOut, 6);

        $stock = DB::table('product_stocks')
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('product_id', $productId)
            ->when(
                Schema::hasColumn('product_stocks', 'stock_area'),
                fn ($query) => $query->where('stock_area', 'front')
            )
            ->lockForUpdate()
            ->first();
        if (! $stock) {
            throw new RuntimeException("SYNC_DEPENDENCY_MISSING: product_stocks:{$productId}:front");
        }
        $newQuantity = round((float) $stock->quantity_on_hand + $delta, 6);
        $reserved = round((float) ($stock->reserved_quantity ?? 0), 6);
        DB::table('product_stocks')->where('id', $stock->id)->update($this->filterColumns('product_stocks', [
            'quantity_on_hand' => $newQuantity,
            'available_quantity' => max(0, round($newQuantity - $reserved, 6)),
            'updated_at' => now(),
        ]));

        $values = array_intersect_key($movement, array_flip($this->columns('stock_movements', self::MOVEMENT_COLUMNS)));
        $values = [
            ...$values,
            'id' => $movementId,
            'company_id' => (string) $node->company_id,
            'branch_id' => (string) $node->branch_id,
            'till_id' => $this->scopedReference('pos_tills', $movement['till_id'] ?? null, $node),
            'product_id' => $productId,
            'stock_area' => 'front',
            'movement_type' => 'adjustment',
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'balance_after' => $newQuantity,
            'reference_type' => 'front_stock_daily_closing',
            'reference_id' => $targetClosingId,
            'metadata' => json_encode([
                ...$this->metadata($movement['metadata'] ?? null),
                'sync_origin_node_id' => (string) $node->id,
                'sync_imported_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $this->timestamp($movement['created_at'] ?? null) ?: now(),
            'updated_at' => $this->timestamp($movement['updated_at'] ?? null) ?: now(),
        ];
        DB::table('stock_movements')->insert($this->filterColumns('stock_movements', $values));
    }

    private function summary(array $lines): array
    {
        $summary = [
            'total_lines' => count($lines),
            'variance_lines' => 0,
            'expected_total_quantity' => 0.0,
            'counted_total_quantity' => 0.0,
            'variance_total_quantity' => 0.0,
            'variance_total_value' => 0.0,
        ];
        foreach ($lines as $line) {
            $variance = (float) ($line['variance_quantity'] ?? 0);
            $summary['variance_lines'] += abs($variance) > 0.000001 ? 1 : 0;
            $summary['expected_total_quantity'] += (float) ($line['expected_quantity'] ?? 0);
            $summary['counted_total_quantity'] += (float) ($line['counted_quantity'] ?? 0);
            $summary['variance_total_quantity'] += $variance;
            $summary['variance_total_value'] += (float) ($line['variance_value'] ?? 0);
        }

        foreach (array_slice(array_keys($summary), 2) as $key) {
            $summary[$key] = round((float) $summary[$key], 6);
        }

        return $summary;
    }

    private function recordOutboxProof(SyncLocalState $state, SyncOutboxEvent $event): void
    {
        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $proof = $metadata['outbox_event_set_proof'] ?? null;
        if ($this->eventSetProof->valid($proof)) {
            $proof = $this->eventSetProof->add($proof, (string) $event->id, (string) $event->payload_hash);
        } else {
            $proof = $this->eventSetProof->empty();
            foreach (SyncOutboxEvent::query()
                ->where('source_node_id', $state->node_id)
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->orderBy('id')
                ->cursor(['id', 'payload_hash']) as $existing) {
                $proof = $this->eventSetProof->add(
                    $proof,
                    (string) $existing->id,
                    (string) $existing->payload_hash
                );
            }
        }
        $state->forceFill(['metadata' => [
            ...$metadata,
            'outbox_event_set_proof' => [
                ...$proof,
                'updated_at' => now()->toIso8601String(),
            ],
        ]])->save();
    }

    private function scopedReference(
        string $table,
        mixed $id,
        SyncNode $node,
        bool $requireBranch = true
    ): ?string {
        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }
        $id = trim((string) $id);
        $query = DB::table($table)->where('id', $id)->where('company_id', $node->company_id);
        if ($requireBranch && Schema::hasColumn($table, 'branch_id')) {
            $query->where('branch_id', $node->branch_id);
        }

        return $query->exists() ? $id : null;
    }

    private function assertPairedState(SyncLocalState $state): void
    {
        if (! $state->node_id || ! $state->company_id || ! $state->branch_id) {
            throw new RuntimeException('The local sync node is not paired to a company and branch.');
        }
    }

    private function uuid(mixed $value, string $label): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException("SYNC_".strtoupper(str_replace(' ', '_', $label)).'_ID_INVALID');
        }

        return $value;
    }

    private function date(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException('SYNC_FRONT_STOCK_CLOSING_DATE_INVALID');
        }
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function metadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function columns(string $table, array $allowlist): array
    {
        $available = array_flip(Schema::getColumnListing($table));

        return array_values(array_filter(
            $allowlist,
            static fn (string $column): bool => isset($available[$column])
        ));
    }

    private function filterColumns(string $table, array $payload): array
    {
        $available = array_flip(Schema::getColumnListing($table));

        return array_intersect_key($payload, $available);
    }
}
