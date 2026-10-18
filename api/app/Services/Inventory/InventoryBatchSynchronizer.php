<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryBatchSynchronizer
{
    public function syncProduct(string $companyId, string $branchId, string $productId, ?string $createdBy = null): int
    {
        $product = DB::table('products')
            ->where('company_id', $companyId)
            ->where('id', $productId)
            ->first();

        if (!$product || !(bool) ($product->batch_tracking_enabled ?? false)) return 0;

        $created = 0;
        $stocks = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('quantity_on_hand', '>', 0)
            ->orderByRaw("CASE WHEN stock_area = 'front' THEN 0 ELSE 1 END")
            ->get();

        // Product stock is the branch-level ledger while batches may live in any
        // warehouse/location. Compare branch totals so a legitimate received or
        // transferred batch is never duplicated merely because it is not in the
        // default picking/storage location.
        $branchStock = (float) $stocks->sum('quantity_on_hand');
        $trackedStock = (float) DB::table('inventory_batches')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNotIn('status', ['destroyed'])
            ->sum('quantity_on_hand');
        $remainingMissing = round(max(0, $branchStock - $trackedStock), 4);
        if ($remainingMissing <= 0) return 0;

        foreach ($stocks as $stock) {
            if ($remainingMissing <= 0) break;
            $area = strtolower((string) ($stock->stock_area ?? 'back')) === 'front' ? 'front' : 'back';
            $locationType = $area === 'front' ? 'picking' : 'storage';
            $location = DB::table('inventory_locations as l')
                ->join('inventory_warehouses as w', 'w.id', '=', 'l.warehouse_id')
                ->where('l.company_id', $companyId)
                ->where('l.branch_id', $branchId)
                ->where('l.is_active', true)
                ->where('w.is_active', true)
                ->whereNull('l.deleted_at')
                ->whereNull('w.deleted_at')
                ->where('l.location_type', $locationType)
                ->select('l.id as location_id', 'w.id as warehouse_id')
                ->orderBy('l.pick_sequence')
                ->first();

            if (!$location) continue;

            $missing = round(min($remainingMissing, max(0, (float) $stock->quantity_on_hand)), 4);
            if ($missing <= 0) continue;

            $cost = DB::table('product_costs')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->first();
            $lot = 'SYSTEM-OPENING-' . strtoupper($area) . '-' . strtoupper(substr(str_replace('-', '', $productId), 0, 8));
            $existingBatch = DB::table('inventory_batches')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->where('lot_number', $lot)
                ->lockForUpdate()
                ->first();
            $batchId = $existingBatch?->id ?: (string) Str::uuid();
            $batchData = [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $location->warehouse_id,
                'location_id' => $location->location_id,
                'product_id' => $productId,
                'purchase_receipt_line_id' => null,
                'lot_number' => $lot,
                'manufactured_at' => null,
                'expires_at' => null,
                'quantity_received' => (float) ($existingBatch->quantity_received ?? 0) + $missing,
                'quantity_on_hand' => (float) ($existingBatch->quantity_on_hand ?? 0) + $missing,
                'reserved_quantity' => 0,
                'quarantined_quantity' => 0,
                'unit_cost' => (float) ($cost->last_cost ?? $cost->average_cost ?? 0),
                'base_unit_cost' => (float) ($cost->average_cost ?? $cost->last_cost ?? 0),
                'currency_code' => null,
                'exchange_rate' => 1,
                'status' => 'available',
                'quality_status' => 'released',
                'notes' => 'System opening batch created from existing branch stock when batch tracking was enabled.',
                'created_by' => $createdBy,
                'updated_at' => now(),
            ];
            if ($existingBatch) {
                DB::table('inventory_batches')->where('id', $batchId)->update($batchData);
            } else {
                DB::table('inventory_batches')->insert(array_merge($batchData, ['id' => $batchId, 'created_at' => now()]));
            }

            $unbatched = DB::table('inventory_location_balances')
                ->where('company_id', $companyId)
                ->where('location_id', $location->location_id)
                ->where('product_id', $productId)
                ->whereNull('batch_id')
                ->lockForUpdate()
                ->first();
            if ($unbatched) {
                $move = min($missing, max(0, (float) $unbatched->quantity_on_hand));
                DB::table('inventory_location_balances')->where('id', $unbatched->id)->update([
                    'quantity_on_hand' => max(0, (float) $unbatched->quantity_on_hand - $move),
                    'available_quantity' => max(0, (float) $unbatched->available_quantity - $move),
                    'updated_at' => now(),
                ]);
            }

            $batchBalance = DB::table('inventory_location_balances')
                ->where('company_id', $companyId)
                ->where('location_id', $location->location_id)
                ->where('product_id', $productId)
                ->where('batch_id', $batchId)
                ->lockForUpdate()
                ->first();
            if ($batchBalance) {
                DB::table('inventory_location_balances')->where('id', $batchBalance->id)->update([
                    'quantity_on_hand' => (float) $batchBalance->quantity_on_hand + $missing,
                    'available_quantity' => (float) $batchBalance->available_quantity + $missing,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('inventory_location_balances')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
                    'warehouse_id' => $location->warehouse_id, 'location_id' => $location->location_id,
                    'product_id' => $productId, 'batch_id' => $batchId, 'quantity_on_hand' => $missing,
                    'reserved_quantity' => 0, 'available_quantity' => $missing, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $created++;
            $remainingMissing = round($remainingMissing - $missing, 4);
        }

        return $created;
    }

    /**
     * @param  array<int, string>  $branchIds
     */
    public function syncCompany(string $companyId, ?string $createdBy = null, array $branchIds = []): int
    {
        $created = 0;
        $rows = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.company_id', $companyId)
            ->when($branchIds !== [], fn ($query) => $query->whereIn('ps.branch_id', $branchIds))
            ->where('p.batch_tracking_enabled', true)
            ->where('ps.quantity_on_hand', '>', 0)
            ->select('ps.branch_id', 'ps.product_id')
            ->distinct()
            ->get();
        foreach ($rows as $row) {
            $created += $this->syncProduct($companyId, $row->branch_id, $row->product_id, $createdBy);
        }
        return $created;
    }
}
