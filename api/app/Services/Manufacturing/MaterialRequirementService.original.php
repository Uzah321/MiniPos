<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MaterialRequirement;
use Modules\Manufacturing\Models\ProductionPlan;
use Modules\Product\Models\ProductStock;

class MaterialRequirementService
{
    public function calculateForPlan(ProductionPlan $plan): array
    {
        return DB::transaction(function () use ($plan) {
            MaterialRequirement::where('production_plan_id', $plan->id)->delete();

            $requirements = [];

            foreach ($plan->items()->with('recipe.items.product')->get() as $planItem) {
                $recipe = $planItem->recipe;

                if (!$recipe) {
                    continue;
                }

                $plannedQuantity = (float) $planItem->planned_quantity;
                $batchOutput = (float) $recipe->batch_output_quantity;

                if ($plannedQuantity <= 0 || $batchOutput <= 0) {
                    continue;
                }

                $batchMultiplier = $plannedQuantity / $batchOutput;

                foreach ($recipe->items as $recipeItem) {
                    $baseQty = (float) $recipeItem->quantity_required;
                    $wastagePercent = (float) $recipeItem->wastage_percent;

                    $required = $baseQty * $batchMultiplier;
                    $required += $required * ($wastagePercent / 100);

                    $available = (float) ProductStock::where('company_id', $plan->company_id)
                        ->where('branch_id', $plan->branch_id)
                        ->where('product_id', $recipeItem->product_id)
                        ->value('available_quantity');

                    $existing = MaterialRequirement::where('company_id', $plan->company_id)
                        ->where('branch_id', $plan->branch_id)
                        ->where('production_plan_id', $plan->id)
                        ->where('product_id', $recipeItem->product_id)
                        ->first();

                    if ($existing) {
                        $existing->required_quantity = (float) $existing->required_quantity + $required;
                        $existing->available_quantity = $available;
                        $existing->reserved_quantity = min((float) $existing->required_quantity, $available);
                        $existing->shortage_quantity = max((float) $existing->required_quantity - $available, 0);
                        $existing->status = $existing->shortage_quantity > 0 ? 'purchase_required' : 'available';
                        $existing->metadata = array_merge($existing->metadata ?? [], [
                            'source' => 'production_plan',
                            'calculation_basis' => 'recipe quantity x planned quantity / recipe batch output + wastage',
                        ]);
                        $existing->save();

                        $requirements[$existing->id] = $existing;
                        continue;
                    }

                    $shortage = max($required - $available, 0);
                    $reserved = min($required, $available);

                    $requirement = MaterialRequirement::create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $plan->company_id,
                        'branch_id' => $plan->branch_id,
                        'production_plan_id' => $plan->id,
                        'product_id' => $recipeItem->product_id,
                        'required_quantity' => $required,
                        'available_quantity' => $available,
                        'reserved_quantity' => $reserved,
                        'shortage_quantity' => $shortage,
                        'status' => $shortage > 0 ? 'purchase_required' : 'available',
                        'metadata' => [
                            'recipe_item_id' => $recipeItem->id,
                            'recipe_product_name' => $recipeItem->product?->name,
                            'base_quantity_per_batch' => $baseQty,
                            'planned_quantity' => $plannedQuantity,
                            'recipe_batch_output_quantity' => $batchOutput,
                            'batch_multiplier' => $batchMultiplier,
                            'wastage_percent' => $wastagePercent,
                            'source' => 'production_plan',
                            'calculation_basis' => 'recipe quantity x planned quantity / recipe batch output + wastage',
                        ],
                    ]);

                    $requirements[$requirement->id] = $requirement;
                }
            }

            return array_values($requirements);
        });
    }
}
