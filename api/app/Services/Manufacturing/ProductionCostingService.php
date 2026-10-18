<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Collection;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;

class ProductionCostingService
{
    public const DIRECT_COST_TYPES = ['labour', 'direct_labour'];

    public const INDIRECT_COST_TYPES = [
        'salary',
        'electricity',
        'fuel',
        'water',
        'rent',
        'machine_maintenance',
        'depreciation',
        'other',
    ];

    public function plannedUnitCost(float $material, float $labour, float $overhead, float $quantity): float
    {
        return $quantity > 0 ? round(($material + $labour + $overhead) / $quantity, 6) : 0;
    }

    public function actualUnitCost(float $material, float $labour, float $overhead, float $goodQuantity): float
    {
        return $goodQuantity > 0 ? round(($material + $labour + $overhead) / $goodQuantity, 6) : 0;
    }

    public function classifyCostType(?string $costType): string
    {
        return match ($costType) {
            'labour', 'direct_labour' => 'direct_labour',
            'salary' => 'indirect_labour',
            'electricity', 'fuel', 'water', 'rent', 'machine_maintenance', 'depreciation' => 'factory_overhead',
            default => 'other_indirect',
        };
    }

    public function isDirectCost(?string $costType, ?string $costClass = null): bool
    {
        return in_array($costType, self::DIRECT_COST_TYPES, true)
            || in_array($costClass, ['direct_labour', 'direct_material'], true);
    }

    public function recalculateActualCosts(ProductionOrder $order): array
    {
        $rawMaterialCost = (float) $order->inputs()->sum('total_cost');

        $labourCost = (float) $order->overheads()
            ->where(function ($query) {
                $query->whereIn('cost_type', self::DIRECT_COST_TYPES)
                    ->orWhereIn('cost_class', ['direct_labour']);
            })
            ->sum('amount');

        $overheadCost = (float) $order->overheads()
            ->where(function ($query) {
                $query->whereNotIn('cost_type', self::DIRECT_COST_TYPES)
                    ->whereNotIn('cost_class', ['direct_labour']);
            })
            ->sum('amount');

        $totalCost = $rawMaterialCost + $labourCost + $overheadCost;
        $unitCost = $this->actualUnitCost(
            $rawMaterialCost,
            $labourCost,
            $overheadCost,
            (float) $order->actual_good_quantity
        );

        return compact('rawMaterialCost', 'labourCost', 'overheadCost', 'totalCost', 'unitCost');
    }

    public function summarizeOrders(Collection $orders): array
    {
        $plannedQuantity = (float) $orders->sum('planned_output_quantity');
        $goodQuantity = (float) $orders->sum('actual_good_quantity');
        $rejectQuantity = (float) $orders->sum('actual_reject_quantity');
        $wasteQuantity = (float) $orders->sum('actual_waste_quantity');
        $plannedCost = (float) $orders->sum('planned_total_cost');
        $materialCost = (float) $orders->sum('actual_raw_material_cost');
        $labourCost = (float) $orders->sum('actual_labour_cost');
        $overheadCost = (float) $orders->sum('actual_overhead_cost');
        $actualCost = (float) $orders->sum('actual_total_cost');

        return [
            'orders' => $orders->count(),
            'completed_orders' => $orders->where('status', 'completed')->count(),
            'open_orders' => $orders->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'planned_output_quantity' => $plannedQuantity,
            'actual_good_quantity' => $goodQuantity,
            'actual_reject_quantity' => $rejectQuantity,
            'actual_waste_quantity' => $wasteQuantity,
            'yield_percent' => $plannedQuantity > 0 ? round(($goodQuantity / $plannedQuantity) * 100, 2) : 0,
            'loss_percent' => $plannedQuantity > 0 ? round((($rejectQuantity + $wasteQuantity) / $plannedQuantity) * 100, 2) : 0,
            'planned_total_cost' => round($plannedCost, 6),
            'direct_material_cost' => round($materialCost, 6),
            'direct_labour_cost' => round($labourCost, 6),
            'indirect_overhead_cost' => round($overheadCost, 6),
            'actual_total_cost' => round($actualCost, 6),
            'average_cost_per_unit' => $goodQuantity > 0 ? round($actualCost / $goodQuantity, 6) : 0,
            'cost_variance_amount' => round($actualCost - $plannedCost, 6),
        ];
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
