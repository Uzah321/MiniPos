<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Product\Models\ProductStock;

/**
 * Central stock movement writer.
 *
 * Important:
 * - Your existing stock logic updates product_stocks first.
 * - This service writes the ledger after that update.
 * - It now resolves and stores unit_cost, total_cost, currency and currency_code,
 *   especially for sales movements.
 */
class StockMovementService
{
    public const TYPE_OPENING = 'opening';
    public const TYPE_RECEIVE = 'receive';
    public const TYPE_SALE = 'sale';
    public const TYPE_SALE_VOID = 'sale_void';
    public const TYPE_RETURN = 'return';
    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';
    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';
    public const TYPE_BREAK_PARENT_OUT = 'break_parent_out';
    public const TYPE_BREAK_CHILD_IN = 'break_child_in';
    public const TYPE_WASTAGE = 'wastage';
    public const TYPE_STOCK_TAKE_GAIN = 'stock_take_gain';
    public const TYPE_STOCK_TAKE_LOSS = 'stock_take_loss';

    public function record(array $data): void
    {
        $quantityIn = round((float) ($data['quantity_in'] ?? 0), 4);
        $quantityOut = round((float) ($data['quantity_out'] ?? 0), 4);

        if ($quantityIn <= 0 && $quantityOut <= 0) {
            return;
        }

        $companyId = $data['company_id'];
        $branchId = $data['branch_id'] ?? null;
        $productId = $data['product_id'];

        $cost = $this->resolveProductCost(
            companyId: $companyId,
            branchId: $branchId,
            productId: $productId
        );

        $quantityForCost = $quantityIn > 0 ? $quantityIn : $quantityOut;

        $unitCost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null
            ? (float) $data['unit_cost']
            : (float) ($cost['unit_cost'] ?? 0);

        $totalCost = array_key_exists('total_cost', $data) && $data['total_cost'] !== null
            ? (float) $data['total_cost']
            : round($unitCost * $quantityForCost, 4);

        $currency = $data['currency']
            ?? $data['currency_code']
            ?? $cost['currency']
            ?? null;

        $row = [
            'id' => $data['id'] ?? (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'movement_type' => $data['movement_type'],
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($totalCost, 4),
            'notes' => $data['notes'] ?? null,
            'created_at' => $data['created_at'] ?? now(),
            'updated_at' => now(),
        ];

        $this->addIfColumnExists($row, 'reference_type', $data['reference_type'] ?? null);
        $this->addIfColumnExists($row, 'reference_id', $data['reference_id'] ?? null);
        $this->addIfColumnExists($row, 'created_by', $data['created_by'] ?? auth()->id());
        $this->addIfColumnExists($row, 'user_id', $data['user_id'] ?? auth()->id());
        $this->addIfColumnExists($row, 'till_id', $data['till_id'] ?? null);
        $this->addIfColumnExists($row, 'shift_id', $data['shift_id'] ?? null);
        $this->addIfColumnExists($row, 'shift_session_id', $data['shift_session_id'] ?? null);
        $this->addIfColumnExists($row, 'warehouse_id', $data['warehouse_id'] ?? null);
        $this->addIfColumnExists($row, 'location_id', $data['location_id'] ?? null);
        $this->addIfColumnExists($row, 'batch_id', $data['batch_id'] ?? null);

        $this->addIfColumnExists($row, 'currency', $currency);
        $this->addIfColumnExists($row, 'currency_code', $currency);

        $this->addIfColumnExists($row, 'base_currency_code', $data['base_currency_code'] ?? $currency);
        $this->addIfColumnExists($row, 'base_unit_cost', $data['base_unit_cost'] ?? $unitCost);
        $this->addIfColumnExists($row, 'base_total_cost', $data['base_total_cost'] ?? $totalCost);

        $this->addIfColumnExists($row, 'balance_after', $this->currentProductStockBalance(
            companyId: $companyId,
            branchId: $branchId,
            productId: $productId
        ));

        $this->addIfColumnExists($row, 'metadata', isset($data['metadata'])
            ? json_encode($data['metadata'])
            : null
        );

        DB::table('stock_movements')->insert($row);
    }

    public function recordSaleLine(array $data): void
    {
        $qty = round((float) ($data['quantity'] ?? 0), 4);

        $this->record([
            ...$data,
            'movement_type' => self::TYPE_SALE,
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'notes' => $data['notes'] ?? 'Sale stock deduction',
        ]);
    }

    public function recordSaleVoidLine(array $data): void
    {
        $qty = round((float) ($data['quantity'] ?? 0), 4);

        $this->record([
            ...$data,
            'movement_type' => self::TYPE_SALE_VOID,
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'notes' => $data['notes'] ?? 'Sale void stock reversal',
        ]);
    }

    public function recordReceiveLine(array $data): void
    {
        $qty = round((float) ($data['quantity'] ?? 0), 4);

        $this->record([
            ...$data,
            'movement_type' => $data['movement_type'] ?? self::TYPE_RECEIVE,
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'notes' => $data['notes'] ?? 'Stock received',
        ]);
    }

    public function recordAdjustment(array $data): void
    {
        $quantityIn = round((float) ($data['quantity_in'] ?? 0), 4);
        $quantityOut = round((float) ($data['quantity_out'] ?? 0), 4);

        $movementType = $quantityIn > 0
            ? self::TYPE_ADJUSTMENT_IN
            : self::TYPE_ADJUSTMENT_OUT;

        $this->record([
            ...$data,
            'movement_type' => $data['movement_type'] ?? $movementType,
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'notes' => $data['notes'] ?? 'Stock adjustment',
        ]);
    }

    public function recordBreakParentOut(array $data): void
    {
        $qty = round((float) ($data['quantity'] ?? 0), 4);

        $this->record([
            ...$data,
            'movement_type' => self::TYPE_BREAK_PARENT_OUT,
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'notes' => $data['notes'] ?? 'Parent product broken into child units',
        ]);
    }

    public function recordBreakChildIn(array $data): void
    {
        $qty = round((float) ($data['quantity'] ?? 0), 4);

        $this->record([
            ...$data,
            'movement_type' => self::TYPE_BREAK_CHILD_IN,
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'notes' => $data['notes'] ?? 'Child stock created from parent product',
        ]);
    }

    public function currentProductStockBalance(
        string $companyId,
        ?string $branchId,
        string $productId
    ): float {
        $query = ProductStock::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return round((float) $query->sum('quantity_on_hand'), 4);
    }

    private function resolveProductCost(
        string $companyId,
        ?string $branchId,
        string $productId
    ): array {
        if (!Schema::hasTable('product_costs')) {
            return [
                'unit_cost' => 0,
                'currency' => null,
            ];
        }

        $query = DB::table('product_costs')
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId && Schema::hasColumn('product_costs', 'branch_id')) {
            $query->where(function ($sub) use ($branchId) {
                $sub->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId]);
        }

        $cost = $query->first();

        if (!$cost) {
            return [
                'unit_cost' => 0,
                'currency' => null,
            ];
        }

        return [
            'unit_cost' => (float) (
                $cost->average_cost
                ?? $cost->last_cost
                ?? $cost->unit_cost
                ?? 0
            ),
            'currency' => $cost->currency
                ?? $cost->currency_code
                    ?? null,
        ];
    }

    private function addIfColumnExists(array &$row, string $column, mixed $value): void
    {
        if (Schema::hasColumn('stock_movements', $column)) {
            $row[$column] = $value;
        }
    }
}
