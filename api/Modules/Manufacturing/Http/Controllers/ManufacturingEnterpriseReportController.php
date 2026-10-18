<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Manufacturing\ManufacturingEnterpriseReportService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;


class ManufacturingEnterpriseReportController extends Controller
{
    public function __construct(
        private ManufacturingEnterpriseReportService $service,
        private BusinessModeBranchScopeService $branchScope
    ) {}

    public function enterpriseDashboard(Request $request) { return response()->json(['message' => 'Enterprise dashboard fetched successfully.', 'data' => $this->service->enterpriseDashboard($this->filters($request))]); }
    public function consolidatedProduction(Request $request) { return response()->json(['message' => 'Consolidated production fetched successfully.', 'data' => $this->service->consolidatedPlanHistory($this->filters($request))]); }
    public function directIndirectCosts(Request $request) { return response()->json(['message' => 'Direct and indirect costs fetched successfully.', 'data' => $this->service->directIndirectCosts($this->filters($request))]); }
    public function staffCosts(Request $request) { return response()->json(['message' => 'Staff production costs fetched successfully.', 'data' => $this->service->staffCosts($this->filters($request))]); }
    public function productionActivity(Request $request) { return response()->json(['message' => 'Production activity fetched successfully.', 'data' => $this->service->productionActivity($this->filters($request))]); }
    public function productionOrderActivity(Request $request, string $orderId) { return response()->json(['message' => 'Production order activity fetched successfully.', 'data' => $this->service->productionOrderActivity($orderId, $this->filters($request))]); }
    public function productionPlanComparison(Request $request) { return response()->json(['message' => 'Production plan comparison fetched successfully.', 'data' => $this->service->productionPlanComparison($this->filters($request))]); }
    public function consolidatedPlanHistory(Request $request) { return response()->json(['message' => 'Consolidated plan history fetched successfully.', 'data' => $this->service->consolidatedPlanHistory($this->filters($request))]); }
    public function salaryAllocation(Request $request) { return response()->json(['message' => 'Salary allocation fetched successfully.', 'data' => $this->service->salaryAllocation($this->filters($request))]); }

    private function filters(Request $request): array
    {
        $companyId = (string) app('company')->id;
        $scope = $this->branchScope->resolve(
            $request,
            $companyId,
            $this->requestedBranchIds($request)
        );

        if ($scope['business_mode'] !== 'retail') {
            throw new HttpResponseException(response()->json([
                'message' => 'Manufacturing reports are only available in a Retail workspace.',
                'code' => 'BUSINESS_MODE_MISMATCH',
                'required_modes' => ['retail'],
                'current_mode' => $scope['business_mode'],
                'branch_id' => $scope['context_branch_id'],
            ], 409));
        }

        return array_merge($request->all(), [
            'company_id' => $companyId,
            'branch_ids' => $scope['branch_ids'],
        ]);
    }

    private function requestedBranchIds(Request $request): array
    {
        $requested = $request->input('branch_ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        if (!is_array($requested)) {
            $requested = [];
        }
        if ($request->filled('branch_id')) {
            $requested[] = $request->input('branch_id');
        }

        return $requested;
    }
}
