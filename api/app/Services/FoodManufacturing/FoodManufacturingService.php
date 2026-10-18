<?php
namespace App\Services\FoodManufacturing;

use App\Support\FiltersTableColumns;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\StockMovement;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\FoodManufacturing\Models\FoodManufacturingBatch;
use Modules\FoodManufacturing\Models\FoodManufacturingBatchLine;

class FoodManufacturingService
{
    use FiltersTableColumns;

    public function calculateRecipeCost(string $companyId, string $branchId, FoodRecipe $recipe): array
    {
        $recipe->loadMissing($this->recipeRelations([
            'ingredients.ingredientProduct.uom',
            'menuProduct',
            'producedProduct',
        ], true));

        $currency = $recipe->currency ?: 'USD';
        $totalIngredientCost = 0.0;
        $totalPackagingComponentCost = 0.0;
        $totalWastageCost = 0.0;
        $lines = [];

        foreach ($recipe->ingredients as $ingredient) {
            $cost = $this->getProductCost($companyId, $branchId, $ingredient->ingredient_product_id);
            $unitCost = (float) ($cost?->average_cost ?? $cost?->last_cost ?? 0);
            $lineCurrency = $cost?->currency ?: $currency;

            if (!$recipe->currency && $lineCurrency) {
                $currency = $lineCurrency;
            }

            $netQty = (float) $ingredient->quantity;
            $wastagePercent = (float) ($ingredient->wastage_percent ?? 0);
            $wastageQty = $netQty * ($wastagePercent / 100);
            $grossQty = $netQty + $wastageQty;

            $netCost = $netQty * $unitCost;
            $wastageCost = $wastageQty * $unitCost;
            $lineCost = $grossQty * $unitCost;
            $lineType = $this->safeLineType($ingredient->line_type, $ingredient->ingredientProduct?->product_type);

            if ($lineType === 'packaging') {
                $totalPackagingComponentCost += $lineCost;
            } else {
                $totalIngredientCost += $lineCost;
            }
            $totalWastageCost += $wastageCost;

            $lines[] = [
                'ingredient_product_id' => $ingredient->ingredient_product_id,
                'ingredient_name' => $ingredient->ingredientProduct?->name,
                'line_type' => $lineType,
                'uom_id' => $ingredient->uom_id ?: $ingredient->ingredientProduct?->base_uom_id,
                'uom' => $ingredient->uom?->code ?: $ingredient->uom?->name ?: $ingredient->ingredientProduct?->uom?->code ?: $ingredient->ingredientProduct?->uom?->name,
                'net_quantity' => round($netQty, 6),
                'wastage_percent' => round($wastagePercent, 4),
                'wastage_quantity' => round($wastageQty, 6),
                'gross_quantity' => round($grossQty, 6),
                'unit_cost' => round($unitCost, 6),
                'net_cost' => round($netCost, 6),
                'wastage_cost' => round($wastageCost, 6),
                'line_cost' => round($lineCost, 6),
                'currency' => $lineCurrency,
            ];
        }

        $packagingOverheadCost = (float) ($recipe->packaging_cost ?? 0);
        $packagingCost = $totalPackagingComponentCost + $packagingOverheadCost;
        $labourCost = (float) ($recipe->labour_cost ?? 0);
        $overheadCost = (float) ($recipe->overhead_cost ?? 0);
        $totalCost = $totalIngredientCost + $packagingCost + $labourCost + $overheadCost;
        $yieldQuantity = max((float) ($recipe->yield_quantity ?: 1), 0.000001);

        return [
            'currency' => $currency,
            'yield_quantity' => round($yieldQuantity, 6),
            'ingredient_cost' => round($totalIngredientCost, 6),
            'wastage_cost' => round($totalWastageCost, 6),
            'packaging_cost' => round($packagingCost, 6),
            'packaging_component_cost' => round($totalPackagingComponentCost, 6),
            'packaging_overhead_cost' => round($packagingOverheadCost, 6),
            'labour_cost' => round($labourCost, 6),
            'overhead_cost' => round($overheadCost, 6),
            'total_cost' => round($totalCost, 6),
            'cost_per_unit' => round($totalCost / $yieldQuantity, 6),
            'lines' => $lines,
        ];
    }

    public function saveCostSnapshot(string $companyId, string $branchId, FoodRecipe $recipe): FoodRecipe
    {
        $cost = $this->calculateRecipeCost($companyId, $branchId, $recipe);

        $recipe->currency = $cost['currency'];
        $recipe->ingredient_cost = $cost['ingredient_cost'];
        $recipe->wastage_cost = $cost['wastage_cost'];
        $recipe->total_cost = $cost['total_cost'];
        $recipe->cost_per_unit = $cost['cost_per_unit'];
        $recipe->last_costed_at = now();
        $recipe->save();

        return $recipe->fresh($this->recipeRelations([
            'ingredients.ingredientProduct.uom',
            'menuProduct',
            'producedProduct',
        ], true));
    }

    public function recordManufacturingBatch(
        string $companyId,
        string $branchId,
        string $recipeId,
        float $actualYieldQuantity,
        ?string $notes = null,
        array $options = []
    ): FoodManufacturingBatch {
        return DB::transaction(function () use ($companyId, $branchId, $recipeId, $actualYieldQuantity, $notes, $options) {
            $recipe = FoodRecipe::where('company_id', $companyId)
                ->where('id', $recipeId)
                ->with($this->recipeRelations([
                    'ingredients.ingredientProduct',
                    'menuProduct',
                    'producedProduct',
                ]))
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($recipe->recipe_type, ['manufacturing', 'prep'], true)) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Only manufacturing/prep recipes can be recorded as production batches.',
                ]);
            }

            $expectedRecipeVersion = $options['expected_recipe_version_no'] ?? null;
            if ($expectedRecipeVersion !== null
                && (int) $expectedRecipeVersion !== (int) $recipe->version_no) {
                throw ValidationException::withMessages([
                    'plan_id' => 'This production plan was calculated from an older BOM version. Recalculate the plan before recording production.',
                ]);
            }

            if ($actualYieldQuantity <= 0) {
                throw ValidationException::withMessages([
                    'actual_yield_quantity' => 'Actual quantity produced must be greater than zero.',
                ]);
            }

            $producedProductId = $options['produced_product_id']
                ?? $recipe->produced_product_id
                ?? $recipe->menu_product_id
                ?? null;

            $configuredOutputProductId = $recipe->produced_product_id ?: $recipe->menu_product_id;
            if ($configuredOutputProductId && $producedProductId && (string) $configuredOutputProductId !== (string) $producedProductId) {
                throw ValidationException::withMessages([
                    'produced_product_id' => 'The selected output product does not match the versioned BOM output.',
                ]);
            }

            if (!$producedProductId) {
                throw ValidationException::withMessages([
                    'produced_product_id' => 'Select the stock item or semi-finished good to produce.',
                ]);
            }

            $producedProduct = Product::where('company_id', $companyId)
                ->where('id', $producedProductId)
                ->firstOrFail();

            $sourceStockArea = $this->safeStockArea($options['source_stock_area'] ?? $recipe->source_stock_area ?? 'back', 'back');
            $destinationStockArea = $this->safeStockArea($options['destination_stock_area'] ?? $recipe->destination_stock_area ?? 'back', 'back');
            $plannedYieldQuantity = (float) ($options['planned_yield_quantity'] ?? $actualYieldQuantity);

            $baseCost = $this->calculateRecipeCost($companyId, $branchId, $recipe);
            $baseYield = max((float) ($recipe->yield_quantity ?: 1), 0.000001);
            $multiplier = $actualYieldQuantity / $baseYield;

            $scaledIngredientCost = 0.0;
            $scaledPackagingComponentCost = 0.0;
            $scaledWastageCost = 0.0;
            $scaledLines = [];

            foreach ($recipe->ingredients->sortBy('ingredient_product_id') as $ingredient) {
                $netQty = (float) $ingredient->quantity * $multiplier;
                $wastageQty = $netQty * ((float) $ingredient->wastage_percent / 100);
                $requiredQty = $netQty + $wastageQty;

                $sourceStock = $this->getStock($companyId, $branchId, $ingredient->ingredient_product_id, $sourceStockArea, true);
                if (!$sourceStock || (float) $sourceStock->available_quantity < $requiredQty) {
                    throw ValidationException::withMessages([
                        'stock' => "Insufficient {$sourceStockArea} stock for {$ingredient->ingredientProduct?->name}. Required " . round($requiredQty, 4) . ', available ' . round((float) ($sourceStock?->available_quantity ?? 0), 4) . '.',
                    ]);
                }

                $baseLine = collect($baseCost['lines'])->firstWhere('ingredient_product_id', $ingredient->ingredient_product_id);
                $unitCost = (float) ($baseLine['unit_cost'] ?? 0);
                $lineCost = $requiredQty * $unitCost;
                $wastageCost = $wastageQty * $unitCost;
                $lineType = $this->safeLineType($ingredient->line_type, $ingredient->ingredientProduct?->product_type);

                if ($lineType === 'packaging') {
                    $scaledPackagingComponentCost += $lineCost;
                } else {
                    $scaledIngredientCost += $lineCost;
                }
                $scaledWastageCost += $wastageCost;

                $scaledLines[] = [
                    'ingredient' => $ingredient,
                    'source_stock' => $sourceStock,
                    'line_type' => $lineType,
                    'net_quantity' => $netQty,
                    'wastage_quantity' => $wastageQty,
                    'gross_quantity' => $requiredQty,
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ];
            }

            $packagingCost = $scaledPackagingComponentCost + ((float) ($recipe->packaging_cost ?? 0) * $multiplier);
            $labourCost = (float) ($recipe->labour_cost ?? 0) * $multiplier;
            $overheadCost = (float) ($recipe->overhead_cost ?? 0) * $multiplier;
            $totalCost = $scaledIngredientCost + $packagingCost + $labourCost + $overheadCost;
            $unitOutputCost = $actualYieldQuantity > 0 ? $totalCost / $actualYieldQuantity : 0;

            $batch = FoodManufacturingBatch::create($this->onlyExistingTableColumns('food_manufacturing_batches', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'food_recipe_id' => $recipe->id,
                'recipe_version_no' => (int) $recipe->version_no,
                'produced_product_id' => $producedProduct->id,
                'source_stock_area' => $sourceStockArea,
                'destination_stock_area' => $destinationStockArea,
                'planned_yield_quantity' => $plannedYieldQuantity,
                'actual_yield_quantity' => $actualYieldQuantity,
                'ingredient_cost' => $scaledIngredientCost,
                'wastage_cost' => $scaledWastageCost,
                'packaging_cost' => $packagingCost,
                'labour_cost' => $labourCost,
                'overhead_cost' => $overheadCost,
                'total_cost' => $totalCost,
                'unit_cost' => $unitOutputCost,
                'currency' => $baseCost['currency'],
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]));

            foreach ($scaledLines as $scaledLine) {
                $ingredient = $scaledLine['ingredient'];
                $requiredQty = (float) $scaledLine['gross_quantity'];
                $unitCost = (float) $scaledLine['unit_cost'];
                $lineCost = (float) $scaledLine['line_cost'];

                $sourceStock = $scaledLine['source_stock'];
                $sourceStock->quantity_on_hand = (float) $sourceStock->quantity_on_hand - $requiredQty;
                $sourceStock->available_quantity = (float) $sourceStock->available_quantity - $requiredQty;
                $sourceStock->save();

                FoodManufacturingBatchLine::create($this->onlyExistingTableColumns('food_manufacturing_batch_lines', [
                    'company_id' => $companyId,
                    'food_manufacturing_batch_id' => $batch->id,
                    'ingredient_product_id' => $ingredient->ingredient_product_id,
                    'uom_id' => $ingredient->uom_id ?: $ingredient->ingredientProduct?->base_uom_id,
                    'line_type' => $scaledLine['line_type'],
                    'stock_area' => $sourceStockArea,
                    'net_quantity' => $scaledLine['net_quantity'],
                    'wastage_percent' => $ingredient->wastage_percent,
                    'wastage_quantity' => $scaledLine['wastage_quantity'],
                    'gross_quantity' => $requiredQty,
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                    'currency' => $baseCost['currency'],
                ]));

                StockMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $ingredient->ingredient_product_id,
                    'movement_type' => 'manufacturing_out',
                    'stock_area' => $sourceStockArea,
                    'quantity_in' => 0,
                    'quantity_out' => $requiredQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'currency' => $baseCost['currency'],
                    'reference_type' => 'food_manufacturing_batch',
                    'reference_id' => $batch->id,
                    'notes' => $notes,
                    'metadata' => [
                        'production_movement_type' => 'food_manufacturing_consumption',
                        'recipe_id' => $recipe->id,
                        'recipe_name' => $recipe->name,
                        'base_recipe_yield' => $baseYield,
                        'actual_yield_quantity' => $actualYieldQuantity,
                        'multiplier' => $multiplier,
                        'produced_product_id' => $producedProduct->id,
                        'produced_product_name' => $producedProduct->name,
                        'source_stock_area' => $sourceStockArea,
                        'destination_stock_area' => $destinationStockArea,
                        'line_type' => $scaledLine['line_type'],
                    ],
                    'created_by' => auth()->id(),
                ]);
            }

            $producedStock = $this->getOrCreateStock($producedProduct, $branchId, $destinationStockArea, true);
            $producedStock->quantity_on_hand = (float) $producedStock->quantity_on_hand + $actualYieldQuantity;
            $producedStock->available_quantity = (float) $producedStock->available_quantity + $actualYieldQuantity;
            $producedStock->save();

            $this->updateProductCostAfterProduction(
                $companyId,
                $branchId,
                $producedProduct->id,
                $actualYieldQuantity,
                $totalCost,
                $baseCost['currency']
            );

            StockMovement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $producedProduct->id,
                'movement_type' => 'manufacturing_in',
                'stock_area' => $destinationStockArea,
                'quantity_in' => $actualYieldQuantity,
                'quantity_out' => 0,
                'unit_cost' => $unitOutputCost,
                'total_cost' => $totalCost,
                'currency' => $baseCost['currency'],
                'reference_type' => 'food_manufacturing_batch',
                'reference_id' => $batch->id,
                'notes' => $notes,
                'metadata' => [
                    'production_movement_type' => 'food_manufacturing_output',
                    'recipe_id' => $recipe->id,
                    'recipe_name' => $recipe->name,
                    'base_recipe_yield' => $baseYield,
                    'actual_yield_quantity' => $actualYieldQuantity,
                    'multiplier' => $multiplier,
                    'produced_product_id' => $producedProduct->id,
                    'produced_product_name' => $producedProduct->name,
                    'produced_product_type' => $producedProduct->product_type,
                    'source_stock_area' => $sourceStockArea,
                    'destination_stock_area' => $destinationStockArea,
                ],
                'created_by' => auth()->id(),
            ]);

            return $batch->fresh(['recipe.menuProduct', 'recipe.producedProduct', 'producedProduct', 'lines.ingredientProduct']);
        });
    }

    public function deductMenuRecipeFromFrontStock(string $companyId, string $branchId, string $saleItemId, string $menuProductId, float $quantitySold): array
    {
        return DB::transaction(function () use ($companyId, $branchId, $saleItemId, $menuProductId, $quantitySold) {
            $recipe = FoodRecipe::where('company_id', $companyId)
                ->where('menu_product_id', $menuProductId)
                ->where('recipe_type', 'menu')
                ->where('is_active', true)
                ->with($this->recipeRelations(['ingredients.ingredientProduct']))
                ->orderByDesc('version_no')
                ->lockForUpdate()
                ->first();

            if (!$recipe) {
                return ['deducted' => false, 'message' => 'No active menu recipe linked to this menu item.', 'total_cost' => 0, 'lines' => []];
            }

            $cost = $this->calculateRecipeCost($companyId, $branchId, $recipe);
            $deductedLines = [];
            $multiplier = $quantitySold / max((float) $recipe->yield_quantity, 0.000001);

            $lockedStocks = [];
            foreach ($recipe->ingredients->sortBy('ingredient_product_id') as $ingredient) {
                $netQty = (float) $ingredient->quantity * $multiplier;
                $wastageQty = $netQty * ((float) $ingredient->wastage_percent / 100);
                $requiredQty = $netQty + $wastageQty;
                $stock = $this->getStock($companyId, $branchId, $ingredient->ingredient_product_id, 'front', true);

                if (!$stock || (float) $stock->available_quantity < $requiredQty) {
                    throw ValidationException::withMessages(['stock' => "Insufficient front stock for {$ingredient->ingredientProduct?->name}."]);
                }

                $lockedStocks[(string) $ingredient->ingredient_product_id] = $stock;
            }

            foreach ($recipe->ingredients as $ingredient) {
                $netQty = (float) $ingredient->quantity * $multiplier;
                $wastageQty = $netQty * ((float) $ingredient->wastage_percent / 100);
                $requiredQty = $netQty + $wastageQty;
                $lineCostData = collect($cost['lines'])->firstWhere('ingredient_product_id', $ingredient->ingredient_product_id);
                $unitCost = (float) ($lineCostData['unit_cost'] ?? 0);
                $lineCost = $requiredQty * $unitCost;

                $stock = $lockedStocks[(string) $ingredient->ingredient_product_id];
                $stock->quantity_on_hand -= $requiredQty;
                $stock->available_quantity -= $requiredQty;
                $stock->save();

                StockMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $ingredient->ingredient_product_id,
                    'movement_type' => 'recipe_sale_consumption',
                    'stock_area' => 'front',
                    'quantity_in' => 0,
                    'quantity_out' => $requiredQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'currency' => $cost['currency'],
                    'reference_type' => 'sale_item',
                    'reference_id' => $saleItemId,
                    'notes' => 'Menu recipe consumption',
                    'created_by' => auth()->id(),
                ]);

                $deductedLines[] = [
                    'ingredient_product_id' => $ingredient->ingredient_product_id,
                    'ingredient_name' => $ingredient->ingredientProduct?->name,
                    'quantity' => round($requiredQty, 6),
                    'unit_cost' => round($unitCost, 6),
                    'line_cost' => round($lineCost, 6),
                    'line_type' => $this->safeLineType($ingredient->line_type, $ingredient->ingredientProduct?->product_type),
                ];
            }

            return ['deducted' => true, 'recipe_id' => $recipe->id, 'currency' => $cost['currency'], 'total_cost' => round(collect($deductedLines)->sum('line_cost'), 6), 'lines' => $deductedLines];
        });
    }

    public function assertNoRecipeCycle(string $companyId, FoodRecipe $recipe): void
    {
        $recipe->loadMissing(['ingredients.ingredientProduct']);
        $outputProductId = (string) ($recipe->produced_product_id ?: $recipe->menu_product_id ?: '');
        if ($outputProductId === '') {
            return;
        }

        $recipesByOutput = $this->activeRecipesByOutput($companyId, $recipe);
        $this->walkRecipeGraph(
            $outputProductId,
            $recipesByOutput,
            [],
            0,
            static function (): void {
                // Validation only; no material rows are collected here.
            }
        );
    }

    public function explodeRecipeRequirements(
        string $companyId,
        FoodRecipe $recipe,
        float $plannedOutputQuantity,
        int $maxDepth = 10
    ): array {
        if ($plannedOutputQuantity <= 0) {
            return ['lines' => [], 'subrecipes' => [], 'max_depth' => 0];
        }

        $recipe->loadMissing($this->recipeRelations(['ingredients.ingredientProduct.uom']));
        $outputProductId = (string) ($recipe->produced_product_id ?: $recipe->menu_product_id ?: '');
        $recipesByOutput = $this->activeRecipesByOutput($companyId, $recipe);
        $leafLines = [];
        $subrecipes = [];
        $deepestLevel = 0;

        $expand = function (FoodRecipe $currentRecipe, float $outputQuantity, array $path, int $level) use (
            &$expand,
            &$leafLines,
            &$subrecipes,
            &$deepestLevel,
            $recipesByOutput,
            $maxDepth
        ): void {
            if ($level > $maxDepth) {
                throw ValidationException::withMessages([
                    'recipe_id' => "BOM depth exceeds the supported {$maxDepth} levels.",
                ]);
            }

            $currentOutputId = (string) ($currentRecipe->produced_product_id ?: $currentRecipe->menu_product_id ?: '');
            if ($currentOutputId !== '' && in_array($currentOutputId, $path, true)) {
                $cycle = implode(' -> ', array_merge($path, [$currentOutputId]));
                throw ValidationException::withMessages([
                    'recipe_id' => "Circular BOM detected: {$cycle}.",
                ]);
            }

            $currentRecipe->loadMissing($this->recipeRelations(['ingredients.ingredientProduct.uom']));
            $nextPath = $currentOutputId === '' ? $path : array_merge($path, [$currentOutputId]);
            $multiplier = $outputQuantity / max((float) ($currentRecipe->yield_quantity ?: 1), 0.000001);
            $deepestLevel = max($deepestLevel, $level);

            foreach ($currentRecipe->ingredients as $ingredient) {
                $netQuantity = (float) $ingredient->quantity * $multiplier;
                $wastageQuantity = $netQuantity * ((float) ($ingredient->wastage_percent ?? 0) / 100);
                $requiredQuantity = $netQuantity + $wastageQuantity;
                $product = $ingredient->ingredientProduct;
                $productId = (string) $ingredient->ingredient_product_id;
                $lineType = $this->safeLineType($ingredient->line_type, $product?->product_type);
                $childRecipe = $recipesByOutput[$productId] ?? null;
                $canExplode = $childRecipe
                    && ($lineType === 'sub_recipe' || in_array(strtolower((string) $product?->product_type), ['semi_finished', 'semi_finished_good'], true));

                if ($canExplode) {
                    $subrecipes[] = [
                        'level' => $level + 1,
                        'parent_recipe_id' => $currentRecipe->id,
                        'parent_recipe_version' => (int) $currentRecipe->version_no,
                        'recipe_id' => $childRecipe->id,
                        'recipe_version' => (int) $childRecipe->version_no,
                        'output_product_id' => $productId,
                        'output_product_name' => $product?->name,
                        'required_output_quantity' => round($requiredQuantity, 6),
                        'path' => $nextPath,
                    ];
                    $expand($childRecipe, $requiredQuantity, $nextPath, $level + 1);
                    continue;
                }

                $key = $productId . '|' . $lineType;
                if (!isset($leafLines[$key])) {
                    $leafLines[$key] = [
                        'ingredient_product_id' => $productId,
                        'ingredient_name' => $product?->name,
                        'ingredient_code' => $product?->code ?: $product?->sku,
                        'ingredient_type' => $product?->product_type,
                        'line_type' => $lineType,
                        'uom_id' => $ingredient->uom_id ?: $product?->base_uom_id,
                        'uom' => $ingredient->uom?->code ?: $ingredient->uom?->name ?: $product?->uom?->code ?: $product?->uom?->name ?: 'EACH',
                        'required_quantity' => 0.0,
                        'net_quantity' => 0.0,
                        'wastage_quantity' => 0.0,
                        'source_recipe_ids' => [],
                        'source_recipe_versions' => [],
                        'paths' => [],
                    ];
                }

                $leafLines[$key]['required_quantity'] += $requiredQuantity;
                $leafLines[$key]['net_quantity'] += $netQuantity;
                $leafLines[$key]['wastage_quantity'] += $wastageQuantity;
                $leafLines[$key]['source_recipe_ids'][] = (string) $currentRecipe->id;
                $leafLines[$key]['source_recipe_versions'][] = (int) $currentRecipe->version_no;
                $leafLines[$key]['paths'][] = $nextPath;
            }
        };

        $expand($recipe, $plannedOutputQuantity, [], 0);

        $normalisedLines = collect($leafLines)->map(function (array $line) {
            $line['required_quantity'] = round($line['required_quantity'], 6);
            $line['net_quantity'] = round($line['net_quantity'], 6);
            $line['wastage_quantity'] = round($line['wastage_quantity'], 6);
            $line['source_recipe_ids'] = array_values(array_unique($line['source_recipe_ids']));
            $line['source_recipe_versions'] = array_values(array_unique($line['source_recipe_versions']));
            $line['paths'] = array_values($line['paths']);
            return $line;
        })->values()->all();

        return [
            'root_output_product_id' => $outputProductId ?: null,
            'root_recipe_id' => $recipe->id,
            'root_recipe_version' => (int) $recipe->version_no,
            'lines' => $normalisedLines,
            'subrecipes' => $subrecipes,
            'max_depth' => $deepestLevel,
        ];
    }

    private function activeRecipesByOutput(string $companyId, FoodRecipe $currentRecipe): array
    {
        $recipes = FoodRecipe::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($currentRecipe) {
                $query->whereIn('recipe_type', ['manufacturing', 'prep'])
                    ->orWhere('id', $currentRecipe->id);
            })
            ->where(function ($query) use ($currentRecipe) {
                $query->where('is_active', true)->orWhere('id', $currentRecipe->id);
            })
            ->with($this->recipeRelations(['ingredients.ingredientProduct.uom']))
            ->orderByDesc('version_no')
            ->get();

        $byOutput = [];
        foreach ($recipes as $candidate) {
            $outputId = (string) ($candidate->produced_product_id ?: $candidate->menu_product_id ?: '');
            if ($outputId === '') {
                continue;
            }

            if ($candidate->id === $currentRecipe->id || !isset($byOutput[$outputId])) {
                $byOutput[$outputId] = $candidate;
            }
        }

        return $byOutput;
    }

    private function walkRecipeGraph(
        string $productId,
        array $recipesByOutput,
        array $path,
        int $depth,
        callable $visit
    ): void {
        if ($depth > 10) {
            throw ValidationException::withMessages([
                'ingredients' => 'BOM depth exceeds the supported 10 levels.',
            ]);
        }

        if (in_array($productId, $path, true)) {
            $cycle = implode(' -> ', array_merge($path, [$productId]));
            throw ValidationException::withMessages([
                'ingredients' => "Circular BOM detected: {$cycle}.",
            ]);
        }

        $recipe = $recipesByOutput[$productId] ?? null;
        if (!$recipe) {
            return;
        }

        $visit($recipe, $depth);
        $nextPath = array_merge($path, [$productId]);
        foreach ($recipe->ingredients as $ingredient) {
            $ingredientId = (string) $ingredient->ingredient_product_id;
            $lineType = $this->safeLineType(
                $ingredient->line_type,
                $ingredient->ingredientProduct?->product_type
            );
            $isSubRecipe = $lineType === 'sub_recipe'
                || in_array(
                    strtolower((string) $ingredient->ingredientProduct?->product_type),
                    ['semi_finished', 'semi_finished_good'],
                    true
                );

            if ($isSubRecipe && isset($recipesByOutput[$ingredientId])) {
                $this->walkRecipeGraph($ingredientId, $recipesByOutput, $nextPath, $depth + 1, $visit);
            }
        }
    }

    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    private function recipeRelations(array $relations, bool $includeYieldUom = false): array
    {
        if ($this->tableHasColumn('food_recipe_ingredients', 'uom_id')) {
            $relations[] = 'ingredients.uom';
        }
        if ($includeYieldUom && $this->tableHasColumn('food_recipes', 'yield_uom_id')) {
            $relations[] = 'yieldUom';
        }

        return array_values(array_unique($relations));
    }

    private function safeStockArea(?string $stockArea, string $fallback = 'back'): string
    {
        return in_array($stockArea, ['front', 'back'], true) ? $stockArea : $fallback;
    }

    private function getProductCost(string $companyId, string $branchId, string $productId): ?ProductCost
    {
        return ProductCost::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
            ->first();
    }

    private function getStock(
        string $companyId,
        string $branchId,
        string $productId,
        string $stockArea,
        bool $lockForUpdate = false
    ): ?ProductStock
    {
        $query = ProductStock::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('stock_area', $stockArea);

        return $lockForUpdate ? $query->lockForUpdate()->first() : $query->first();
    }

    private function getOrCreateStock(Product $product, string $branchId, string $stockArea, bool $lockForUpdate = false): ProductStock
    {
        $stock = ProductStock::firstOrCreate(
            ['company_id' => $product->company_id, 'branch_id' => $branchId, 'product_id' => $product->id, 'stock_area' => $stockArea],
            ['quantity_on_hand' => 0, 'reserved_quantity' => 0, 'available_quantity' => 0]
        );

        return $lockForUpdate
            ? ProductStock::whereKey($stock->id)->lockForUpdate()->firstOrFail()
            : $stock;
    }

    private function safeLineType(?string $lineType, ?string $productType = null): string
    {
        $normalised = strtolower(trim((string) $lineType));
        if (in_array($normalised, ['ingredient', 'packaging', 'consumable', 'sub_recipe'], true)) {
            return $normalised;
        }

        return match (strtolower((string) $productType)) {
            'packaging' => 'packaging',
            'consumable', 'consumables' => 'consumable',
            'semi_finished', 'semi_finished_good' => 'sub_recipe',
            default => 'ingredient',
        };
    }

    private function updateProductCostAfterProduction(string $companyId, string $branchId, string $productId, float $quantity, float $totalCost, string $currency): ProductCost
    {
        $cost = ProductCost::firstOrCreate(
            ['company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId],
            ['average_cost' => 0, 'last_cost' => 0, 'total_quantity' => 0, 'total_value' => 0, 'currency' => $currency]
        );

        $previousQty = (float) $cost->total_quantity;
        $previousValue = (float) $cost->total_value;
        $newQty = $previousQty + $quantity;
        $newValue = $previousValue + $totalCost;

        $cost->last_cost = $quantity > 0 ? $totalCost / $quantity : 0;
        $cost->total_quantity = $newQty;
        $cost->total_value = $newValue;
        $cost->average_cost = $newQty > 0 ? $newValue / $newQty : 0;
        $cost->currency = $currency;
        $cost->save();

        return $cost;
    }
}
