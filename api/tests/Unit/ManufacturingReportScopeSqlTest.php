<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Manufacturing\ManufacturingEnterpriseReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Manufacturing\Http\Controllers\ManufacturingEnterpriseReportController;
use Modules\Manufacturing\Http\Controllers\ManufacturingReportController;
use ReflectionMethod;
use Tests\TestCase;

class ManufacturingReportScopeSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('company', (object) ['id' => 'company-a']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        parent::tearDown();
    }

    public function test_order_filter_always_adds_resolved_branch_scope(): void
    {
        $scope = Mockery::mock(BusinessModeBranchScopeService::class);
        $scope->shouldReceive('resolve')->once()->andReturn($this->retailScope());
        $controller = new ManufacturingReportController($scope);
        $query = DB::table('production_orders');

        (new ReflectionMethod($controller, 'applyOrderFilters'))->invoke(
            $controller,
            $query,
            Request::create('/api/pos/manufacturing/reports', 'GET')
        );

        $this->assertStringContainsString(
            '"production_orders"."branch_id" in (?, ?)',
            $query->toSql()
        );
        $this->assertSame(['retail-a', 'retail-b'], $query->getBindings());
    }

    public function test_enterprise_filters_override_client_supplied_tenant_scope(): void
    {
        $scope = Mockery::mock(BusinessModeBranchScopeService::class);
        $scope->shouldReceive('resolve')->once()->andReturn($this->retailScope());
        $controller = new ManufacturingEnterpriseReportController(
            new ManufacturingEnterpriseReportService,
            $scope
        );
        $request = Request::create('/api/pos/manufacturing/reports', 'GET', [
            'company_id' => 'company-b',
            'branch_ids' => ['foreign-a'],
        ]);

        $filters = (new ReflectionMethod($controller, 'filters'))->invoke($controller, $request);

        $this->assertSame('company-a', $filters['company_id']);
        $this->assertSame(['retail-a', 'retail-b'], $filters['branch_ids']);
    }

    private function retailScope(): array
    {
        return [
            'business_mode' => 'retail',
            'context_branch_id' => 'retail-a',
            'branch_ids' => ['retail-a', 'retail-b'],
            'accessible_branch_ids' => ['retail-a', 'retail-b', 'hospitality-a'],
        ];
    }
}
