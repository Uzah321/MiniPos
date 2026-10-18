<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use App\Services\Sales\SalesCostingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Product\Models\ProductCost;
use Tests\TestCase;

class ProductCostCurrencyCompatibilityTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for product-cost compatibility tests.');
        }

        $this->databaseReady = true;
        $this->createRecipeTables();
        $this->createLegacyProductCostsTable();
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

    public function test_retail_and_hospitality_costing_work_with_legacy_currency_column(): void
    {
        $service = $this->service();

        $retailCurrency = $service->costCurrencyExpression(false, "'USD'");

        $this->assertStringContainsString('product_costs.currency', $retailCurrency);
        $this->assertStringNotContainsString('product_costs.currency_code', $retailCurrency);
        $this->assertSame(
            'ZWL',
            DB::table('product_costs')->selectRaw(
                $service->productCostCurrencyExpression().' as resolved_currency'
            )->value('resolved_currency')
        );

        $recipeCost = $service->activeRecipeUnitCosts('company-a', 'branch-a')->firstOrFail();

        $this->assertSame('ZWL', $recipeCost->cost_currency_code);
        $this->assertSame(11.0, (float) $recipeCost->recipe_unit_cost);
    }

    public function test_repair_migration_adds_and_backfills_currency_code_without_removing_legacy_column(): void
    {
        $migration = require base_path(
            'Modules/Product/database/migrations/2026_10_02_130000_add_currency_code_to_legacy_product_costs.php'
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('product_costs', 'currency'));
        $this->assertTrue(Schema::hasColumn('product_costs', 'currency_code'));
        $this->assertSame('ZWL', DB::table('product_costs')->value('currency_code'));

        $expression = $this->service()->productCostCurrencyExpression();
        $this->assertSame('COALESCE(product_costs.currency_code, product_costs.currency)', $expression);
        $this->assertSame(
            'ZWL',
            DB::table('product_costs')->selectRaw("{$expression} as resolved_currency")->value('resolved_currency')
        );

        // A second run is safe during retries or partially completed deployments.
        $migration->up();
        $this->assertSame('ZWL', DB::table('product_costs')->value('currency_code'));
    }

    public function test_product_cost_model_writes_currency_through_legacy_and_canonical_names(): void
    {
        $cost = ProductCost::query()->firstOrFail();
        $cost->currency_code = 'USD';
        $cost->save();

        $this->assertSame('USD', DB::table('product_costs')->value('currency'));
        $this->assertSame('USD', $cost->fresh()->currency_code);

        $migration = require base_path(
            'Modules/Product/database/migrations/2026_10_02_130000_add_currency_code_to_legacy_product_costs.php'
        );
        $migration->up();

        $cost = $cost->fresh();
        $cost->currency = 'ZAR';
        $cost->save();

        $stored = DB::table('product_costs')->first();
        $this->assertSame('ZAR', $stored->currency);
        $this->assertSame('ZAR', $stored->currency_code);
    }

    public function test_reports_fall_back_to_sale_currency_when_product_cost_has_no_currency_column(): void
    {
        Schema::table('product_costs', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        $service = $this->service();

        $this->assertNull($service->productCostCurrencyExpression());
        $this->assertSame(
            'COALESCE(retail_line_costs.cost_currency_code, sales_transactions.base_currency_code)',
            $service->costCurrencyExpression(false)
        );

        $recipeCost = $service->activeRecipeUnitCosts('company-a', 'branch-a')->firstOrFail();
        $this->assertSame('USD', $recipeCost->cost_currency_code);
    }

    public function test_queries_never_hard_code_the_legacy_currency_column(): void
    {
        // Fresh databases only have product_costs.currency_code. A query that
        // names the legacy column directly fails with "Unknown column", as
        // barcode search, stock wastage and stock take snapshots once did.
        $offenders = [];
        foreach (['app', 'Modules'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root)));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (! $file->isFile() || ! str_ends_with($path, '.php')
                    || preg_match('#[\\\\/](database|tests)[\\\\/]#', $path)) {
                    continue;
                }
                // Commented-out code never runs.
                $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($path));
                if (preg_match('/\b(?:costs|pc|product_costs|history_costs)\.currency\b(?!_code)/', $source) !== 1) {
                    continue;
                }
                $guarded = str_contains($source, "Schema::hasColumn('product_costs', 'currency')")
                    || str_ends_with(str_replace('\\', '/', $path), 'app/Services/Sales/SalesCostingService.php');
                if (! $guarded) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Use SalesCostingService::productCostCurrencyExpression() instead of naming product_costs.currency directly.'
        );
    }

    private function service(): SalesCostingService
    {
        return new SalesCostingService(Mockery::mock(CompanySettingService::class));
    }

    private function createRecipeTables(): void
    {
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

        DB::table('company_branches')->insert([
            'id' => 'branch-a',
            'company_id' => 'company-a',
        ]);

        DB::table('food_recipes')->insert([
            'id' => 'recipe-a',
            'company_id' => 'company-a',
            'menu_product_id' => 'menu-a',
            'recipe_type' => 'menu',
            'version_no' => 1,
            'yield_quantity' => 2,
            'currency' => 'USD',
            'packaging_cost' => 1,
            'labour_cost' => 1,
            'overhead_cost' => 0,
            'is_active' => true,
        ]);

        DB::table('food_recipe_ingredients')->insert([
            'id' => 'ingredient-line-a',
            'company_id' => 'company-a',
            'food_recipe_id' => 'recipe-a',
            'ingredient_product_id' => 'ingredient-a',
            'quantity' => 2,
            'wastage_percent' => 0,
        ]);
    }

    private function createLegacyProductCostsTable(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('product_id')->index();
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('last_cost', 18, 6)->default(0);
            $table->string('currency', 3)->nullable();
            $table->timestamps();
        });

        DB::table('product_costs')->insert([
            'id' => 'cost-a',
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'product_id' => 'ingredient-a',
            'average_cost' => 10,
            'last_cost' => 9,
            'currency' => 'ZWL',
        ]);
    }
}
