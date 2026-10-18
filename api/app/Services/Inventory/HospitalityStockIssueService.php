<?php

namespace App\Services\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\StockMovement;

class HospitalityStockIssueService
{
    /**
     * Atomically post an issue out of Back Stock. Front issues are transfers;
     * every other destination is an internal consumption/write-off issue.
     */
    public function issue(array $context): array
    {
        $companyId = (string) $context['company_id'];
        $branchId = (string) $context['branch_id'];
        $destination = strtolower((string) ($context['issue_destination'] ?? 'front'));
        $referenceType = (string) ($context['reference_type'] ?? 'enterprise_stock_issue');
        $issueId = (string) ($context['idempotency_key'] ?? Str::uuid());
        $issueNumber = $this->issueNumber($issueId, now());
        $items = collect($context['items'] ?? [])->values();

        $fingerprint = hash('sha256', json_encode([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'issue_name' => $context['issue_name'] ?? null,
            'issue_type' => $context['issue_type'] ?? $destination,
            'destination' => $destination,
            'department_name' => $context['department_name'] ?? null,
            'cost_center' => $context['cost_center'] ?? null,
            'requested_by' => $context['requested_by'] ?? null,
            'issue_purpose' => $context['issue_purpose'] ?? null,
            'notes' => $context['notes'] ?? null,
            'items' => $items
                ->map(fn (array $item) => [
                    'product_id' => (string) $item['product_id'],
                    'quantity' => round((float) $item['quantity'], 4),
                ])
                ->sortBy('product_id')
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $destination,
            $referenceType,
            $issueId,
            $issueNumber,
            $items,
            $fingerprint,
            $context
        ) {
            // This lock serializes issue documents for the branch. It closes the
            // check-then-insert window used by idempotent request replays.
            $branch = DB::table('company_branches')
                ->where('company_id', $companyId)
                ->where('id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $branch) {
                throw ValidationException::withMessages([
                    'branch_id' => ['The selected branch does not belong to this company.'],
                ]);
            }

            $existing = StockMovement::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $issueId)
                ->with('product')
                ->orderBy('created_at')
                ->get();

            if ($existing->isNotEmpty()) {
                $existingFingerprint = (string) data_get($existing->first()?->metadata, 'request_fingerprint', '');
                if ($existingFingerprint !== '' && ! hash_equals($existingFingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for a different stock issue.'],
                    ]);
                }

                return $this->replayResult($existing, $issueId);
            }

            $issued = [];
            $summary = $this->blankSummary();

            foreach ($items as $item) {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->where('id', $item['product_id'])
                    ->where(function ($query) use ($branchId) {
                        $query->whereNull('branch_id')
                            ->orWhere('branch_id', $branchId)
                            ->orWhere('is_global', true);
                    })
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['A selected product is not available to this company branch.'],
                    ]);
                }

                $quantity = round((float) $item['quantity'], 4);
                $stockUsageType = $this->stockUsageType($product);

                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} is Non-Turnover Stock and cannot be transferred to Front of House. Issue it to a department, operating expense, production use, or wastage."],
                    ]);
                }

                $backStock = $this->lockOrCreateStock($product, $branchId, 'back');
                $backBefore = (float) $backStock->available_quantity;

                $affected = DB::table('product_stocks')
                    ->where('id', $backStock->id)
                    ->where('quantity_on_hand', '>=', $quantity)
                    ->where('available_quantity', '>=', $quantity)
                    ->decrementEach([
                        'quantity_on_hand' => $quantity,
                        'available_quantity' => $quantity,
                    ], ['updated_at' => now()]);

                if ($affected !== 1) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient available Back Stock for {$product->name}. Required: {$quantity}; available: ".round($backBefore, 4).'.'],
                    ]);
                }

                $backAfter = round($backBefore - $quantity, 4);
                $frontBefore = null;
                $frontAfter = null;

                if ($destination === 'front') {
                    $frontStock = $this->lockOrCreateStock($product, $branchId, 'front');
                    $frontBefore = (float) $frontStock->available_quantity;

                    DB::table('product_stocks')
                        ->where('id', $frontStock->id)
                        ->incrementEach([
                            'quantity_on_hand' => $quantity,
                            'available_quantity' => $quantity,
                        ], ['updated_at' => now()]);

                    $frontAfter = round($frontBefore + $quantity, 4);
                }

                $cost = ProductCost::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();
                $averageCost = (float) ($cost?->average_cost ?? 0);
                $unitCost = $averageCost > 0 ? $averageCost : (float) ($cost?->last_cost ?? 0);
                $currency = strtoupper((string) ($cost?->currency_code ?? $cost?->currency ?? 'USD'));
                $totalCost = round($quantity * $unitCost, 6);
                $costQuantityBefore = $cost ? (float) $cost->total_quantity : null;
                $costValueBefore = $cost ? (float) $cost->total_value : null;

                if ($cost && $destination !== 'front') {
                    $cost->total_quantity = max(0, $costQuantityBefore - $quantity);
                    $cost->total_value = max(0, $costValueBefore - $totalCost);
                    $cost->average_cost = (float) $cost->total_quantity > 0
                        ? (float) $cost->total_value / (float) $cost->total_quantity
                        : 0;
                    $cost->save();
                }

                $metadata = [
                    'enterprise_issue' => $referenceType === 'enterprise_stock_issue',
                    'issue_id' => $issueId,
                    'issue_number' => $issueNumber,
                    'idempotency_key' => $context['idempotency_key'] ?? null,
                    'request_fingerprint' => $fingerprint,
                    'issue_name' => $context['issue_name'] ?? 'Issue to Front of House',
                    'issue_type' => $context['issue_type'] ?? $destination,
                    'issue_destination' => $destination,
                    'department_name' => $context['department_name'] ?? null,
                    'cost_center' => $context['cost_center'] ?? null,
                    'requested_by' => $context['requested_by'] ?? null,
                    'requested_by_id' => $context['requested_by_id'] ?? $context['user_id'] ?? null,
                    'issue_purpose' => $context['issue_purpose'] ?? null,
                    'header_notes' => $context['notes'] ?? null,
                    'stock_usage_type' => $stockUsageType,
                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
                    'product_type' => $product->product_type,
                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
                    'back_available_before' => round($backBefore, 4),
                    'back_available_after' => $backAfter,
                    'front_available_before' => $frontBefore,
                    'front_available_after' => $frontAfter,
                    'cost_total_quantity_before' => $costQuantityBefore,
                    'cost_total_quantity_after' => $cost ? (float) $cost->total_quantity : null,
                    'cost_total_value_before' => $costValueBefore,
                    'cost_total_value_after' => $cost ? (float) $cost->total_value : null,
                ];

                StockMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'movement_type' => $this->movementType($destination),
                    'stock_area' => $destination === 'front' ? 'front' : 'back',
                    'from_stock_area' => 'back',
                    'to_stock_area' => $destination,
                    // One transfer row deliberately records both sides. Existing
                    // hospitality reconciliation reads quantity_in for FRONT and
                    // quantity_out for BACK from this single auditable movement.
                    'quantity_in' => $destination === 'front' ? $quantity : 0,
                    'quantity_out' => $quantity,
                    'balance_after' => $destination === 'front' ? $frontAfter : $backAfter,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'currency' => $currency,
                    'currency_code' => $currency,
                    'reference_type' => $referenceType,
                    'reference_id' => $issueId,
                    'notes' => $context['notes'] ?? null,
                    'metadata' => $metadata,
                    'issue_destination' => $destination,
                    'stock_usage_type' => $stockUsageType,
                    'expense_account_code' => $product->expense_account_code,
                    'created_by' => $context['user_id'] ?? null,
                ]);

                $line = [
                    'issue_id' => $issueId,
                    'issue_name' => $metadata['issue_name'],
                    'product_id' => (string) $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'product_type' => $product->product_type,
                    'stock_usage_type' => $stockUsageType,
                    'issue_destination' => $destination,
                    'quantity' => $quantity,
                    'unit_cost' => round($unitCost, 6),
                    'total_cost' => $totalCost,
                    'currency' => $currency,
                    'from_stock_area' => 'back',
                    'to_stock_area' => $destination,
                    'back_available_before' => round($backBefore, 4),
                    'back_available_after' => $backAfter,
                    'front_available_before' => $frontBefore,
                    'front_available_after' => $frontAfter,
                    'department_name' => $metadata['department_name'],
                    'cost_center' => $metadata['cost_center'],
                    'requested_by' => $metadata['requested_by'],
                    'requested_by_id' => $metadata['requested_by_id'],
                    'issue_purpose' => $metadata['issue_purpose'],
                ];

                $issued[] = $line;
                $this->addToSummary($summary, $line);
            }

            return [
                'issue_id' => $issueId,
                'issue_number' => $issueNumber,
                'idempotent_replay' => false,
                'header' => $this->header($context + ['issue_number' => $issueNumber], $destination),
                'data' => $issued,
                'summary' => $this->roundedSummary($summary),
            ];
        }, 3);
    }

    private function lockOrCreateStock(Product $product, string $branchId, string $stockArea): ProductStock
    {
        $key = [
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'stock_area' => $stockArea,
        ];

        $stock = ProductStock::query()->where($key)->lockForUpdate()->first();
        if ($stock) {
            return $stock;
        }

        try {
            ProductStock::create($key + [
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
            ]);
        } catch (QueryException $exception) {
            if (! ProductStock::query()->where($key)->exists()) {
                throw $exception;
            }
        }

        return ProductStock::query()->where($key)->lockForUpdate()->firstOrFail();
    }

    private function stockUsageType(Product $product): string
    {
        $usage = str_replace([' ', '-'], '_', strtolower(trim((string) $product->stock_usage_type)));
        if (in_array($usage, ['non_turnover', 'nonturnover', 'non_turn_over'], true)) {
            return 'non_turnover';
        }

        if ($usage === 'turnover') {
            return 'turnover';
        }

        return in_array(strtolower((string) $product->product_type), ['consumable', 'consumables'], true)
            ? 'non_turnover'
            : 'turnover';
    }

    private function movementType(string $destination): string
    {
        return match ($destination) {
            'front' => 'boh_issue_to_front',
            'department' => 'boh_issue_to_department',
            'production' => 'boh_issue_to_production',
            'wastage' => 'wastage',
            default => 'boh_issue_to_expense',
        };
    }

    private function replayResult($movements, string $issueId): array
    {
        $issued = [];
        $summary = $this->blankSummary();
        $firstMetadata = (array) ($movements->first()->metadata ?? []);
        $issueNumber = $firstMetadata['issue_number'] ?? $this->issueNumber(
            $issueId,
            $movements->first()?->created_at ?? now()
        );

        foreach ($movements as $movement) {
            $metadata = (array) ($movement->metadata ?? []);
            $quantity = max((float) $movement->quantity_in, (float) $movement->quantity_out);
            $line = [
                'issue_id' => $issueId,
                'issue_name' => $metadata['issue_name'] ?? 'Stock Issue',
                'product_id' => (string) $movement->product_id,
                'product_name' => $movement->product?->name ?? 'Product',
                'sku' => $movement->product?->sku,
                'code' => $movement->product?->code,
                'product_type' => $movement->product?->product_type,
                'stock_usage_type' => $movement->stock_usage_type ?? $metadata['stock_usage_type'] ?? 'turnover',
                'issue_destination' => $movement->issue_destination ?? $metadata['issue_destination'] ?? $movement->to_stock_area,
                'quantity' => round($quantity, 4),
                'unit_cost' => round((float) $movement->unit_cost, 6),
                'total_cost' => round((float) $movement->total_cost, 6),
                'currency' => $movement->currency_code ?? $movement->currency ?? 'USD',
                'from_stock_area' => $movement->from_stock_area,
                'to_stock_area' => $movement->to_stock_area,
                'back_available_before' => $metadata['back_available_before'] ?? null,
                'back_available_after' => $metadata['back_available_after'] ?? null,
                'front_available_before' => $metadata['front_available_before'] ?? null,
                'front_available_after' => $metadata['front_available_after'] ?? null,
                'department_name' => $metadata['department_name'] ?? null,
                'cost_center' => $metadata['cost_center'] ?? null,
                'requested_by' => $metadata['requested_by'] ?? null,
                'requested_by_id' => $metadata['requested_by_id'] ?? $movement->created_by,
                'issue_purpose' => $metadata['issue_purpose'] ?? null,
            ];
            $issued[] = $line;
            $this->addToSummary($summary, $line);
        }

        return [
            'issue_id' => $issueId,
            'issue_number' => $issueNumber,
            'idempotent_replay' => true,
            'header' => [
                'issue_number' => $issueNumber,
                'issue_name' => $firstMetadata['issue_name'] ?? 'Stock Issue',
                'issue_type' => $firstMetadata['issue_type'] ?? $firstMetadata['issue_destination'] ?? 'front',
                'issue_destination' => $firstMetadata['issue_destination'] ?? 'front',
                'department_name' => $firstMetadata['department_name'] ?? null,
                'cost_center' => $firstMetadata['cost_center'] ?? null,
                'requested_by' => $firstMetadata['requested_by'] ?? null,
                'requested_by_id' => $firstMetadata['requested_by_id'] ?? $movements->first()?->created_by,
                'issue_purpose' => $firstMetadata['issue_purpose'] ?? null,
                'notes' => $firstMetadata['header_notes'] ?? null,
            ],
            'data' => $issued,
            'summary' => $this->roundedSummary($summary),
        ];
    }

    private function header(array $context, string $destination): array
    {
        $issueId = (string) ($context['idempotency_key'] ?? '');

        return [
            'issue_number' => $context['issue_number'] ?? ($issueId !== '' ? $this->issueNumber($issueId, now()) : null),
            'issue_name' => $context['issue_name'] ?? 'Issue to Front of House',
            'issue_type' => $context['issue_type'] ?? $destination,
            'issue_destination' => $destination,
            'department_name' => $context['department_name'] ?? null,
            'cost_center' => $context['cost_center'] ?? null,
            'requested_by' => $context['requested_by'] ?? null,
            'requested_by_id' => $context['requested_by_id'] ?? $context['user_id'] ?? null,
            'issue_purpose' => $context['issue_purpose'] ?? null,
            'notes' => $context['notes'] ?? null,
        ];
    }

    private function issueNumber(string $issueId, mixed $issuedAt): string
    {
        $date = $issuedAt instanceof \DateTimeInterface
            ? $issuedAt->format('Ymd')
            : \Illuminate\Support\Carbon::parse($issuedAt)->format('Ymd');
        $token = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $issueId) ?: 'ISSUE', -12));

        return "ISS-{$date}-{$token}";
    }

    private function blankSummary(): array
    {
        return [
            'turnover_lines' => 0,
            'non_turnover_lines' => 0,
            'front_quantity' => 0,
            'internal_quantity' => 0,
            'front_value' => 0,
            'internal_value' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
        ];
    }

    private function addToSummary(array &$summary, array $line): void
    {
        $quantity = (float) $line['quantity'];
        $value = (float) $line['total_cost'];
        $isFront = ($line['issue_destination'] ?? '') === 'front';

        $summary[$line['stock_usage_type'] === 'non_turnover' ? 'non_turnover_lines' : 'turnover_lines']++;
        $summary[$isFront ? 'front_quantity' : 'internal_quantity'] += $quantity;
        $summary[$isFront ? 'front_value' : 'internal_value'] += $value;
        $summary['total_quantity'] += $quantity;
        $summary['total_value'] += $value;
    }

    private function roundedSummary(array $summary): array
    {
        return collect($summary)
            ->map(fn ($value) => is_numeric($value) ? round((float) $value, 6) : $value)
            ->all();
    }
}
