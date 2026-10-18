<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function normaliseLineType(?string $productType): string
    {
        $productType = strtolower(str_replace([' ', '-'], '_', trim((string) $productType)));

        return match ($productType) {
            'packaging' => 'packaging',
            'consumable', 'consumables' => 'consumable',
            'semi_finished', 'semi_finished_good' => 'sub_recipe',
            default => 'ingredient',
        };
    }

    private function backfillRecipeYieldUoms(): void
    {
        if (!Schema::hasTable('products')
            || !Schema::hasColumn('products', 'base_uom_id')
            || !Schema::hasColumn('food_recipes', 'yield_uom_id')) {
            return;
        }

        $hasProducedProduct = Schema::hasColumn('food_recipes', 'produced_product_id');
        $hasMenuProduct = Schema::hasColumn('food_recipes', 'menu_product_id');
        if (!$hasProducedProduct && !$hasMenuProduct) {
            return;
        }

        $columns = ['id', 'yield_uom_id'];
        if ($hasMenuProduct) {
            $columns[] = 'menu_product_id';
        }
        if ($hasProducedProduct) {
            $columns[] = 'produced_product_id';
        }

        DB::table('food_recipes')
            ->whereNull('yield_uom_id')
            ->select($columns)
            ->orderBy('id')
            ->chunk(500, function ($recipes) use ($hasProducedProduct, $hasMenuProduct) {
                $productIds = collect($recipes)
                    ->flatMap(fn ($recipe) => [
                        $hasProducedProduct ? ($recipe->produced_product_id ?? null) : null,
                        $hasMenuProduct ? ($recipe->menu_product_id ?? null) : null,
                    ])
                    ->filter()
                    ->unique()
                    ->values();
                $uoms = DB::table('products')
                    ->whereIn('id', $productIds)
                    ->pluck('base_uom_id', 'id');

                foreach ($recipes as $recipe) {
                    $productId = $hasProducedProduct
                        ? ($recipe->produced_product_id ?? ($hasMenuProduct ? ($recipe->menu_product_id ?? null) : null))
                        : ($hasMenuProduct ? ($recipe->menu_product_id ?? null) : null);
                    $uomId = $productId ? $uoms->get($productId) : null;
                    if ($uomId) {
                        DB::table('food_recipes')
                            ->where('id', $recipe->id)
                            ->whereNull('yield_uom_id')
                            ->update(['yield_uom_id' => $uomId]);
                    }
                }
            });
    }

    private function backfillComponentMetadata(string $tableName): void
    {
        if (!Schema::hasTable('products')
            || !Schema::hasColumn('products', 'product_type')
            || !Schema::hasColumn($tableName, 'uom_id')
            || !Schema::hasColumn($tableName, 'line_type')) {
            return;
        }

        $hasProductUom = Schema::hasColumn('products', 'base_uom_id');
        DB::table($tableName)
            ->select(['id', 'ingredient_product_id', 'uom_id', 'line_type'])
            ->orderBy('id')
            ->chunk(500, function ($lines) use ($tableName, $hasProductUom) {
                $products = DB::table('products')
                    ->whereIn('id', collect($lines)->pluck('ingredient_product_id')->filter()->unique())
                    ->get($hasProductUom ? ['id', 'product_type', 'base_uom_id'] : ['id', 'product_type'])
                    ->keyBy('id');

                foreach ($lines as $line) {
                    $product = $products->get($line->ingredient_product_id);
                    $updates = [
                        'line_type' => $this->normaliseLineType($product?->product_type),
                    ];

                    if ($hasProductUom && empty($line->uom_id) && !empty($product?->base_uom_id)) {
                        $updates['uom_id'] = $product->base_uom_id;
                    }

                    DB::table($tableName)
                        ->where('id', $line->id)
                        ->update($updates);
                }
            });
    }

    private function backfillRecipeVersions(string $tableName): void
    {
        if (!Schema::hasTable('food_recipes')
            || !Schema::hasColumn('food_recipes', 'version_no')
            || !Schema::hasColumn($tableName, 'recipe_version_no')) {
            return;
        }

        DB::table($tableName)
            ->whereNull('recipe_version_no')
            ->select(['id', 'food_recipe_id', 'recipe_version_no'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($tableName) {
                $versions = DB::table('food_recipes')
                    ->whereIn('id', collect($rows)->pluck('food_recipe_id')->filter()->unique())
                    ->pluck('version_no', 'id');

                foreach ($rows as $row) {
                    $version = $versions->get($row->food_recipe_id);
                    if ($version !== null) {
                        DB::table($tableName)
                            ->where('id', $row->id)
                            ->whereNull('recipe_version_no')
                            ->update(['recipe_version_no' => $version]);
                    }
                }
            });
    }

    private function dropIndexIfPresent(string $tableName, string $column): void
    {
        $index = $tableName . '_' . $column . '_index';
        if (!Schema::hasIndex($tableName, $index)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }

    public function up(): void
    {
        if (Schema::hasTable('food_recipes')) {
            Schema::table('food_recipes', function (Blueprint $table) {
                if (!Schema::hasColumn('food_recipes', 'yield_uom_id')) {
                    $table->uuid('yield_uom_id')->nullable()->after('yield_quantity')->index();
                }

                if (!Schema::hasColumn('food_recipes', 'notes')) {
                    $table->text('notes')->nullable()->after('overhead_cost');
                }
            });
            $this->backfillRecipeYieldUoms();
        }

        if (Schema::hasTable('food_recipe_ingredients')) {
            Schema::table('food_recipe_ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('food_recipe_ingredients', 'uom_id')) {
                    $table->uuid('uom_id')->nullable()->after('ingredient_product_id')->index();
                }

                if (!Schema::hasColumn('food_recipe_ingredients', 'line_type')) {
                    $table->string('line_type', 30)->default('ingredient')->after('uom_id')->index();
                }
            });
            $this->backfillComponentMetadata('food_recipe_ingredients');
        }

        if (Schema::hasTable('food_manufacturing_batch_lines')) {
            Schema::table('food_manufacturing_batch_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('food_manufacturing_batch_lines', 'uom_id')) {
                    $table->uuid('uom_id')->nullable()->after('ingredient_product_id')->index();
                }

                if (!Schema::hasColumn('food_manufacturing_batch_lines', 'line_type')) {
                    $table->string('line_type', 30)->default('ingredient')->after('uom_id')->index();
                }
            });
            $this->backfillComponentMetadata('food_manufacturing_batch_lines');
        }

        if (Schema::hasTable('food_manufacturing_batches')) {
            Schema::table('food_manufacturing_batches', function (Blueprint $table) {
                if (!Schema::hasColumn('food_manufacturing_batches', 'recipe_version_no')) {
                    $table->unsignedInteger('recipe_version_no')->nullable()->after('food_recipe_id')->index();
                }
            });
            $this->backfillRecipeVersions('food_manufacturing_batches');
        }

        if (Schema::hasTable('food_manufacturing_production_plans')) {
            Schema::table('food_manufacturing_production_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('food_manufacturing_production_plans', 'recipe_version_no')) {
                    $table->unsignedInteger('recipe_version_no')->nullable()->after('food_recipe_id')->index();
                }
                if (!Schema::hasColumn('food_manufacturing_production_plans', 'exploded_lines')) {
                    $table->json('exploded_lines')->nullable()->after('lines');
                }
                if (!Schema::hasColumn('food_manufacturing_production_plans', 'subrecipe_requirements')) {
                    $table->json('subrecipe_requirements')->nullable()->after('exploded_lines');
                }
            });
            $this->backfillRecipeVersions('food_manufacturing_production_plans');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('food_manufacturing_production_plans')) {
            $this->dropIndexIfPresent('food_manufacturing_production_plans', 'recipe_version_no');
            Schema::table('food_manufacturing_production_plans', function (Blueprint $table) {
                foreach (['subrecipe_requirements', 'exploded_lines', 'recipe_version_no'] as $column) {
                    if (Schema::hasColumn('food_manufacturing_production_plans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('food_manufacturing_batches')) {
            $this->dropIndexIfPresent('food_manufacturing_batches', 'recipe_version_no');
            Schema::table('food_manufacturing_batches', function (Blueprint $table) {
                if (Schema::hasColumn('food_manufacturing_batches', 'recipe_version_no')) {
                    $table->dropColumn('recipe_version_no');
                }
            });
        }

        if (Schema::hasTable('food_manufacturing_batch_lines')) {
            $this->dropIndexIfPresent('food_manufacturing_batch_lines', 'line_type');
            $this->dropIndexIfPresent('food_manufacturing_batch_lines', 'uom_id');
            Schema::table('food_manufacturing_batch_lines', function (Blueprint $table) {
                if (Schema::hasColumn('food_manufacturing_batch_lines', 'line_type')) {
                    $table->dropColumn('line_type');
                }
                if (Schema::hasColumn('food_manufacturing_batch_lines', 'uom_id')) {
                    $table->dropColumn('uom_id');
                }
            });
        }

        if (Schema::hasTable('food_recipe_ingredients')) {
            $this->dropIndexIfPresent('food_recipe_ingredients', 'line_type');
            $this->dropIndexIfPresent('food_recipe_ingredients', 'uom_id');
            Schema::table('food_recipe_ingredients', function (Blueprint $table) {
                if (Schema::hasColumn('food_recipe_ingredients', 'line_type')) {
                    $table->dropColumn('line_type');
                }
                if (Schema::hasColumn('food_recipe_ingredients', 'uom_id')) {
                    $table->dropColumn('uom_id');
                }
            });
        }

        if (Schema::hasTable('food_recipes')) {
            $this->dropIndexIfPresent('food_recipes', 'yield_uom_id');
            Schema::table('food_recipes', function (Blueprint $table) {
                if (Schema::hasColumn('food_recipes', 'notes')) {
                    $table->dropColumn('notes');
                }
                if (Schema::hasColumn('food_recipes', 'yield_uom_id')) {
                    $table->dropColumn('yield_uom_id');
                }
            });
        }
    }
};
