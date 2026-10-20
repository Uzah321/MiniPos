<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Categories become brand-owned (a brand only sees its own), and recipes
 * created without a brand are filed under the brand of their product.
 *
 * Existing categories are assigned to the brand whose products use them. A
 * category used by several brands (Brand Menu Import used to reuse categories
 * by name across brands) is split: the brand with the most products keeps it,
 * every other brand gets its own copy and its products move to that copy.
 * No product belongs to two brands, so the split is unambiguous.
 *
 * Shop (edge) servers only add the columns: their categories arrive from the
 * Back Office, and splitting locally would create IDs the Back Office does not
 * know.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_categories', 'product_sub_categories'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'brand_id')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->string('brand_id', 36)->nullable()->after('company_id');
                    $table->index(['company_id', 'brand_id']);
                });
            }
        }

        if (config('servnest.deployment_mode') === 'edge'
            || ! Schema::hasTable('brand_products')
            || ! Schema::hasTable('products')) {
            return;
        }

        DB::transaction(function (): void {
            $this->assignCategories();
            $this->assignSubCategories();
            $this->assignRecipes();
        });
    }

    public function down(): void
    {
        foreach (['product_sub_categories', 'product_categories'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'brand_id')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropIndex(['company_id', 'brand_id']);
                    $table->dropColumn('brand_id');
                });
            }
        }
    }

    private function assignCategories(): void
    {
        $usage = DB::table('products')
            ->join('brand_products', 'brand_products.product_id', '=', 'products.id')
            ->whereNotNull('products.category_id')
            ->whereNull('products.deleted_at')
            ->whereNull('brand_products.deleted_at')
            ->selectRaw('products.category_id, brand_products.brand_id, COUNT(DISTINCT products.id) AS product_count')
            ->groupBy('products.category_id', 'brand_products.brand_id')
            ->get()
            ->groupBy('category_id');

        $brandCodes = DB::table('brands')->pluck('code', 'id');

        foreach ($usage as $categoryId => $brands) {
            $category = DB::table('product_categories')->where('id', $categoryId)->first();
            if (! $category) {
                continue;
            }

            $ordered = $brands->sortByDesc('product_count')->values();
            DB::table('product_categories')->where('id', $categoryId)->update(['brand_id' => $ordered[0]->brand_id]);

            foreach ($ordered->slice(1) as $other) {
                $copyId = (string) Str::uuid();
                $suffix = strtoupper((string) ($brandCodes[$other->brand_id] ?? substr((string) $other->brand_id, 0, 6)));
                $copy = (array) $category;
                $copy['id'] = $copyId;
                $copy['brand_id'] = $other->brand_id;
                $copy['code'] = $category->code ? substr($category->code.'-'.$suffix, 0, 255) : null;
                $copy['created_at'] = now();
                $copy['updated_at'] = now();
                DB::table('product_categories')->insert($copy);

                DB::table('products')
                    ->where('category_id', $categoryId)
                    ->whereExists(fn ($links) => $links->selectRaw('1')
                        ->from('brand_products')
                        ->whereColumn('brand_products.product_id', 'products.id')
                        ->where('brand_products.brand_id', $other->brand_id)
                        ->whereNull('brand_products.deleted_at'))
                    ->update(['category_id' => $copyId, 'updated_at' => now()]);

                $this->repointDiscountCategories((string) $categoryId, $copyId, (string) $other->brand_id);
            }
        }
    }

    /** Category discounts set up for the other brand's shops follow its copy. */
    private function repointDiscountCategories(string $categoryId, string $copyId, string $brandId): void
    {
        if (! Schema::hasTable('discount_categories') || ! Schema::hasTable('discounts')
            || ! Schema::hasColumn('discounts', 'branch_id') || ! Schema::hasTable('shops')) {
            return;
        }

        $brandBranches = DB::table('shops')->where('brand_id', $brandId)->pluck('branch_id');
        $discountIds = DB::table('discounts')->whereIn('branch_id', $brandBranches)->pluck('id');
        DB::table('discount_categories')
            ->where('category_id', $categoryId)
            ->whereIn('discount_id', $discountIds)
            ->update(['category_id' => $copyId]);
    }

    private function assignSubCategories(): void
    {
        if (! Schema::hasTable('product_sub_categories')) {
            return;
        }

        foreach (DB::table('product_categories')->whereNotNull('brand_id')->get(['id', 'brand_id']) as $category) {
            DB::table('product_sub_categories')
                ->where('category_id', $category->id)
                ->whereNull('brand_id')
                ->update(['brand_id' => $category->brand_id]);
        }
    }

    /** Recipes saved without a brand take the brand of their menu or produced product. */
    private function assignRecipes(): void
    {
        if (! Schema::hasTable('food_recipes') || ! Schema::hasColumn('food_recipes', 'brand_id')) {
            return;
        }

        foreach (DB::table('food_recipes')->whereNull('brand_id')->get(['id', 'menu_product_id', 'produced_product_id']) as $recipe) {
            $productIds = array_values(array_filter([$recipe->menu_product_id, $recipe->produced_product_id]));
            if ($productIds === []) {
                continue;
            }
            $brands = DB::table('brand_products')
                ->whereIn('product_id', $productIds)
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('brand_id');
            if ($brands->count() === 1) {
                DB::table('food_recipes')->where('id', $recipe->id)->update(['brand_id' => $brands->first()]);
            }
        }
    }
};
