<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use Modules\Product\Http\Controllers\StockReportController;
use Modules\Product\Http\Controllers\StockTakeController;
use Modules\Product\Http\Controllers\StockTransferController;
use Modules\Sales\Http\Controllers\CashupController;
use Modules\Sales\Http\Controllers\DiscountReportController;
use Modules\Sales\Http\Controllers\FoodIndustrySalesReportController;
use Modules\Sales\Http\Controllers\SalesReportController;
use Modules\ZimraIntegration\Http\Controllers\EnterpriseTaxReportController;
use ReflectionMethod;
use Tests\TestCase;

class BusinessModeBranchScopeServiceTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for business-mode scope tests.');
        }

        $this->databaseReady = true;

        Schema::create('company_branches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->softDeletes();
        });

        DB::table('company_branches')->insert([
            ['id' => 'branch-a', 'company_id' => 'company-a'],
            ['id' => 'branch-b', 'company_id' => 'company-a'],
            ['id' => 'branch-c', 'company_id' => 'company-a'],
            ['id' => 'foreign-a', 'company_id' => 'company-b'],
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');

        if ($this->databaseReady) {
            Schema::dropIfExists('company_branches');
        }

        parent::tearDown();
    }

    public function test_all_accessible_company_branches_share_the_hospitality_scope(): void
    {
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $this->request(['X-Branch-ID' => 'branch-a']),
            'company-a'
        );

        $this->assertSame('hospitality', $scope['business_mode']);
        $this->assertSame('branch-a', $scope['context_branch_id']);
        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $scope['branch_ids']);
    }

    public function test_every_workspace_branch_uses_the_hospitality_scope(): void
    {
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $this->request(['X-Branch-ID' => 'branch-c']),
            'company-a'
        );

        $this->assertSame('hospitality', $scope['business_mode']);
        $this->assertSame('branch-c', $scope['context_branch_id']);
        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $scope['branch_ids']);
    }

    public function test_explicit_accessible_branch_filter_is_accepted_in_the_hospitality_scope(): void
    {
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $this->request(['X-Branch-ID' => 'branch-a'], ['branch_id' => 'branch-c']),
            'company-a',
            ['branch-c']
        );

        $this->assertSame('hospitality', $scope['business_mode']);
        $this->assertSame('branch-a', $scope['context_branch_id']);
        $this->assertSame(['branch-c'], $scope['branch_ids']);
    }

    public function test_inaccessible_branch_filter_is_still_rejected(): void
    {
        try {
            app(BusinessModeBranchScopeService::class)->resolve(
                $this->request(['X-Branch-ID' => 'branch-a'], ['branch_id' => 'foreign-a']),
                'company-a',
                ['foreign-a']
            );
            $this->fail('Expected a branch outside the current company allocation to be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('BRANCH_ACCESS_DENIED', json_decode($exception->getResponse()->getContent(), true)['code']);
        }
    }

    public function test_general_and_hospitality_report_filters_share_the_same_mode_scope(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $request = $this->request(['X-Branch-ID' => 'branch-a']);

        $salesMethod = new ReflectionMethod(SalesReportController::class, 'filters');
        $salesMethod->setAccessible(true);
        $salesFilters = $salesMethod->invoke(new SalesReportController, $request);

        $foodMethod = new ReflectionMethod(FoodIndustrySalesReportController::class, 'filters');
        $foodMethod->setAccessible(true);
        $foodFilters = $foodMethod->invoke(new FoodIndustrySalesReportController, $request);

        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $salesFilters['branch_ids']);
        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $foodFilters['branch_ids']);
        $this->assertSame('branch-a', $salesFilters['costing_context_branch_id']);
        $this->assertSame('branch-a', $foodFilters['mode_context_branch_id']);
    }

    public function test_discount_report_all_branches_is_limited_to_the_active_mode(): void
    {
        $controller = new DiscountReportController(
            app(EnterpriseReportDownloadService::class),
            app(BusinessModeBranchScopeService::class)
        );
        $method = new ReflectionMethod($controller, 'modeScopedBranchIds');
        $method->setAccessible(true);

        $branchIds = $method->invoke(
            $controller,
            $this->request(['X-Branch-ID' => 'branch-a']),
            'company-a'
        );

        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $branchIds);
    }

    public function test_shared_inventory_reports_use_all_accessible_branches_in_the_active_mode(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $request = $this->request(['X-Branch-ID' => 'branch-a']);
        $expected = ['branch-a', 'branch-b', 'branch-c'];

        $stockFilters = new ReflectionMethod(StockReportController::class, 'filters');
        $this->assertSame(
            $expected,
            $stockFilters->invoke(new StockReportController, $request)['branch_ids']
        );

        $inventoryScope = new ReflectionMethod(EnterpriseInventoryController::class, 'dashboardBranchScope');
        $this->assertSame(
            $expected,
            $inventoryScope->invoke(
                new EnterpriseInventoryController,
                $request,
                'company-a',
                app(BusinessModeBranchScopeService::class)
            )['branch_ids']
        );

        $transferScope = new ReflectionMethod(StockTransferController::class, 'reportBranchIds');
        $this->assertSame(
            $expected,
            $transferScope->invoke(new StockTransferController, $request, 'company-a')
        );

        $stockTakeScope = new ReflectionMethod(StockTakeController::class, 'resolveBranchIds');
        $this->assertSame(
            $expected,
            $stockTakeScope->invoke(new StockTakeController, $request, 'company-a')
        );
    }

    public function test_shared_inventory_context_is_hospitality_for_every_company_branch(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $request = $this->request(['X-Branch-ID' => 'branch-c']);

        $stockFilters = new ReflectionMethod(StockReportController::class, 'filters');
        $this->assertSame(
            ['branch-a', 'branch-b', 'branch-c'],
            $stockFilters->invoke(new StockReportController, $request)['branch_ids']
        );

        $inventoryScope = new ReflectionMethod(EnterpriseInventoryController::class, 'dashboardBranchScope');
        $scope = $inventoryScope->invoke(
            new EnterpriseInventoryController,
            $request,
            'company-a',
            app(BusinessModeBranchScopeService::class)
        );

        $this->assertSame('hospitality', $scope['business_mode']);
        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $scope['branch_ids']);
    }

    public function test_shared_inventory_report_accepts_an_accessible_branch_filter(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $request = $this->request(
            ['X-Branch-ID' => 'branch-a'],
            ['branch_id' => 'branch-c']
        );

        $method = new ReflectionMethod(StockTakeController::class, 'resolveBranchIds');

        $this->assertSame(
            ['branch-c'],
            $method->invoke(new StockTakeController, $request, 'company-a')
        );
    }

    public function test_stock_transfer_report_accepts_accessible_hospitality_endpoints(): void
    {
        $request = $this->request(
            ['X-Branch-ID' => 'branch-a'],
            ['from_branch_id' => 'branch-c']
        );

        $method = new ReflectionMethod(StockTransferController::class, 'reportBranchIds');

        $this->assertSame(
            ['branch-a', 'branch-b', 'branch-c'],
            $method->invoke(new StockTransferController, $request, 'company-a')
        );
    }

    public function test_cashup_reports_all_branches_are_limited_to_the_active_mode(): void
    {
        $controller = app(CashupController::class);
        $method = new ReflectionMethod($controller, 'resolveCashupReportBranchIds');
        $method->setAccessible(true);

        $branchIds = $method->invoke(
            $controller,
            $this->request(['X-Branch-ID' => 'branch-c']),
            'company-a'
        );

        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $branchIds);
    }

    public function test_tax_reports_receive_only_same_mode_branch_ids(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $controller = app(EnterpriseTaxReportController::class);
        $method = new ReflectionMethod($controller, 'scopedRequest');
        $method->setAccessible(true);

        $request = $method->invoke(
            $controller,
            $this->request(['X-Branch-ID' => 'branch-a'])
        );

        $this->assertSame(['branch-a', 'branch-b', 'branch-c'], $request->input('branch_ids'));
        $this->assertSame('hospitality', $request->input('business_mode'));
    }

    private function request(array $headers = [], array $query = []): Request
    {
        $request = Request::create('/api/pos/reports', 'GET', $query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $user = new class
        {
            public string $branch_id = 'branch-c';

            public string $user_type = 'manager';

            public object $role;

            public function __construct()
            {
                $this->role = (object) ['name' => 'Manager'];
            }

            public function branches(): object
            {
                return new class
                {
                    public function where(string $column, mixed $value): self
                    {
                        return $this;
                    }

                    public function pluck(string $column)
                    {
                        return collect(['branch-a', 'branch-b', 'branch-c']);
                    }
                };
            }
        };
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
