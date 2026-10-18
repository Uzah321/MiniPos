<?php

namespace App\Services\Manufacturing;

use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;

class ProductionCostingService
{
    public function plannedUnitCost(float $material, float $labour, float $overhead, float $quantity): float
    {
        return $quantity > 0 ? round(($material + $labour + $overhead) / $quantity, 6) : 0;
    }

    public function actualUnitCost(float $material, float $labour, float $overhead, float $goodQuantity): float
    {
        return $goodQuantity > 0 ? round(($material + $labour + $overhead) / $goodQuantity, 6) : 0;
    }

    public function recalculateActualCosts(ProductionOrder $order): array
    {
        $rawMaterialCost = (float) $order->inputs()->sum('total_cost');
        $labourCost = (float) $order->overheads()->whereIn('cost_type', ['labour', 'salary'])->sum('amount');
        $overheadCost = (float) $order->overheads()->whereNotIn('cost_type', ['labour', 'salary'])->sum('amount');
        $totalCost = $rawMaterialCost + $labourCost + $overheadCost;
        $unitCost = $this->actualUnitCost($rawMaterialCost, $labourCost, $overheadCost, (float) $order->actual_good_quantity);

        return compact('rawMaterialCost', 'labourCost', 'overheadCost', 'totalCost', 'unitCost');
    }

    public function addAverageCost(Product $product, string $branchId, float $quantity, float $unitCost, string $currency): ProductCost
    {
        $cost = ProductCost::firstOrCreate(
            [
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
            ],
            [
                'average_cost' => 0,
                'last_cost' => 0,
                'total_quantity' => 0,
                'total_value' => 0,
                'currency' => $currency,
            ]
        );

        $oldQuantity = (float) $cost->total_quantity;
        $oldValue = (float) $cost->total_value;
        $newValue = $quantity * $unitCost;
        $newQuantity = $oldQuantity + $quantity;
        $newTotalValue = $oldValue + $newValue;

        $cost->average_cost = $newQuantity > 0 ? $newTotalValue / $newQuantity : 0;
        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newQuantity;
        $cost->total_value = $newTotalValue;
        $cost->currency = $currency;
        $cost->save();

        return $cost;
    }
}
