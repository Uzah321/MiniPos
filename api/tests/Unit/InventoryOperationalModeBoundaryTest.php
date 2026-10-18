<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Inventory\InventoryBatchSynchronizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use Modules\Product\Http\Controllers\StockTransferController;
use Tests\TestCase;

class InventoryOperationalModeBoundaryTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for inventory mode-boundary tests.');
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
            foreach (array_reverse($this->tables()) as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_warehouse_and_batch_views_keep_global_resources_but_hide_opposite_mode_records(): void
    {
        DB::table('company_branches')->insert([
            ['id' => 'hospitality-a', 'branch_name' => 'Hospitality', 'branch_code' => 'H'],
            ['id' => 'retail-a', 'branch_name' => 'Retail', 'branch_code' => 'R'],
        ]);
        DB::table('inventory_warehouses')->insert([
            $this->warehouse('warehouse-h', 'hospitality-a', 'Hospitality Warehouse'),
            $this->warehouse('warehouse-r', 'retail-a', 'Retail Warehouse'),
            $this->warehouse('warehouse-global', null, 'Company Warehouse'),
        ]);
        DB::table('products')->insert(['id' => 'product-a', 'name' => 'Flour', 'code' => 'FLOUR']);
        DB::table('inventory_batches')->insert([
            $this->batch('batch-h', 'hospitality-a', 'warehouse-h'),
            $this->batch('batch-r', 'retail-a', 'warehouse-r'),
        ]);

        $controller = new EnterpriseInventoryController;
        $request = Request::create('/inventory/warehouses', 'GET');

        $warehouseIds = collect($controller->warehouses($request)->getData(true)['data'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $batchIds = collect($controller->batches($request)->getData(true)['data']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame(['warehouse-global', 'warehouse-h'], $warehouseIds);
        $this->assertSame(['batch-h'], $batchIds);
    }

    public function test_alert_refresh_only_resolves_alerts_in_the_active_mode(): void
    {
        DB::table('inventory_alerts')->insert([
            $this->alert('alert-h', 'hospitality-a'),
            $this->alert('alert-r', 'retail-a'),
        ]);

        (new EnterpriseInventoryController)->refreshAlerts(
            Request::create('/inventory/alerts/refresh', 'POST')
        );

        $this->assertSame('resolved', DB::table('inventory_alerts')->where('id', 'alert-h')->value('status'));
        $this->assertSame('open', DB::table('inventory_alerts')->where('id', 'alert-r')->value('status'));
    }

    public function test_batch_reconciliation_receives_only_active_mode_branches(): void
    {
        $synchronizer = new class extends InventoryBatchSynchronizer {
            public array $receivedBranchIds = [];

            public function syncCompany(string $companyId, ?string $createdBy = null, array $branchIds = []): int
            {
                $this->receivedBranchIds = $branchIds;

                return 0;
            }
        };

        (new EnterpriseInventoryController)->reconcileBatches(
            Request::create('/inventory/batches/reconcile', 'POST'),
            $synchronizer
        );

        $this->assertSame(['hospitality-a'], $synchronizer->receivedBranchIds);
    }

    public function test_stock_transfer_reads_and_mutations_cannot_reach_an_opposite_mode_transfer(): void
    {
        DB::table('company_branches')->insert([
            ['id' => 'hospitality-a', 'branch_name' => 'Hospitality', 'branch_code' => 'H'],
            ['id' => 'retail-a', 'branch_name' => 'Retail', 'branch_code' => 'R'],
        ]);
        DB::table('stock_transfer_headers')->insert([
            $this->transfer('transfer-h', 'hospitality-a'),
            $this->transfer('transfer-r', 'retail-a'),
        ]);

        $controller = new StockTransferController;
        $request = Request::create('/stock-transfers', 'GET');
        app()->instance('request', $request);

        $ids = collect($controller->index($request)->getData(true)['data']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame(['transfer-h'], $ids);
        $this->assertSame(404, $controller->show('transfer-r')->getStatusCode());

        foreach (['approve', 'reject', 'dispatch', 'receive', 'cancel'] as $action) {
            $response = $controller->{$action}(Request::create("/stock-transfers/transfer-r/{$action}", 'POST'), 'transfer-r');
            $this->assertSame(404, $response->getStatusCode(), "{$action} must hide an opposite-mode transfer");
        }
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

    private function warehouse(string $id, ?string $branchId, string $name): array
    {
        return [
            'id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId,
            'code' => strtoupper($id), 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ];
    }

    private function batch(string $id, string $branchId, string $warehouseId): array
    {
        return [
            'id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId,
            'warehouse_id' => $warehouseId, 'location_id' => null, 'product_id' => 'product-a',
            'expires_at' => null, 'quantity_on_hand' => 1, 'reserved_quantity' => 0,
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function alert(string $id, string $branchId): array
    {
        return [
            'id' => $id, 'company_id' => 'company-a', 'branch_id' => $branchId,
            'alert_type' => 'low_stock', 'status' => 'open', 'resolved_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function transfer(string $id, string $branchId): array
    {
        return [
            'id' => $id, 'company_id' => 'company-a', 'transfer_no' => strtoupper($id),
            'transfer_date' => now()->toDateString(), 'from_branch_id' => $branchId,
            'from_warehouse_id' => null, 'from_location_type' => 'branch',
            'to_branch_id' => $branchId, 'to_warehouse_id' => null, 'to_location_type' => 'branch',
            'status' => 'requested', 'requested_by' => 'user-a',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function createSchema(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('branch_name')->nullable();
            $table->string('branch_code')->nullable();
        });
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('warehouse_id');
            $table->string('name');
            $table->integer('pick_sequence')->default(0);
            $table->softDeletes();
        });
        Schema::create('inventory_location_balances', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('warehouse_id');
            $table->string('product_id');
            $table->decimal('quantity_on_hand')->default(0);
            $table->decimal('reserved_quantity')->default(0);
            $table->decimal('available_quantity')->default(0);
        });
        Schema::create('product_costs', function (Blueprint $table) {
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('product_id');
            $table->decimal('average_cost')->default(0);
            $table->decimal('last_cost')->default(0);
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->decimal('reorder_level')->default(0);
        });
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('warehouse_id')->nullable();
            $table->string('location_id')->nullable();
            $table->string('product_id');
            $table->date('expires_at')->nullable();
            $table->decimal('quantity_on_hand')->default(0);
            $table->decimal('reserved_quantity')->default(0);
            $table->string('status')->default('available');
            $table->timestamps();
        });
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('product_id');
            $table->decimal('available_quantity')->default(0);
        });
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('alert_type');
            $table->string('status');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_transfer_headers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('transfer_no');
            $table->date('transfer_date');
            $table->string('from_branch_id');
            $table->string('from_warehouse_id')->nullable();
            $table->string('from_location_type');
            $table->string('to_branch_id');
            $table->string('to_warehouse_id')->nullable();
            $table->string('to_location_type');
            $table->string('status');
            $table->string('requested_by')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('stock_transfer_id');
            $table->string('product_name')->nullable();
        });
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        return [
            'company_branches', 'inventory_warehouses', 'inventory_locations',
            'inventory_location_balances', 'product_costs', 'products',
            'inventory_batches', 'product_stocks', 'inventory_alerts',
            'stock_transfer_headers', 'stock_transfer_lines',
        ];
    }
}
