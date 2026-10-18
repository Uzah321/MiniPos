<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\BackStockMonthEndStockTakeController;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityStockTakeSnapshotCompatibilityTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for stock snapshot tests.');
        }

        $this->databaseReady = true;
        $this->createTables();
        $this->seedStock();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('product_costs');
            Schema::dropIfExists('product_stocks');
            Schema::dropIfExists('products');
            Schema::dropIfExists('uoms');
        }

        parent::tearDown();
    }

    public function test_front_and_back_snapshots_are_separate_and_support_legacy_cost_currency(): void
    {
        $front = $this->snapshot(
            new FrontStockDailyClosingController,
            'buildFrontStockSnapshotLines',
            ['company-a', 'branch-a', 'front']
        );
        $back = $this->snapshot(
            new BackStockMonthEndStockTakeController,
            'buildBackStockSnapshotLines',
            ['company-a', 'branch-a']
        );

        $this->assertCount(1, $front);
        $this->assertCount(1, $back);
        $this->assertSame(5.0, (float) $front[0]['expected_quantity']);
        $this->assertSame(11.0, (float) $back[0]['expected_quantity']);
        $this->assertSame('ZWL', $front[0]['currency_code']);
        $this->assertSame('ZWL', $back[0]['currency_code']);
        $this->assertSame(10.0, (float) $front[0]['unit_cost']);
        $this->assertSame(110.0, (float) $back[0]['expected_value']);
    }

    private function snapshot(object $controller, string $methodName, array $arguments): array
    {
        $method = new ReflectionMethod($controller, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($controller, $arguments);
    }

    private function createTables(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('base_uom_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('product_type');
            $table->boolean('is_active')->nullable();
            $table->softDeletes();
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->index();
            $table->string('product_id')->index();
            $table->string('stock_area');
            $table->decimal('quantity_on_hand', 18, 6)->default(0);
            $table->decimal('reserved_quantity', 18, 6)->default(0);
        });

        // Reproduce the deployed legacy schema that caused the reported error:
        // it has currency but not currency_code.
        Schema::create('product_costs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('product_id')->index();
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('last_cost', 18, 6)->default(0);
            $table->string('currency', 3)->nullable();
        });
    }

    private function seedStock(): void
    {
        DB::table('uoms')->insert([
            'id' => 'each',
            'code' => 'EACH',
            'name' => 'Each',
        ]);
        DB::table('products')->insert([
            'id' => 'ingredient-a',
            'company_id' => 'company-a',
            'base_uom_id' => 'each',
            'name' => 'Chicken Portion',
            'code' => 'RM-001',
            'sku' => 'RM-001',
            'barcode' => null,
            'product_type' => 'raw_material',
            'is_active' => true,
            'deleted_at' => null,
        ]);
        DB::table('product_stocks')->insert([
            [
                'id' => 'front-stock',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'ingredient-a',
                'stock_area' => 'front',
                'quantity_on_hand' => 5,
                'reserved_quantity' => 0,
            ],
            [
                'id' => 'back-stock',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'ingredient-a',
                'stock_area' => 'back',
                'quantity_on_hand' => 11,
                'reserved_quantity' => 0,
            ],
        ]);
        DB::table('product_costs')->insert([
            'id' => 'ingredient-cost',
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'product_id' => 'ingredient-a',
            'average_cost' => 10,
            'last_cost' => 9,
            'currency' => 'ZWL',
        ]);
    }
}
