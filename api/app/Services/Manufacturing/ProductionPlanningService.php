<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderInput;
use Modules\Manufacturing\Models\ProductionPlan;
use Modules\Manufacturing\Models\ProductionPlanItem;
use Modules\Manufacturing\Models\ProductionRecipe;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;

class ProductionPlanningService
{
    public function requiredProduction(float $forecast, float $currentStock, float $safetyStock = 0): float
    {
        return max(($forecast + $safetyStock) - $currentStock, 0);
    }

    public function batches(float $requiredProduction, float $batchOutput): int
    {
        if ($batchOutput <= 0) {
            throw new \Exception('Batch output quantity must be greater than zero.');
        }

        return (int) ceil($requiredProduction / $batchOutput);
    }

    public function createPlanItemFromRequest(ProductionPlan $plan, array $item): ProductionPlanItem
    {
        $recipe = ProductionRecipe::where('company_id', $plan->company_id)
            ->where('id', $item['production_recipe_id'])
            ->firstOrFail();

        $currentStock = (float) ProductStock::where('company_id', $plan->company_id)
            ->where('branch_id', $plan->branch_id)
            ->where('product_id', $item['finished_product_id'])
            ->value('available_quantity');

        $forecast = (float) ($item['forecast_quantity'] ?? 0);
        $safety = (float) ($item['safety_stock_quantity'] ?? 0);

        $plannedQuantity = isset($item['planned_quantity'])
            ? (float) $item['planned_quantity']
            : $this->requiredProduction($forecast, $currentStock, $safety);

        if ($plannedQuantity <= 0) {
            throw new \Exception('Planned quantity must be greater than zero.');
        }

        $batchSize = (float) $recipe->batch_output_quantity;

        if ($batchSize <= 0) {
            throw new \Exception('Recipe batch output quantity must be greater than zero.');
        }

        $plannedBatches = $this->batches($plannedQuantity, $batchSize);

        /*
        |--------------------------------------------------------------------------
        | Important:
        |--------------------------------------------------------------------------
        | We keep the user's planned quantity as the production quantity.
        | planned_batches is informational for costing/material calculations.
        | We do NOT inflate 100 units into 100 separate orders.
        */
        $batchMultiplier = $plannedQuantity / $batchSize;

        $plannedMaterialCost = $this->estimateMaterialCostForQuantity(
            $recipe,
            $batchMultiplier,
            $plan->branch_id
        );

        $plannedLabourCost = (float) ($item['planned_labour_cost'] ?? 0);
        $plannedOverheadCost = (float) ($item['planned_overhead_cost'] ?? 0);

        $plannedTotalCost = $plannedMaterialCost + $plannedLabourCost + $plannedOverheadCost;
        $plannedUnitCost = $plannedQuantity > 0 ? round($plannedTotalCost / $plannedQuantity, 6) : 0;

        $currency = $this->resolveCurrency(
            $plan->company_id,
            $plan->branch_id,
            $item['finished_product_id']
        );

        return ProductionPlanItem::create([
            'id' => (string) Str::uuid(),
            'company_id' => $plan->company_id,
            'branch_id' => $plan->branch_id,
            'production_plan_id' => $plan->id,
            'finished_product_id' => $item['finished_product_id'],
            'production_recipe_id' => $recipe->id,
            'forecast_quantity' => $forecast,
            'current_stock_quantity' => $currentStock,
            'safety_stock_quantity' => $safety,
            'planned_quantity' => $plannedQuantity,
            'batch_size' => $batchSize,
            'planned_batches' => $plannedBatches,
            'planned_unit_cost' => $plannedUnitCost,
            'planned_total_cost' => $plannedTotalCost,
            'required_by' => $item['required_by'] ?? null,
            'metadata' => [
                'currency' => $currency,
                'batch_multiplier' => $batchMultiplier,
                'planned_material_cost' => $plannedMaterialCost,
                'planned_labour_cost' => $plannedLabourCost,
                'planned_overhead_cost' => $plannedOverheadCost,
                'costing_note' => 'Planned quantity remains user-entered quantity. Batch multiplier is used only for material/cost calculation.',
            ],
        ]);
    }

    public function estimateMaterialCost(ProductionRecipe $recipe, int $batches, string $branchId): float
    {
        return $this->estimateMaterialCostForQuantity($recipe, (float) $batches, $branchId);
    }

    public function estimateMaterialCostForQuantity(
        ProductionRecipe $recipe,
        float $batchMultiplier,
        string $branchId
    ): float {
        $total = 0;

        foreach ($recipe->items()->where('is_active', true)->get() as $item) {
            $cost = ProductCost::where('company_id', $recipe->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $item->product_id)
                ->first();

            $qty = (float) $item->quantity_required * $batchMultiplier;
            $qty += $qty * ((float) $item->wastage_percent / 100);

            $total += $qty * (float) ($cost?->average_cost ?? 0);
        }

        return round($total, 4);
    }

    public function convertPlanToProductionOrders(ProductionPlan $plan): array
    {
        return DB::transaction(function () use ($plan) {
            $orders = [];

            foreach ($plan->items()->with('recipe.items')->get() as $planItem) {
                $recipe = $planItem->recipe;

                if (!$recipe) {
                    throw new \Exception('Production recipe not found for plan item.');
                }

                $plannedQuantity = (float) $planItem->planned_quantity;
                $batchSize = (float) $planItem->batch_size;

                if ($plannedQuantity <= 0) {
                    throw new \Exception('Planned quantity must be greater than zero.');
                }

                if ($batchSize <= 0) {
                    throw new \Exception('Batch size must be greater than zero.');
                }

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT FIX
                |--------------------------------------------------------------------------
                | We now create ONE production order per plan item.
                | Example:
                | Plan says produce 100 bricks.
                | Backend creates ONE order with planned_output_quantity = 100.
                |
                | It must NOT create 100 separate orders.
                |--------------------------------------------------------------------------
                */

                $plannedBatches = (int) max(1, (int) $planItem->planned_batches);

                $plannedMaterialCost = (float) data_get(
                    $planItem->metadata,
                    'planned_material_cost',
                    0
                );

                $plannedLabourCost = (float) data_get(
                    $planItem->metadata,
                    'planned_labour_cost',
                    0
                );

                $plannedOverheadCost = (float) data_get(
                    $planItem->metadata,
                    'planned_overhead_cost',
                    0
                );

                $plannedTotalCost =
                    $plannedMaterialCost +
                    $plannedLabourCost +
                    $plannedOverheadCost;

                if ($plannedTotalCost <= 0) {
                    $plannedTotalCost = (float) $planItem->planned_total_cost;
                }

                $plannedUnitCost = $plannedQuantity > 0
                    ? round($plannedTotalCost / $plannedQuantity, 6)
                    : 0;

                $order = ProductionOrder::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $plan->company_id,
                    'branch_id' => $plan->branch_id,

                    'production_plan_id' => $plan->id,
                    'production_plan_item_id' => $planItem->id,
                    'production_recipe_id' => $planItem->production_recipe_id,
                    'finished_product_id' => $planItem->finished_product_id,

                    'production_number' => $this->generateProductionNumber(
                        $plan->company_id,
                        $plan->branch_id
                    ),

                    'status' => 'draft',

                    /*
                    |--------------------------------------------------------------------------
                    | FIXED
                    |--------------------------------------------------------------------------
                    | batch_number is integer, so do NOT put UUID here.
                    |--------------------------------------------------------------------------
                    */
                    'batch_number' => 1,

                    /*
                    |--------------------------------------------------------------------------
                    | This order represents the whole planned quantity.
                    |--------------------------------------------------------------------------
                    */
                    'batch_size' => $plannedQuantity,
                    'planned_output_quantity' => $plannedQuantity,

                    'planned_raw_material_cost' => $plannedMaterialCost,
                    'planned_labour_cost' => $plannedLabourCost,
                    'planned_overhead_cost' => $plannedOverheadCost,
                    'planned_total_cost' => $plannedTotalCost,
                    'planned_unit_cost' => $plannedUnitCost,

                    'currency' => data_get($planItem->metadata, 'currency', 'USD'),

                    'planned_start_date' => $plan->period_start,
                    'planned_end_date' => $plan->period_end,

                    'created_by' => auth()->id(),

                    'metadata' => [
                        'source' => 'production_plan',
                        'plan_number' => $plan->plan_number,
                        'plan_name' => $plan->name,
                        'plan_item_id' => $planItem->id,
                        'planned_batches' => $plannedBatches,
                        'batch_multiplier' => $plannedQuantity / max((float) $recipe->batch_output_quantity, 1),
                        'recipe_batch_output_quantity' => (float) $recipe->batch_output_quantity,
                        'planned_quantity' => $plannedQuantity,
                        'planned_material_cost' => $plannedMaterialCost,
                        'planned_labour_cost' => $plannedLabourCost,
                        'planned_overhead_cost' => $plannedOverheadCost,
                        'planned_unit_cost' => $plannedUnitCost,
                    ],
                ]);

                /*
                |--------------------------------------------------------------------------
                | Convert BOM into order inputs
                |--------------------------------------------------------------------------
                | If BOM is for 1 brick and plan is for 100 bricks:
                | material quantity = BOM qty x 100
                |--------------------------------------------------------------------------
                */

                $recipeBatchOutput = (float) $recipe->batch_output_quantity;
                $multiplier = $recipeBatchOutput > 0
                    ? $plannedQuantity / $recipeBatchOutput
                    : 1;

                foreach ($recipe->items as $recipeItem) {
                    $baseQuantity = (float) $recipeItem->quantity_required;
                    $wastagePercent = (float) $recipeItem->wastage_percent;

                    $plannedInputQuantity = $baseQuantity * $multiplier;
                    $plannedInputQuantity += $plannedInputQuantity * ($wastagePercent / 100);

                    ProductionOrderInput::create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $plan->company_id,
                        'branch_id' => $plan->branch_id,
                        'production_order_id' => $order->id,
                        'product_id' => $recipeItem->product_id,
                        'uom_id' => $recipeItem->uom_id,
                        'planned_quantity' => $plannedInputQuantity,
                        'currency' => $order->currency,
                        'metadata' => [
                            'source' => 'production_plan',
                            'recipe_item_id' => $recipeItem->id,
                            'base_quantity_per_batch' => $baseQuantity,
                            'planned_output_quantity' => $plannedQuantity,
                            'recipe_batch_output_quantity' => $recipeBatchOutput,
                            'multiplier' => $multiplier,
                            'wastage_percent' => $wastagePercent,
                        ],
                    ]);
                }

                $orders[] = $order->fresh(['finishedProduct', 'inputs.product', 'overheads']);
            }

            $plan->update([
                'status' => 'converted_to_orders',
            ]);

            return $orders;
        });
    }

    public function generateProductionNumber(string $companyId, string $branchId): string
    {
        $date = now()->format('ymd');

        $count = ProductionOrder::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', now()->toDateString())
                ->count() + 1;

        do {
            $number = 'PROD-' . $date . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
            $exists = ProductionOrder::where('production_number', $number)->exists();
            $count++;
        } while ($exists);

        return $number;
    }

    private function resolveCurrency(string $companyId, string $branchId, ?string $productId = null): string
    {
        /*
        |--------------------------------------------------------------------------
        | 1. First try product cost currency
        |--------------------------------------------------------------------------
        | If the finished product already has a costing currency for this branch,
        | use that. This is the most accurate source.
        |--------------------------------------------------------------------------
        */

        if ($productId) {
            $currency = ProductCost::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->value('currency');

            if ($currency) {
                return strtoupper($currency);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Then use company / branch base currency
        |--------------------------------------------------------------------------
        | Your currencies table has is_base_currency, not is_base or is_default.
        |--------------------------------------------------------------------------
        */

        $currency = DB::table('currencies')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_base_currency', true)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
            ->value('code');

        /*
        |--------------------------------------------------------------------------
        | 3. Fallback to any active company currency
        |--------------------------------------------------------------------------
        */

        if (!$currency) {
            $currency = DB::table('currencies')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                })
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
                ->value('code');
        }

        return strtoupper($currency ?: 'USD');
    }}
