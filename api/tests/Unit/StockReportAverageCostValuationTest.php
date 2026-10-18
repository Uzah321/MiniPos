<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\StockReportController;
use ReflectionMethod;
use Tests\TestCase;

class StockReportAverageCostValuationTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for stock valuation tests.');
        }

        $this->databaseReady = true;
        $this->createTables();
        $this->seedInventory();

        app()->instance('company', (object) ['id' => 'company-a']);
        app()->instance(BusinessModeBranchScopeService::class, new class extends BusinessModeBranchScopeService {
            public function __construct() {}

            public function resolve(Request $request, string $companyId, array $requestedBranchIds = []): array
            {
                return [
                    'business_mode' => 'retail',
                    'context_branch_id' => 'branch-a',
                    'branch_ids' => ['branch-a'],
                    'accessible_branch_ids' => ['branch-a'],
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance(BusinessModeBranchScopeService::class);

        if ($this->databaseReady) {
            foreach ([
                'product_prices',
                'stock_movements',
                'product_costs',
                'product_stocks',
                'products',
                'product_categories',
                'suppliers',
                'inventory_warehouses',
                'pos_users',
                'company_branches',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_every_inventory_balance_uses_quantity_times_average_cost_and_never_selling_price(): void
    {
        $controller = new StockReportController;
        $request = $this->request();

        $company = $this->payload($controller->companyBalance(clone $request));
        $branch = $this->payload($controller->branchBalance(clone $request));
        $balance = $this->payload($controller->balance(clone $request));
        $asOf = $this->payload($controller->asOfBalance($this->request(['as_of' => '2026-10-02'])));
        $valuation = $this->payload($controller->valuation(clone $request));
        $category = $this->payload($controller->categorySummary(clone $request));
        $supplier = $this->payload($controller->supplierSummary(clone $request));
        $dashboard = $this->payload($controller->dashboard(clone $request));

        foreach ([$company, $branch, $balance, $asOf, $valuation, $category, $supplier, $dashboard] as $payload) {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('selling_price', $encoded);
            $this->assertStringNotContainsString('selling_value', $encoded);
            $this->assertStringNotContainsString('potential_margin', $encoded);
        }

        $this->assertSame(70.0, (float) $company['summary']['stock_value']);
        $this->assertSame(70.0, (float) $branch['summary']['stock_value']);
        $this->assertSame(70.0, (float) $balance['summary']['stock_value']);
        $this->assertSame(70.0, (float) $asOf['summary']['stock_value']);
        $this->assertSame(70.0, (float) $valuation['summary']['stock_value']);
        $this->assertSame(70.0, (float) $dashboard['data']['summary']['stock_value']);
        $this->assertSame(70.0, (float) $category['data']['data'][0]['stock_value']);
        $this->assertSame(70.0, (float) $supplier['data']['data'][0]['stock_value']);
        $this->assertSame(70.0, (float) $dashboard['data']['stock_by_branch'][0]['stock_value']);
        $this->assertSame(70.0, (float) $dashboard['data']['stock_by_category'][0]['stock_value']);

        $companyRows = collect($company['data']['data'])->keyBy('product_id');
        $this->assertSame(10.0, (float) $companyRows['product-a']['average_cost']);
        $this->assertSame(70.0, (float) $companyRows['product-a']['stock_value']);
        $this->assertSame(0.0, (float) $companyRows['product-b']['average_cost']);
        $this->assertSame(0.0, (float) $companyRows['product-b']['stock_value']);

        $balanceRows = collect($balance['data']['data'])->keyBy('product_id');
        $this->assertSame(7.0, (float) $balanceRows['product-a']['quantity_on_hand']);
        $this->assertSame(70.0, (float) $balanceRows['product-a']['stock_value']);
        $this->assertSame(2.0, (float) $balanceRows['product-b']['quantity_on_hand']);
        $this->assertSame(0.0, (float) $balanceRows['product-b']['stock_value']);

        $asOfRows = collect($asOf['data']['data'])->keyBy('product_id');
        $this->assertSame(70.0, (float) $asOfRows['product-a']['stock_value']);
        $this->assertSame(0.0, (float) $asOfRows['product-b']['stock_value']);
    }

    public function test_legacy_cost_column_is_used_only_when_average_cost_schema_column_is_absent(): void
    {
        $method = new ReflectionMethod(StockReportController::class, 'costColumn');
        $method->setAccessible(true);
        $controller = new StockReportController;

        $this->assertSame('COALESCE(costs.average_cost, 0)', $method->invoke($controller, 'average_cost'));

        Schema::drop('product_costs');
        Schema::create('product_costs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->decimal('last_cost', 18, 4)->nullable();
        });

        $this->assertSame('COALESCE(costs.last_cost, 0)', $method->invoke($controller, 'average_cost'));
    }

    public function test_adjustments_use_average_cost_while_receipts_keep_recorded_transaction_cost(): void
    {
        $controller = new StockReportController;

        $adjustments = $this->payload($controller->adjustments($this->request()));
        $rows = collect($adjustments['data']['data'])->keyBy('movement_type');

        $this->assertSame(10.0, (float) $rows['adjustment_in']['average_cost']);
        $this->assertSame(20.0, (float) $rows['adjustment_in']['cost_value']);
        $this->assertSame(10.0, (float) $rows['adjustment_out']['cost_value']);
        $this->assertSame(30.0, (float) $adjustments['summary']['cost_value']);
        $this->assertSame(20.0, (float) $adjustments['summary']['increased_cost_value']);
        $this->assertSame(10.0, (float) $adjustments['summary']['decreased_cost_value']);

        $adjustmentJson = json_encode($adjustments, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('selling_', $adjustmentJson);
        $this->assertStringNotContainsString('margin_value', $adjustmentJson);

        $received = $this->payload($controller->received($this->request()));
        $receivedRows = collect($received['data']['data']);

        $this->assertSame(2799.0, (float) $received['summary']['cost_value']);
        $this->assertSame(1000.0, (float) $receivedRows->firstWhere('id', 'movement-opening-a')['total_cost']);
        $this->assertArrayNotHasKey('cost_value', $receivedRows->firstWhere('id', 'movement-opening-a'));
    }

    private function request(array $query = []): Request
    {
        return Request::create('/api/pos/reports/stock', 'GET', [
            'per_page' => 50,
            ...$query,
        ]);
    }

    private function payload($response): array
    {
        return (array) $response->getData(true);
    }

    private function createTables(): void
    {
        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('branch_name');
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('category_id')->nullable();
            $table->string('supplier_id')->nullable();
            $table->decimal('reorder_level', 18, 4)->default(0);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->index();
            $table->string('product_id')->index();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0);
            $table->decimal('available_quantity', 18, 4)->default(0);
        });

        Schema::create('product_costs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->index();
            $table->string('product_id')->index();
            $table->decimal('average_cost', 18, 4)->nullable();
            $table->decimal('last_cost', 18, 4)->nullable();
            $table->string('currency_code', 3)->nullable();
        });

        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
        });

        Schema::create('pos_users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->index();
            $table->string('warehouse_id')->nullable();
            $table->string('till_id')->nullable();
            $table->string('shift_session_id')->nullable();
            $table->string('product_id')->index();
            $table->string('movement_type');
            $table->decimal('quantity_in', 18, 4)->default(0);
            $table->decimal('quantity_out', 18, 4)->default(0);
            $table->decimal('balance_after', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_cost', 18, 4)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('metadata')->nullable();
            $table->string('created_by')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('product_id')->index();
            $table->decimal('price', 18, 4);
        });
    }

    private function seedInventory(): void
    {
        DB::table('company_branches')->insert(['id' => 'branch-a', 'branch_name' => 'Main Branch']);
        DB::table('product_categories')->insert(['id' => 'category-a', 'name' => 'Category A']);
        DB::table('suppliers')->insert(['id' => 'supplier-a', 'name' => 'Supplier A']);
        DB::table('inventory_warehouses')->insert(['id' => 'warehouse-a', 'name' => 'Main Warehouse']);
        DB::table('pos_users')->insert(['id' => 'user-a', 'name' => 'Stock User', 'username' => 'stock.user']);

        DB::table('products')->insert([
            [
                'id' => 'product-a',
                'name' => 'Average Cost Product',
                'code' => 'AVG-1',
                'sku' => 'AVG-1',
                'barcode' => '1001',
                'category_id' => 'category-a',
                'supplier_id' => 'supplier-a',
                'reorder_level' => 2,
            ],
            [
                'id' => 'product-b',
                'name' => 'Fallback Cost Product',
                'code' => 'AVG-2',
                'sku' => 'AVG-2',
                'barcode' => '1002',
                'category_id' => 'category-a',
                'supplier_id' => 'supplier-a',
                'reorder_level' => 1,
            ],
        ]);

        DB::table('product_stocks')->insert([
            [
                'id' => 'stock-a',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'product-a',
                'quantity_on_hand' => 7,
                'reserved_quantity' => 1,
                'available_quantity' => 6,
            ],
            [
                'id' => 'stock-b',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'product-b',
                'quantity_on_hand' => 2,
                'reserved_quantity' => 0,
                'available_quantity' => 2,
            ],
        ]);

        DB::table('product_costs')->insert([
            [
                'id' => 'cost-a',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'product-a',
                'average_cost' => 10,
                'last_cost' => 8,
                'currency_code' => 'USD',
            ],
            [
                'id' => 'cost-b',
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'product-b',
                'average_cost' => null,
                'last_cost' => 4,
                'currency_code' => 'USD',
            ],
        ]);

        DB::table('stock_movements')->insert([
            $this->movement('movement-opening-a', 'product-a', 'opening', 10, 0, 10, 1000),
            $this->movement('movement-sale-a', 'product-a', 'sale', 0, 4, 6, 900),
            $this->movement('movement-adjustment-in-a', 'product-a', 'adjustment_in', 2, 0, 8, 999),
            $this->movement('movement-adjustment-out-a', 'product-a', 'adjustment_out', 0, 1, 7, 888),
            $this->movement('movement-opening-b', 'product-b', 'opening', 2, 0, 2, 800),
        ]);

        DB::table('product_prices')->insert([
            'id' => 'price-a',
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'product_id' => 'product-a',
            'price' => 500,
        ]);
    }

    private function movement(
        string $id,
        string $productId,
        string $type,
        float $quantityIn,
        float $quantityOut,
        float $balanceAfter,
        float $recordedTotalCost
    ): array {
        return [
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'warehouse_id' => 'warehouse-a',
            'till_id' => null,
            'shift_session_id' => null,
            'product_id' => $productId,
            'movement_type' => $type,
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'balance_after' => $balanceAfter,
            'unit_cost' => $recordedTotalCost,
            'total_cost' => $recordedTotalCost,
            'currency_code' => 'USD',
            'currency' => 'USD',
            'reference_type' => 'test',
            'reference_id' => $id,
            'notes' => 'Deliberately different from average cost',
            'metadata' => null,
            'created_by' => 'user-a',
            'created_at' => '2026-10-01 08:00:00',
        ];
    }
}
