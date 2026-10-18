<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HospitalityMigrationPortabilityTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for migration portability tests.');
        }

        $this->databaseReady = true;
        $this->createLegacyTables();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            foreach ([
                'food_manufacturing_production_plans',
                'food_manufacturing_batches',
                'food_manufacturing_batch_lines',
                'food_recipe_ingredients',
                'food_recipes',
                'stock_movements',
                'products',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_hospitality_migrations_upgrade_and_rollback_on_sqlite(): void
    {
        DB::table('products')->insert([
            'id' => 'product-a',
            'default_issue_destination' => 'operating_expense',
        ]);

        $bomMigration = require base_path(
            'Modules/FoodManufacturing/database/migrations/2026_10_02_000001_add_bom_component_metadata_to_food_manufacturing_tables.php'
        );
        $classificationMigration = require base_path(
            'Modules/Product/database/migrations/2026_10_02_120000_harden_hospitality_stock_classification.php'
        );

        $bomMigration->up();
        $classificationMigration->up();

        $this->assertTrue(Schema::hasColumns('food_recipes', ['yield_uom_id', 'notes']));
        $this->assertTrue(Schema::hasColumns('food_recipe_ingredients', ['uom_id', 'line_type']));
        $this->assertTrue(Schema::hasColumns('food_manufacturing_batch_lines', ['uom_id', 'line_type']));
        $this->assertTrue(Schema::hasColumn('food_manufacturing_batches', 'recipe_version_no'));
        $this->assertTrue(Schema::hasColumns('food_manufacturing_production_plans', [
            'recipe_version_no',
            'exploded_lines',
            'subrecipe_requirements',
        ]));
        $this->assertSame(
            'expense',
            DB::table('products')->where('id', 'product-a')->value('default_issue_destination')
        );

        // SQLite deliberately skips the MySQL-only ENUM alteration while still
        // applying the portable data cleanup above.
        $this->assertSame('varchar', Schema::getColumnType('stock_movements', 'movement_type'));

        $bomMigration->down();

        $this->assertFalse(Schema::hasColumn('food_recipes', 'yield_uom_id'));
        $this->assertFalse(Schema::hasColumn('food_recipe_ingredients', 'line_type'));
        $this->assertFalse(Schema::hasColumn('food_manufacturing_production_plans', 'exploded_lines'));
    }

    private function createLegacyTables(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('default_issue_destination')->nullable();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('movement_type')->default('sale');
        });

        Schema::create('food_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->decimal('overhead_cost', 18, 6)->default(0);
        });

        Schema::create('food_recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ingredient_product_id');
        });

        Schema::create('food_manufacturing_batch_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ingredient_product_id');
        });

        Schema::create('food_manufacturing_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('food_recipe_id');
        });

        Schema::create('food_manufacturing_production_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('food_recipe_id');
            $table->json('lines')->nullable();
        });
    }
}
