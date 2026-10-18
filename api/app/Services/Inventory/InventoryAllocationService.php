<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryAllocationService
{
    public function allocateSale(array $context): bool
    {
        $product = DB::table('products')
            ->where('company_id', $context['company_id'])
            ->where('id', $context['product_id'])
            ->first();

        if (! $product || ! (bool) ($product->batch_tracking_enabled ?? false)) {
            return false;
        }

        app(InventoryBatchSynchronizer::class)->syncProduct(
            $context['company_id'],
            $context['branch_id'],
            $context['product_id'],
            $context['user_id'] ?? auth()->id()
        );

        $remaining = round((float) $context['quantity'], 4);
        $batches = DB::table('inventory_batches')
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('product_id', $context['product_id'])
            ->where('status', 'available')
            ->where('quality_status', 'released')
            ->where('quantity_on_hand', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today());
            })
            ->orderByRaw('expires_at IS NULL, expires_at ASC, created_at ASC')
            ->lockForUpdate()
            ->get();

        $available = $batches->sum(fn ($batch) => max(0, (float) $batch->quantity_on_hand - (float) $batch->reserved_quantity - (float) $batch->quarantined_quantity));
        if ($available + 0.00001 < $remaining) {
            throw new \RuntimeException("Insufficient released, unexpired batch stock for {$product->name}. Required: {$remaining}, available: ".round($available, 4).'.');
        }

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $batchAvailable = max(0, (float) $batch->quantity_on_hand - (float) $batch->reserved_quantity - (float) $batch->quarantined_quantity);
            $allocated = min($remaining, $batchAvailable);
            if ($allocated <= 0) {
                continue;
            }

            DB::table('inventory_batches')->where('id', $batch->id)->update([
                'quantity_on_hand' => (float) $batch->quantity_on_hand - $allocated,
                'status' => ((float) $batch->quantity_on_hand - $allocated) <= 0 ? 'depleted' : 'available',
                'updated_at' => now(),
            ]);

            $this->changeLocationBalance($batch, -$allocated);
            $this->movement($context, $batch, $allocated, false);
            $remaining = round($remaining - $allocated, 4);
        }

        return true;
    }

    public function reverseSale(array $context): bool
    {
        $originalReferenceTypes = $context['original_reference_types']
            ?? [$context['original_reference_type'] ?? 'sale'];

        $movements = DB::table('stock_movements')
            ->where('company_id', $context['company_id'])
            ->whereIn('reference_type', (array) $originalReferenceTypes)
            ->where('reference_id', $context['original_sale_id'])
            ->where('product_id', $context['product_id'])
            ->whereNotNull('batch_id')
            ->where('quantity_out', '>', 0)
            ->when(! empty($context['receipt_line_id']), fn ($query) => $query->where('metadata->receipt_line_id', $context['receipt_line_id']))
            ->get();

        if ($movements->isEmpty()) {
            return false;
        }

        foreach ($movements as $movement) {
            $batch = DB::table('inventory_batches')->where('company_id', $context['company_id'])->where('id', $movement->batch_id)->lockForUpdate()->first();
            if (! $batch) {
                throw new \RuntimeException('The original sale batch no longer exists; cancellation cannot safely restore stock.');
            }
            $quantity = (float) $movement->quantity_out;
            DB::table('inventory_batches')->where('id', $batch->id)->update([
                'quantity_on_hand' => (float) $batch->quantity_on_hand + $quantity,
                'status' => $batch->quality_status === 'released' ? 'available' : $batch->status,
                'updated_at' => now(),
            ]);
            $this->changeLocationBalance($batch, $quantity);
            $this->movement(array_merge($context, [
                'sale_id' => $context['cancel_sale_id'],
                'unit_cost' => $movement->unit_cost,
                'currency' => $movement->currency_code ?? $movement->currency,
            ]), $batch, $quantity, true);
        }

        return true;
    }

    private function changeLocationBalance(object $batch, float $delta): void
    {
        if (! $batch->location_id) {
            return;
        }
        $query = DB::table('inventory_location_balances')
            ->where('company_id', $batch->company_id)
            ->where('location_id', $batch->location_id)
            ->where('product_id', $batch->product_id)
            ->where('batch_id', $batch->id);
        $balance = $query->lockForUpdate()->first();
        if (! $balance) {
            if ($delta < 0) {
                throw new \RuntimeException('Batch location balance is missing; stock allocation stopped to prevent an untraceable sale.');
            }
            DB::table('inventory_location_balances')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $batch->company_id, 'branch_id' => $batch->branch_id,
                'warehouse_id' => $batch->warehouse_id, 'location_id' => $batch->location_id, 'product_id' => $batch->product_id,
                'batch_id' => $batch->id, 'quantity_on_hand' => $delta, 'reserved_quantity' => 0, 'available_quantity' => $delta,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return;
        }
        if ($delta < 0 && (float) $balance->available_quantity + 0.00001 < abs($delta)) {
            throw new \RuntimeException('Batch location balance is insufficient; stock allocation stopped to prevent an untraceable sale.');
        }
        $query->update([
            'quantity_on_hand' => (float) $balance->quantity_on_hand + $delta,
            'available_quantity' => (float) $balance->available_quantity + $delta,
            'updated_at' => now(),
        ]);
    }

    private function movement(array $context, object $batch, float $quantity, bool $reversal): void
    {
        $unitCost = (float) ($context['unit_cost'] ?? $batch->base_unit_cost ?? $batch->unit_cost ?? 0);
        $referenceType = $reversal
            ? ($context['reversal_reference_type'] ?? 'sale_cancel')
            : ($context['reference_type'] ?? 'sale');
        $metadata = array_merge([
            'allocation_method' => 'FEFO',
            'lot_number' => $batch->lot_number,
            'original_sale_id' => $context['original_sale_id'] ?? null,
            'receipt_line_id' => $context['receipt_line_id'] ?? null,
        ], is_array($context['movement_metadata'] ?? null) ? $context['movement_metadata'] : []);

        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company_id'], 'branch_id' => $context['branch_id'],
            'warehouse_id' => $batch->warehouse_id, 'location_id' => $batch->location_id, 'batch_id' => $batch->id,
            'till_id' => $context['till_id'] ?? null, 'shift_session_id' => $context['shift_session_id'] ?? null,
            'product_id' => $context['product_id'], 'movement_type' => $reversal ? 'sale_cancel' : 'sale',
            'stock_area' => $context['stock_area'] ?? 'front',
            'quantity_in' => $reversal ? $quantity : 0, 'quantity_out' => $reversal ? 0 : $quantity,
            'balance_after' => $context['balance_after'] ?? null, 'unit_cost' => $unitCost, 'total_cost' => $unitCost * $quantity,
            'currency' => $context['currency'] ?? $context['currency_code'] ?? $batch->currency_code,
            'currency_code' => $context['currency_code'] ?? $context['currency'] ?? $batch->currency_code,
            'reference_type' => $referenceType, 'reference_id' => $context['sale_id'],
            'notes' => $context['movement_notes'] ?? ($reversal ? 'Exact batch restoration for cancelled sale.' : 'FEFO batch allocation for sale.'),
            'metadata' => json_encode($metadata),
            'created_by' => $context['user_id'] ?? auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
