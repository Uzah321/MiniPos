<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\PurchaseController;
use ReflectionMethod;
use Tests\TestCase;

class PurchaseControllerModeScopeTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for purchase mode-scope tests.');
        }

        $this->databaseReady = true;
        $this->createSchema();
        app()->instance('company', (object) ['id' => 'company-a']);
        app()->instance(BusinessModeBranchScopeService::class, $this->branchScope());
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance(BusinessModeBranchScopeService::class);

        if ($this->databaseReady) {
            foreach (array_reverse($this->tables()) as $table) Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_purchase_register_export_rows_are_limited_to_the_active_mode(): void
    {
        DB::table('company_branches')->insert([
            ['id' => 'hospitality-a', 'company_id' => 'company-a', 'branch_name' => 'Hospitality', 'branch_code' => 'H'],
            ['id' => 'retail-a', 'company_id' => 'company-a', 'branch_name' => 'Retail', 'branch_code' => 'R'],
        ]);
        DB::table('suppliers')->insert(['id' => 'supplier-a', 'company_id' => 'company-a', 'name' => 'Supplier']);
        DB::table('purchase_orders')->insert([
            $this->order('order-h', 'hospitality-a', 'PO-H'),
            $this->order('order-r', 'retail-a', 'PO-R'),
        ]);

        $rows = $this->method('purchaseRegisterRows')->invoke(
            new PurchaseController,
            Request::create('/purchases/reports/orders/export/xlsx', 'GET'),
            'orders'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('PO-H', $rows[0][0]);
        $this->assertSame('Hospitality', $rows[0][3]);
    }

    public function test_purchase_locations_and_products_cannot_be_selected_from_the_other_mode(): void
    {
        DB::table('inventory_warehouses')->insert([
            $this->warehouse('warehouse-h', 'hospitality-a'),
            $this->warehouse('warehouse-r', 'retail-a'),
            $this->warehouse('warehouse-global', null),
        ]);
        DB::table('inventory_locations')->insert([
            $this->location('location-h', 'warehouse-h', 'hospitality-a'),
            $this->location('location-r', 'warehouse-r', 'retail-a'),
            $this->location('location-global', 'warehouse-global', null),
        ]);
        DB::table('products')->insert([
            ['id' => 'product-h', 'company_id' => 'company-a', 'branch_id' => 'hospitality-a'],
            ['id' => 'product-r', 'company_id' => 'company-a', 'branch_id' => 'retail-a'],
            ['id' => 'product-global', 'company_id' => 'company-a', 'branch_id' => null],
        ]);

        $controller = new PurchaseController;
        $locations = $this->method('purchaseLineLocationsValid');
        $product = $this->method('purchaseProduct');

        $this->assertTrue($locations->invoke($controller, 'company-a', 'hospitality-a', [
            ['warehouse_id' => 'warehouse-h', 'location_id' => 'location-h'],
            ['warehouse_id' => 'warehouse-global', 'location_id' => 'location-global'],
        ]));
        $this->assertFalse($locations->invoke($controller, 'company-a', 'hospitality-a', [
            ['warehouse_id' => 'warehouse-r', 'location_id' => 'location-r'],
        ]));
        $this->assertNotNull($product->invoke($controller, 'company-a', 'product-h', ['hospitality-a']));
        $this->assertNotNull($product->invoke($controller, 'company-a', 'product-global', ['hospitality-a']));
        $this->assertNull($product->invoke($controller, 'company-a', 'product-r', ['hospitality-a']));
    }

    public function test_purchase_lists_details_writes_and_exports_are_wired_to_the_mode_scope(): void
    {
        $source = file_get_contents(base_path('Modules/Product/Http/Controllers/PurchaseController.php'));

        foreach ([
            'overview', 'orders', 'storeOrder', 'updateOrder', 'receiveOrder', 'storeReturn',
            'storeSupplierInvoice', 'receipts', 'returns', 'invoices', 'supplierPayments',
            'storeSupplierPayment', 'reverseSupplierPayment', 'purchaseRegisterRows',
        ] as $method) {
            $body = $this->methodSource($source, $method);
            $this->assertStringContainsString(
                $method === 'updateOrder' ? 'BusinessModeBranchScopeService' : 'purchaseBranchScope',
                $body,
                "{$method} must resolve the active business-mode branch scope"
            );
        }

        foreach (['loadOrder', 'loadReceipt', 'loadReturn', 'loadInvoice'] as $method) {
            $body = $this->methodSource($source, $method);
            $this->assertStringContainsString('purchaseBranchScope', $body);
            $this->assertStringContainsString("whereIn('branch_id', \$branchIds)", $body);
        }
    }

    private function method(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(PurchaseController::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function methodSource(string $source, string $method): string
    {
        $reflection = $this->method($method);
        $lines = preg_split('/\R/', $source);

        return implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    private function branchScope(): BusinessModeBranchScopeService
    {
        return new class extends BusinessModeBranchScopeService {
            public function __construct() {}

            public function resolve(Request $request, string $companyId, array $requestedBranchIds = []): array
            {
                return [
                    'business_mode' => 'hospitality',
                    'context_branch_id' => 'hospitality-a',
                    'branch_ids' => $requestedBranchIds !== [] ? $requestedBranchIds : ['hospitality-a'],
                    'accessible_branch_ids' => ['hospitality-a', 'retail-a'],
                ];
            }
        };
    }

    private function order(string $id, string $branchId, string $number): array
    {
        return [
            'id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId,
            'supplier_id' => 'supplier-a', 'po_number' => $number, 'order_date' => '2026-10-02',
            'currency_code' => 'USD', 'subtotal' => 10, 'tax_total' => 1, 'grand_total' => 11,
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function warehouse(string $id, ?string $branchId): array
    {
        return ['id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId, 'deleted_at' => null];
    }

    private function location(string $id, string $warehouseId, ?string $branchId): array
    {
        return ['id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'deleted_at' => null];
    }

    private function createSchema(): void
    {
        foreach (array_reverse($this->tables()) as $table) Schema::dropIfExists($table);

        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_name')->nullable();
            $table->string('branch_code')->nullable();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('supplier_id')->nullable();
            $table->string('po_number');
            $table->date('order_date');
            $table->string('currency_code');
            $table->decimal('subtotal')->default(0);
            $table->decimal('tax_total')->default(0);
            $table->decimal('grand_total')->default(0);
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->softDeletes();
        });
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('warehouse_id');
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->softDeletes();
        });
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        return ['company_branches', 'suppliers', 'purchase_orders', 'inventory_warehouses', 'inventory_locations', 'products'];
    }
}
