<?php

namespace App\Services\Sales;

use App\Services\CompanySettingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One source of truth for report costing.
 *
 * Retail uses the branch product weighted-average cost. Hospitality uses the
 * ingredient cost captured by the recipe stock movements at the time of sale;
 * the active recipe snapshot is used only for legacy sales without movements.
 */
class SalesCostingService
{
    public function __construct(private readonly CompanySettingService $settings) {}

    public function mode(string $companyId, ?string $branchId = null): array
    {
        $hospitality = $this->settings->isHospitalityMode($companyId, $branchId);

        return [
            'business_mode' => $hospitality ? 'hospitality' : 'retail',
            'label' => $hospitality ? 'Hospitality / Recipe Costing' : 'Retail / Historical Sale Costing',
            'cost_source' => $hospitality ? 'recipe_ingredient_consumption' : 'sale_stock_movement_with_weighted_average_fallback',
            'boh_enabled' => $this->settings->isBohEnabled($companyId, $branchId),
            'fast_food_mode_enabled' => $this->settings->isFastFoodMode($companyId, $branchId),
            'receive_stock_to_boh_first' => $this->settings->enabled($companyId, 'receive_stock_to_boh_first', $branchId),
            'recipe_consumes_front_stock' => $this->settings->enabled($companyId, 'recipe_consumes_front_stock', $branchId),
        ];
    }

    public function isHospitality(string $companyId, ?string $branchId = null): bool
    {
        return $this->mode($companyId, $branchId)['business_mode'] === 'hospitality';
    }

    /** Actual, immutable recipe cost recorded for each receipt line. */
    public function recipeMovementCostsByReceiptLine(string $companyId): Builder
    {
        $receiptLineId = 'JSON_UNQUOTE(JSON_EXTRACT(stock_movements.metadata, "$.receipt_line_id"))';

        return DB::table('stock_movements')
            ->join('receipt_lines as costing_receipt_lines', function ($join) use ($receiptLineId) {
                $join->on('costing_receipt_lines.sales_transaction_id', '=', 'stock_movements.reference_id')
                    ->whereRaw("costing_receipt_lines.id = {$receiptLineId}");
            })
            ->selectRaw("stock_movements.reference_id as sales_transaction_id, {$receiptLineId} as receipt_line_id")
            ->selectRaw('SUM(COALESCE(NULLIF(stock_movements.total_cost, 0), stock_movements.quantity_out * stock_movements.unit_cost, 0)) as recipe_cost')
            ->selectRaw('MAX(COALESCE(stock_movements.currency_code, stock_movements.currency)) as cost_currency_code')
            ->where('stock_movements.company_id', $companyId)
            ->where(function ($query) {
                $query->whereIn('stock_movements.reference_type', ['sale_recipe', 'sale_modifier_recipe'])
                    ->orWhere(function ($legacyBatchQuery) {
                        $legacyBatchQuery->where('stock_movements.reference_type', 'sale')
                            ->whereNotNull('stock_movements.batch_id')
                            ->whereColumn('stock_movements.product_id', '<>', 'costing_receipt_lines.product_id');
                    });
            })
            ->whereRaw('COALESCE(stock_movements.quantity_out, 0) > 0')
            ->whereRaw("{$receiptLineId} IS NOT NULL")
            ->whereRaw("{$receiptLineId} <> ''")
            ->groupBy('stock_movements.reference_id', DB::raw($receiptLineId));
    }

    /**
     * Latest active recipe cost per menu product and branch, used for legacy
     * receipt lines only.
     *
     * Recipe definitions are company-wide, while ingredient weighted-average
     * costs are branch-specific. Recalculate the estimate from the branch's
     * current ingredient costs instead of using food_recipes.cost_per_unit,
     * which is only the snapshot from whichever branch last costed the recipe.
     */
    public function activeRecipeUnitCosts(string $companyId, ?string $branchId = null): Builder
    {
        $activeRecipes = DB::table('food_recipes as report_recipes')
            ->select([
                'report_recipes.id',
                'report_recipes.company_id',
                'report_recipes.menu_product_id',
                'report_recipes.version_no',
                'report_recipes.yield_quantity',
                'report_recipes.currency',
                'report_recipes.packaging_cost',
                'report_recipes.labour_cost',
                'report_recipes.overhead_cost',
            ])
            ->where('report_recipes.company_id', $companyId)
            ->where('report_recipes.recipe_type', 'menu')
            ->where('report_recipes.is_active', true)
            ->whereNull('report_recipes.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('food_recipes as newer_report_recipes')
                    ->whereColumn('newer_report_recipes.company_id', 'report_recipes.company_id')
                    ->whereColumn('newer_report_recipes.menu_product_id', 'report_recipes.menu_product_id')
                    ->where('newer_report_recipes.recipe_type', 'menu')
                    ->where('newer_report_recipes.is_active', true)
                    ->whereNull('newer_report_recipes.deleted_at')
                    ->whereColumn('newer_report_recipes.version_no', '>', 'report_recipes.version_no');
            });

        $ingredientUnitCost = 'COALESCE(NULLIF(recipe_ingredient_costs.average_cost, 0), recipe_ingredient_costs.last_cost, 0)';
        $grossIngredientQuantity = 'COALESCE(recipe_ingredients.quantity, 0) * (1 + COALESCE(recipe_ingredients.wastage_percent, 0) / 100.0)';
        $ingredientCostCurrency = $this->productCostCurrencyExpression('recipe_ingredient_costs');
        $recipeCostCurrency = $ingredientCostCurrency
            ? "COALESCE(MAX({$ingredientCostCurrency}), MAX(active_recipes.currency), 'USD')"
            : "COALESCE(MAX(active_recipes.currency), 'USD')";

        return DB::query()
            ->fromSub($activeRecipes, 'active_recipes')
            ->join('company_branches as recipe_branches', 'recipe_branches.company_id', '=', 'active_recipes.company_id')
            ->leftJoin('food_recipe_ingredients as recipe_ingredients', function ($join) {
                $join->on('recipe_ingredients.food_recipe_id', '=', 'active_recipes.id')
                    ->on('recipe_ingredients.company_id', '=', 'active_recipes.company_id');
            })
            ->leftJoin('product_costs as recipe_ingredient_costs', function ($join) {
                $join->on('recipe_ingredient_costs.company_id', '=', 'active_recipes.company_id')
                    ->on('recipe_ingredient_costs.branch_id', '=', 'recipe_branches.id')
                    ->on('recipe_ingredient_costs.product_id', '=', 'recipe_ingredients.ingredient_product_id');
            })
            ->selectRaw('recipe_branches.id as branch_id, active_recipes.menu_product_id')
            ->selectRaw("(
                COALESCE(SUM(({$grossIngredientQuantity}) * ({$ingredientUnitCost})), 0)
                + COALESCE(MAX(active_recipes.packaging_cost), 0)
                + COALESCE(MAX(active_recipes.labour_cost), 0)
                + COALESCE(MAX(active_recipes.overhead_cost), 0)
            ) / NULLIF(MAX(active_recipes.yield_quantity), 0) as recipe_unit_cost")
            ->selectRaw("{$recipeCostCurrency} as cost_currency_code")
            ->selectRaw('MAX(active_recipes.id) as recipe_id, MAX(active_recipes.version_no) as recipe_version_no')
            ->where('recipe_branches.company_id', $companyId)
            ->when($branchId, fn ($query) => $query->where('recipe_branches.id', $branchId))
            ->groupBy('recipe_branches.id', 'active_recipes.menu_product_id');
    }

    /** Immutable retail COGS captured by stock movements when the sale posted. */
    public function retailMovementCostsByReceiptLine(string $companyId): Builder
    {
        $receiptLineId = 'JSON_UNQUOTE(JSON_EXTRACT(stock_movements.metadata, "$.receipt_line_id"))';

        return DB::table('stock_movements')
            ->selectRaw("stock_movements.reference_id as sales_transaction_id, {$receiptLineId} as receipt_line_id")
            ->selectRaw('SUM(COALESCE(NULLIF(stock_movements.total_cost, 0), stock_movements.quantity_out * stock_movements.unit_cost, 0)) as movement_cost')
            ->selectRaw('MAX(COALESCE(stock_movements.currency_code, stock_movements.currency)) as cost_currency_code')
            ->where('stock_movements.company_id', $companyId)
            ->where('stock_movements.reference_type', 'sale')
            ->where('stock_movements.movement_type', 'sale')
            ->whereRaw('COALESCE(stock_movements.quantity_out, 0) > 0')
            ->whereRaw("{$receiptLineId} IS NOT NULL")
            ->whereRaw("{$receiptLineId} <> ''")
            ->groupBy('stock_movements.reference_id', DB::raw($receiptLineId));
    }

    public function unitCostExpression(bool $hospitality, string $quantityExpression = 'receipt_lines.line_quantity'): string
    {
        if ($hospitality) {
            return "COALESCE(recipe_line_costs.recipe_cost / NULLIF({$quantityExpression}, 0), active_recipe_costs.recipe_unit_cost, 0)";
        }

        return "COALESCE(retail_line_costs.movement_cost / NULLIF({$quantityExpression}, 0), NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)";
    }

    public function lineCostExpression(bool $hospitality, string $quantityExpression = 'receipt_lines.line_quantity'): string
    {
        if ($hospitality) {
            return "COALESCE(recipe_line_costs.recipe_cost, active_recipe_costs.recipe_unit_cost * {$quantityExpression}, 0)";
        }

        return "COALESCE(retail_line_costs.movement_cost, {$quantityExpression} * COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0))";
    }

    public function costCurrencyExpression(bool $hospitality, string $fallbackCurrencyExpression = 'sales_transactions.base_currency_code'): string
    {
        if ($hospitality) {
            return "COALESCE(recipe_line_costs.cost_currency_code, active_recipe_costs.cost_currency_code, {$fallbackCurrencyExpression})";
        }

        $productCostCurrency = $this->productCostCurrencyExpression();

        if ($productCostCurrency) {
            return "COALESCE(retail_line_costs.cost_currency_code, {$productCostCurrency}, {$fallbackCurrencyExpression})";
        }

        return "COALESCE(retail_line_costs.cost_currency_code, {$fallbackCurrencyExpression})";
    }

    /**
     * Resolve the product-cost currency without assuming every deployed tenant
     * has the same version of the legacy currency migration.
     */
    public function productCostCurrencyExpression(string $tableAlias = 'product_costs', ?array $availableColumns = null): ?string
    {
        $availableColumns ??= $this->productCostCurrencyColumns();
        $hasCurrencyCode = in_array('currency_code', $availableColumns, true);
        $hasLegacyCurrency = in_array('currency', $availableColumns, true);

        if ($hasCurrencyCode && $hasLegacyCurrency) {
            return "COALESCE({$tableAlias}.currency_code, {$tableAlias}.currency)";
        }

        if ($hasCurrencyCode) {
            return "{$tableAlias}.currency_code";
        }

        if ($hasLegacyCurrency) {
            return "{$tableAlias}.currency";
        }

        return null;
    }

    private function productCostCurrencyColumns(): array
    {
        try {
            if (! Schema::getFacadeRoot()) {
                return [];
            }

            $columns = Schema::getColumnListing('product_costs');

            return array_values(array_intersect(['currency_code', 'currency'], $columns));
        } catch (\Throwable) {
            // Cost values remain usable even if schema inspection is unavailable;
            // the report currency then falls back to the sale/base currency.
            return [];
        }
    }
}
