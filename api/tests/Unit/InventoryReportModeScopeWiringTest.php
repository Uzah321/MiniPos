<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use Illuminate\Http\Request;
use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use Modules\Product\Http\Controllers\StockReportController;
use Modules\Product\Http\Controllers\StockTakeController;
use Modules\Product\Http\Controllers\StockTransferController;
use ReflectionMethod;
use Tests\TestCase;

class InventoryReportModeScopeWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance(BusinessModeBranchScopeService::class);

        parent::tearDown();
    }

    public function test_shared_inventory_reports_use_the_resolved_mode_branch_ids(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        $branchScope = $this->branchScope([
            'business_mode' => 'hospitality',
            'context_branch_id' => 'hospitality-a',
            'branch_ids' => ['hospitality-a', 'hospitality-b'],
            'accessible_branch_ids' => ['hospitality-a', 'hospitality-b', 'retail-a'],
        ]);
        app()->instance(BusinessModeBranchScopeService::class, $branchScope);
        $request = Request::create('/api/pos/reports', 'GET');
        $request->headers->set('X-Branch-ID', 'hospitality-a');

        $stockFilters = $this->method(StockReportController::class, 'filters')
            ->invoke(new StockReportController, $request);
        $inventoryScope = $this->method(EnterpriseInventoryController::class, 'dashboardBranchScope')
            ->invoke(new EnterpriseInventoryController, $request, 'company-a', $branchScope);
        $transferBranchIds = $this->method(StockTransferController::class, 'reportBranchIds')
            ->invoke(new StockTransferController, $request, 'company-a');
        $stockTakeBranchIds = $this->method(StockTakeController::class, 'resolveBranchIds')
            ->invoke(new StockTakeController, $request, 'company-a');

        $expected = ['hospitality-a', 'hospitality-b'];
        $this->assertSame($expected, $stockFilters['branch_ids']);
        $this->assertSame($expected, $inventoryScope['branch_ids']);
        $this->assertSame($expected, $transferBranchIds);
        $this->assertSame($expected, $stockTakeBranchIds);
    }

    public function test_transfer_scope_validates_both_selected_endpoints_against_the_workspace_mode(): void
    {
        $branchScope = $this->branchScope([
            'business_mode' => 'retail',
            'context_branch_id' => 'retail-a',
            'branch_ids' => ['retail-a', 'retail-b'],
            'accessible_branch_ids' => ['retail-a', 'retail-b'],
        ]);
        app()->instance(BusinessModeBranchScopeService::class, $branchScope);
        $request = Request::create('/api/pos/reports', 'GET', [
            'from_branch_id' => 'retail-a',
            'to_branch_id' => 'retail-b',
        ]);

        $branchIds = $this->method(StockTransferController::class, 'reportBranchIds')
            ->invoke(new StockTransferController, $request, 'company-a');

        $this->assertSame(['retail-a', 'retail-b'], $branchIds);
        $this->assertSame([[], ['retail-a', 'retail-b']], $branchScope->requestedBranchIds);
    }

    private function method(string $class, string $method): ReflectionMethod
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection;
    }

    private function branchScope(array $scope): BusinessModeBranchScopeService
    {
        return new class($scope) extends BusinessModeBranchScopeService {
            public array $requestedBranchIds = [];

            public function __construct(private readonly array $scope) {}

            public function resolve(Request $request, string $companyId, array $requestedBranchIds = []): array
            {
                $this->requestedBranchIds[] = $requestedBranchIds;

                return $this->scope;
            }
        };
    }
}
