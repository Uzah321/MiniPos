<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use App\Services\Sales\SalesCostingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SalesCostingBranchFallbackTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for branch recipe costing tests.');
        }

        $this->databaseReady = true;

        Schema::create('company_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
        });

        Schema::create('food_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('menu_product_id')->index();
            $table->string('recipe_type');
            $table->unsignedInteger('version_no');
            $table->decimal('yield_quantity', 18, 6);
            $table->string('currency', 3)->default('USD');
            $table->decimal('packaging_cost', 18, 6)->default(0);
            $table->decimal('labour_cost', 18, 6)->default(0);
            $table->decimal('overhead_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('cost_per_unit', 18, 6)->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('food_recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('food_recipe_id')->index();
            $table->uuid('ingredient_product_id')->index();
            $table->decimal('quantity', 18, 6);
            $table->decimal('wastage_percent', 8, 4)->default(0);
        });

        Schema::create('product_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('product_id')->index();
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('last_cost', 18, 6)->default(0);
            $table->string('currency_code', 3)->nullable();
            $table->unique(['company_id', 'branch_id', 'product_id']);
        });

        DB::table('company_branches')->insert([
            ['id' => 'branch-a', 'company_id' => 'company-a'],
            ['id' => 'branch-b', 'company_id' => 'company-a'],
            ['id' => 'branch-foreign', 'company_id' => 'company-b'],
        ]);

        DB::table('food_recipes')->insert([
            [
                'id' => 'recipe-old',
                'company_id' => 'company-a',
                'menu_product_id' => 'menu-a',
                'recipe_type' => 'menu',
                'version_no' => 1,
                'yield_quantity' => 2,
                'currency' => 'USD',
                'packaging_cost' => 1,
                'labour_cost' => 2,
                'overhead_cost' => 3,
                'total_cost' => 1998,
                'cost_per_unit' => 999,
                'is_active' => true,
            ],
            [
                'id' => 'recipe-current',
                'company_id' => 'company-a',
                'menu_product_id' => 'menu-a',
                'recipe_type' => 'menu',
                'version_no' => 2,
                'yield_quantity' => 2,
                'currency' => 'USD',
                'packaging_cost' => 1,
                'labour_cost' => 2,
                'overhead_cost' => 3,
                // Deliberately wrong snapshot: the fallback must recost by branch.
                'total_cost' => 1776,
                'cost_per_unit' => 888,
                'is_active' => true,
            ],
        ]);

        DB::table('food_recipe_ingredients')->insert([
            'id' => 'ingredient-line',
            'company_id' => 'company-a',
            'food_recipe_id' => 'recipe-current',
            'ingredient_product_id' => 'ingredient-a',
            'quantity' => 4,
            'wastage_percent' => 25,
        ]);

        DB::table('product_costs')->insert([
            [
                'id' => 'cost-a',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'ingredient-a',
                'average_cost' => 10,
                'last_cost' => 9,
                'currency_code' => 'USD',
            ],
            [
                'id' => 'cost-b',
                'company_id' => 'company-a',
                'branch_id' => 'branch-b',
                'product_id' => 'ingredient-a',
                'average_cost' => 20,
                'last_cost' => 19,
                'currency_code' => 'USD',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('product_costs');
            Schema::dropIfExists('food_recipe_ingredients');
            Schema::dropIfExists('food_recipes');
            Schema::dropIfExists('company_branches');
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_legacy_recipe_fallback_returns_one_correct_cost_per_company_branch(): void
    {
        $service = new SalesCostingService(Mockery::mock(CompanySettingService::class));

        $rows = $service->activeRecipeUnitCosts('company-a')
            ->orderBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $this->assertCount(2, $rows);
        $this->assertSame(28.0, (float) $rows['branch-a']->recipe_unit_cost);
        $this->assertSame(53.0, (float) $rows['branch-b']->recipe_unit_cost);
        $this->assertSame('recipe-current', $rows['branch-a']->recipe_id);
        $this->assertSame(2, (int) $rows['branch-a']->recipe_version_no);
        $this->assertFalse($rows->has('branch-foreign'));

        $branchOnly = $service->activeRecipeUnitCosts('company-a', 'branch-b')->get();

        $this->assertCount(1, $branchOnly);
        $this->assertSame('branch-b', $branchOnly->first()->branch_id);
        $this->assertSame(53.0, (float) $branchOnly->first()->recipe_unit_cost);
    }
}
