<?php

namespace Tests\Unit;

use App\Support\FiltersTableColumns;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoodManufacturingLegacySchemaCompatibilityTest extends TestCase
{
    public function test_pending_bom_columns_are_removed_from_legacy_schema_writes(): void
    {
        $legacyColumns = [
            'food_recipes' => ['company_id', 'name', 'yield_quantity'],
            'food_recipe_ingredients' => ['company_id', 'food_recipe_id', 'ingredient_product_id', 'quantity'],
            'food_manufacturing_batches' => ['company_id', 'branch_id', 'food_recipe_id'],
            'food_manufacturing_batch_lines' => ['company_id', 'food_manufacturing_batch_id', 'ingredient_product_id'],
            'food_manufacturing_production_plans' => ['company_id', 'branch_id', 'food_recipe_id', 'lines'],
        ];

        Schema::shouldReceive('getColumnListing')
            ->andReturnUsing(fn (string $table) => $legacyColumns[$table]);

        $filter = $this->filterHarness();

        $this->assertSame(
            ['company_id' => 'company-a', 'name' => 'Burger', 'yield_quantity' => 1],
            $filter->apply('food_recipes', [
                'company_id' => 'company-a',
                'name' => 'Burger',
                'yield_quantity' => 1,
                'yield_uom_id' => 'uom-a',
                'notes' => 'new metadata',
            ])
        );
        $this->assertSame(
            ['company_id' => 'company-a', 'food_recipe_id' => 'recipe-a', 'ingredient_product_id' => 'product-a', 'quantity' => 2],
            $filter->apply('food_recipe_ingredients', [
                'company_id' => 'company-a',
                'food_recipe_id' => 'recipe-a',
                'ingredient_product_id' => 'product-a',
                'uom_id' => 'uom-a',
                'line_type' => 'ingredient',
                'quantity' => 2,
            ])
        );
        $this->assertArrayNotHasKey('recipe_version_no', $filter->apply(
            'food_manufacturing_batches',
            ['company_id' => 'company-a', 'branch_id' => 'branch-a', 'food_recipe_id' => 'recipe-a', 'recipe_version_no' => 3]
        ));
        $this->assertSame(
            ['company_id' => 'company-a', 'food_manufacturing_batch_id' => 'batch-a', 'ingredient_product_id' => 'product-a'],
            $filter->apply('food_manufacturing_batch_lines', [
                'company_id' => 'company-a',
                'food_manufacturing_batch_id' => 'batch-a',
                'ingredient_product_id' => 'product-a',
                'uom_id' => 'uom-a',
                'line_type' => 'packaging',
            ])
        );
        $this->assertSame(
            ['company_id' => 'company-a', 'branch_id' => 'branch-a', 'food_recipe_id' => 'recipe-a', 'lines' => []],
            $filter->apply('food_manufacturing_production_plans', [
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'food_recipe_id' => 'recipe-a',
                'recipe_version_no' => 3,
                'lines' => [],
                'exploded_lines' => [],
                'subrecipe_requirements' => [],
            ])
        );
    }

    public function test_bom_metadata_is_retained_after_the_migration_columns_exist(): void
    {
        $migratedColumns = [
            'food_recipes' => ['yield_uom_id', 'notes'],
            'food_recipe_ingredients' => ['uom_id', 'line_type'],
            'food_manufacturing_batches' => ['recipe_version_no'],
            'food_manufacturing_batch_lines' => ['uom_id', 'line_type'],
            'food_manufacturing_production_plans' => ['recipe_version_no', 'exploded_lines', 'subrecipe_requirements'],
        ];

        Schema::shouldReceive('getColumnListing')
            ->andReturnUsing(fn (string $table) => $migratedColumns[$table]);

        $filter = $this->filterHarness();
        foreach ($migratedColumns as $table => $columns) {
            $payload = array_fill_keys($columns, 'kept');
            $this->assertSame($payload, $filter->apply($table, $payload));
        }
    }

    public function test_every_pending_migration_write_and_optional_relation_is_schema_guarded(): void
    {
        $recipeController = file_get_contents(base_path('Modules/FoodManufacturing/Http/Controllers/FoodRecipeController.php'));
        $manufacturingController = file_get_contents(base_path('Modules/FoodManufacturing/Http/Controllers/FoodManufacturingController.php'));
        $service = file_get_contents(base_path('app/Services/FoodManufacturing/FoodManufacturingService.php'));

        $this->assertGreaterThanOrEqual(2, substr_count(
            $recipeController,
            "onlyExistingTableColumns('food_recipes'"
        ));
        $this->assertGreaterThanOrEqual(2, substr_count(
            $recipeController,
            "onlyExistingTableColumns('food_recipe_ingredients'"
        ));
        $this->assertStringContainsString("tableHasColumn('food_recipes', 'yield_uom_id')", $recipeController);
        $this->assertStringContainsString("tableHasColumn('food_recipe_ingredients', 'uom_id')", $recipeController);

        $this->assertStringContainsString(
            "onlyExistingTableColumns('food_manufacturing_batches'",
            $service
        );
        $this->assertStringContainsString(
            "onlyExistingTableColumns('food_manufacturing_batch_lines'",
            $service
        );
        $this->assertStringContainsString(
            "tableHasColumn('food_recipe_ingredients', 'uom_id')",
            $service
        );

        $this->assertStringContainsString(
            "onlyExistingTableColumns('food_manufacturing_production_plans'",
            $manufacturingController
        );
        $this->assertStringContainsString(
            "tableHasColumn('food_manufacturing_batch_lines', 'uom_id')",
            $manufacturingController
        );
        $this->assertStringContainsString(
            "tableHasColumn('food_recipes', 'yield_uom_id')",
            $manufacturingController
        );

        $migration = file_get_contents(base_path(
            'Modules/FoodManufacturing/database/migrations/2026_10_02_000001_add_bom_component_metadata_to_food_manufacturing_tables.php'
        ));
        $this->assertStringContainsString("'packaging' => 'packaging'", $migration);
        $this->assertStringContainsString("'consumable', 'consumables' => 'consumable'", $migration);
        $this->assertStringContainsString("'semi_finished', 'semi_finished_good' => 'sub_recipe'", $migration);
        $this->assertStringContainsString("backfillComponentMetadata('food_recipe_ingredients')", $migration);
        $this->assertStringContainsString("backfillComponentMetadata('food_manufacturing_batch_lines')", $migration);
        $this->assertStringContainsString("backfillRecipeVersions('food_manufacturing_batches')", $migration);
        $this->assertStringContainsString("backfillRecipeVersions('food_manufacturing_production_plans')", $migration);
    }

    private function filterHarness(): object
    {
        return new class {
            use FiltersTableColumns;

            /** @param array<string, mixed> $attributes */
            public function apply(string $table, array $attributes): array
            {
                return $this->onlyExistingTableColumns($table, $attributes);
            }
        };
    }
}
