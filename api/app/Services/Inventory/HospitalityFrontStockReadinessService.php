<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HospitalityFrontStockReadinessService
{
    private const EPSILON = 0.000001;

    /**
     * Return the unresolved FRONT-stock debt for one Hospitality branch.
     *
     * Negative balances are intentionally allowed only for menu items whose
     * `allow_negative_stock` switch is enabled. They are still a temporary
     * operational debt: BOH stock must be issued to FRONT before reconciliation
     * and Day End can complete.
     *
     * @return array<string, mixed>
     */
    public function assess(string $companyId, string $branchId, ?string $tradingDate = null): array
    {
        $base = [
            'can_reconcile_front_stock' => false,
            'can_run_day_end' => false,
            'trading_date' => $tradingDate,
            'negative_ingredient_count' => 0,
            'affected_menu_item_count' => 0,
            'total_issue_quantity_required' => 0.0,
            'blocking_front_stock_issues' => [],
            'blocking_reasons' => [],
            'verification_errors' => [],
        ];

        foreach (['product_stocks', 'products'] as $table) {
            if (!Schema::hasTable($table)) {
                $message = "Front-stock readiness cannot be verified because {$table} is unavailable.";

                return array_merge($base, [
                    'blocking_reasons' => [$message],
                    'verification_errors' => [$message],
                ]);
            }
        }

        $negativeStocks = DB::table('product_stocks as ps')
            ->join('products as ingredient', 'ingredient.id', '=', 'ps.product_id')
            ->where('ps.company_id', $companyId)
            ->where('ps.branch_id', $branchId)
            ->whereRaw("LOWER(COALESCE(ps.stock_area, '')) = ?", ['front'])
            ->where(function ($query) {
                $query->where('ps.quantity_on_hand', '<', -self::EPSILON)
                    ->orWhere('ps.available_quantity', '<', -self::EPSILON);
            })
            ->when(
                Schema::hasColumn('products', 'deleted_at'),
                fn ($query) => $query->whereNull('ingredient.deleted_at')
            )
            ->select([
                'ps.product_id',
                'ps.quantity_on_hand',
                'ps.reserved_quantity',
                'ps.available_quantity',
                'ingredient.name as ingredient_name',
                'ingredient.code as ingredient_code',
                'ingredient.sku as ingredient_sku',
            ])
            ->orderBy('ingredient.name')
            ->get();

        if ($negativeStocks->isEmpty()) {
            return array_merge($base, [
                'can_reconcile_front_stock' => true,
                'can_run_day_end' => true,
            ]);
        }

        $ingredientIds = $negativeStocks->pluck('product_id')->map(fn ($id) => (string) $id)->all();
        $recipesByIngredient = collect();

        if (Schema::hasTable('food_recipes') && Schema::hasTable('food_recipe_ingredients')) {
            $recipeQuery = DB::table('food_recipe_ingredients as line')
                ->join('food_recipes as recipe', 'recipe.id', '=', 'line.food_recipe_id')
                ->leftJoin('products as menu', 'menu.id', '=', 'recipe.menu_product_id')
                ->where('recipe.company_id', $companyId)
                ->whereIn('line.ingredient_product_id', $ingredientIds)
                ->whereRaw("LOWER(COALESCE(recipe.recipe_type, '')) = ?", ['menu'])
                ->where(function ($query) {
                    $query->where('recipe.is_active', true)->orWhereNull('recipe.is_active');
                });

            if (Schema::hasColumn('food_recipes', 'deleted_at')) {
                $recipeQuery->whereNull('recipe.deleted_at');
            }

            if (Schema::hasColumn('products', 'deleted_at')) {
                $recipeQuery->whereNull('menu.deleted_at');
            }

            if (Schema::hasColumn('products', 'branch_id')) {
                $recipeQuery->where(function ($query) use ($branchId) {
                    $query->whereNull('menu.branch_id')->orWhere('menu.branch_id', $branchId);
                });
            }

            $recipesByIngredient = $recipeQuery
                ->select([
                    'line.ingredient_product_id',
                    'recipe.id as recipe_id',
                    'recipe.name as recipe_name',
                    'recipe.version_no',
                    'menu.id as menu_product_id',
                    'menu.name as menu_product_name',
                    'menu.code as menu_product_code',
                    'menu.sku as menu_product_sku',
                    'menu.allow_negative_stock as menu_allows_negative_front_stock',
                ])
                ->orderBy('menu.name')
                ->get()
                ->groupBy(fn ($row) => (string) $row->ingredient_product_id);
        }

        $rows = [];
        $menuItemIds = collect();
        $totalIssueQuantity = 0.0;

        foreach ($negativeStocks as $stock) {
            $onHand = (float) ($stock->quantity_on_hand ?? 0);
            $available = (float) ($stock->available_quantity ?? 0);
            $issueQuantity = round(max(abs(min(0, $onHand)), abs(min(0, $available))), 6);
            $totalIssueQuantity += $issueQuantity;

            $menuRecipes = $recipesByIngredient->get((string) $stock->product_id, collect());

            if ($menuRecipes->isEmpty()) {
                $rows[] = $this->issueRow($stock, $issueQuantity, null);
                continue;
            }

            foreach ($menuRecipes->unique(fn ($row) => (string) ($row->menu_product_id ?: $row->recipe_id)) as $recipe) {
                if ($recipe->menu_product_id) {
                    $menuItemIds->push((string) $recipe->menu_product_id);
                }

                $rows[] = $this->issueRow($stock, $issueQuantity, $recipe);
            }
        }

        $ingredientCount = $negativeStocks->count();
        $menuCount = $menuItemIds->unique()->count();
        $reason = $menuCount > 0
            ? "Issue BOH stock to FRONT for {$ingredientCount} negative ingredient(s) used by {$menuCount} menu item(s), then complete the front-stock reconciliation."
            : "Issue BOH stock to FRONT for {$ingredientCount} negative ingredient balance(s), then complete the front-stock reconciliation.";

        return array_merge($base, [
            'negative_ingredient_count' => $ingredientCount,
            'affected_menu_item_count' => $menuCount,
            'total_issue_quantity_required' => round($totalIssueQuantity, 6),
            'blocking_front_stock_issues' => $rows,
            'blocking_reasons' => [$reason],
        ]);
    }

    /** @return array<string, mixed> */
    private function issueRow(object $stock, float $issueQuantity, ?object $recipe): array
    {
        return [
            'ingredient_product_id' => (string) $stock->product_id,
            'ingredient_name' => $stock->ingredient_name ?: 'Ingredient',
            'ingredient_code' => $stock->ingredient_code ?: $stock->ingredient_sku,
            'front_quantity_on_hand' => round((float) ($stock->quantity_on_hand ?? 0), 6),
            'front_reserved_quantity' => round((float) ($stock->reserved_quantity ?? 0), 6),
            'front_available_quantity' => round((float) ($stock->available_quantity ?? 0), 6),
            'issue_quantity_required' => $issueQuantity,
            'menu_product_id' => $recipe?->menu_product_id,
            'menu_product_name' => $recipe?->menu_product_name ?: 'Unmapped front-stock ingredient',
            'menu_product_code' => $recipe?->menu_product_code ?: $recipe?->menu_product_sku,
            'recipe_id' => $recipe?->recipe_id,
            'recipe_name' => $recipe?->recipe_name,
            'recipe_version_no' => $recipe?->version_no,
            'menu_allows_negative_front_stock' => (bool) ($recipe?->menu_allows_negative_front_stock ?? false),
            'required_action' => 'Issue stock from BOH to FRONT before Front Stock Reconciliation and Day End.',
        ];
    }
}
