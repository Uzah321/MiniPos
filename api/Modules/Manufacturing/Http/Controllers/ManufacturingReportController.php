<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use function App\Helpers\hasPermission;
use Modules\Manufacturing\Models\MaterialRequirement;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionPlan;

class ManufacturingReportController extends Controller
{
    public function __construct(
        private readonly BusinessModeBranchScopeService $branchScope
    ) {}

    private function canViewReports()
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_view_reports',
            $company->id
        );

        return [$company, $permission];
    }

    private function applyOrderFilters($query, Request $request, string $alias = 'production_orders')
    {
        $query->whereIn(
            "{$alias}.branch_id",
            $this->reportBranchIds($request, (string) app('company')->id)
        );

        if ($request->filled('production_plan_id')) {
            $query->where("{$alias}.production_plan_id", $request->production_plan_id);
        }

        if ($request->filled('production_order_id')) {
            $query->where("{$alias}.id", $request->production_order_id);
        }

        if ($request->filled('status')) {
            $query->where("{$alias}.status", $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate("{$alias}.created_at", '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate("{$alias}.created_at", '<=', $request->date_to);
        }

        return $query;
    }

    private function reportBranchIds(Request $request, string $companyId): array
    {
        $cacheKey = 'manufacturing_report_branch_scope';
        $scope = $request->attributes->get($cacheKey);

        if (!is_array($scope) || (string) ($scope['company_id'] ?? '') !== $companyId) {
            $scope = $this->branchScope->resolve(
                $request,
                $companyId,
                $this->requestedBranchIds($request)
            );
            $scope['company_id'] = $companyId;
            $request->attributes->set($cacheKey, $scope);
        }

        if (($scope['business_mode'] ?? null) !== 'retail') {
            throw new HttpResponseException(response()->json([
                'message' => 'Manufacturing reports are only available in a Retail workspace.',
                'code' => 'BUSINESS_MODE_MISMATCH',
                'required_modes' => ['retail'],
                'current_mode' => $scope['business_mode'] ?? null,
                'branch_id' => $scope['context_branch_id'] ?? null,
            ], 409));
        }

        return $scope['branch_ids'];
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

    private function money($value): float
    {
        return round((float) $value, 6);
    }

    public function dashboard(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        $query = ProductionOrder::where('company_id', $company->id);
        $this->applyOrderFilters($query, $request);

        $recentOrders = (clone $query)
            ->with('finishedProduct')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'production_order_code' => $order->production_number,
                    'finished_product_name' => $order->finishedProduct?->name,
                    'status' => $order->status,
                    'planned_quantity' => (float) $order->planned_output_quantity,
                    'actual_good_quantity' => (float) $order->actual_good_quantity,
                    'actual_total_cost' => (float) $order->actual_total_cost,
                    'completed_at' => $order->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Manufacturing dashboard fetched successfully.',
            'data' => [
                'open_orders' => (clone $query)
                    ->whereIn('status', [
                        'draft',
                        'approved',
                        'materials_reserved',
                        'materials_issued',
                    ])
                    ->count(),

                'in_progress_orders' => (clone $query)
                    ->where('status', 'in_progress')
                    ->count(),

                'completed_today' => (clone $query)
                    ->where('status', 'completed')
                    ->whereDate('completed_at', now()->toDateString())
                    ->count(),

                'planned_quantity' => (float) (clone $query)->sum('planned_output_quantity'),
                'actual_good_quantity' => (float) (clone $query)->sum('actual_good_quantity'),
                'actual_reject_quantity' => (float) (clone $query)->sum('actual_reject_quantity'),
                'actual_waste_quantity' => (float) (clone $query)->sum('actual_waste_quantity'),

                'planned_total_cost' => (float) (clone $query)->sum('planned_total_cost'),
                'actual_total_cost' => (float) (clone $query)->sum('actual_total_cost'),

                'recent_orders' => $recentOrders,
                'material_alerts' => $this->materialShortageAlerts($request)->getData(true)['data'] ?? [],
            ],
        ]);
    }

    public function index(Request $request)
    {
        return $this->productionSummary($request);
    }

    public function productionSummary(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        $query = ProductionOrder::where('company_id', $company->id)
            ->with('finishedProduct');

        $this->applyOrderFilters($query, $request);

        $orders = (clone $query)->get();

        $plannedQty = (float) $orders->sum('planned_output_quantity');
        $actualGood = (float) $orders->sum('actual_good_quantity');
        $actualReject = (float) $orders->sum('actual_reject_quantity');
        $actualWaste = (float) $orders->sum('actual_waste_quantity');

        $plannedTotalCost = (float) $orders->sum('planned_total_cost');
        $actualRawMaterialCost = (float) $orders->sum('actual_raw_material_cost');
        $actualLabourCost = (float) $orders->sum('actual_labour_cost');
        $actualOverheadCost = (float) $orders->sum('actual_overhead_cost');
        $actualTotalCost = (float) $orders->sum('actual_total_cost');

        $recentProduction = $orders
            ->sortByDesc('created_at')
            ->take(30)
            ->values()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'production_plan_id' => $order->production_plan_id,
                    'production_order_code' => $order->production_number,
                    'product_name' => $order->finishedProduct?->name,
                    'planned_quantity' => (float) $order->planned_output_quantity,
                    'completed_quantity' => (float) $order->actual_good_quantity,
                    'reject_quantity' => (float) $order->actual_reject_quantity,
                    'waste_quantity' => (float) $order->actual_waste_quantity,
                    'planned_total_cost' => (float) $order->planned_total_cost,
                    'actual_total_cost' => (float) $order->actual_total_cost,
                    'actual_unit_cost' => (float) $order->actual_unit_cost,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'completed_at' => $order->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Production summary fetched successfully.',
            'data' => [
                'orders' => $orders->count(),
                'completed_orders' => $orders->where('status', 'completed')->count(),
                'in_progress_orders' => $orders->where('status', 'in_progress')->count(),

                'planned_output_quantity' => $plannedQty,
                'actual_good_quantity' => $actualGood,
                'actual_reject_quantity' => $actualReject,
                'actual_waste_quantity' => $actualWaste,

                'yield_percent' => $plannedQty > 0
                    ? round(($actualGood / $plannedQty) * 100, 2)
                    : 0,

                'loss_percent' => $plannedQty > 0
                    ? round((($actualReject + $actualWaste) / $plannedQty) * 100, 2)
                    : 0,

                'planned_total_cost' => $plannedTotalCost,

                'direct_material_cost' => $actualRawMaterialCost,
                'direct_labour_cost' => $actualLabourCost,
                'indirect_overhead_cost' => $actualOverheadCost,

                'actual_total_cost' => $actualTotalCost,

                'average_cost_per_unit' => $actualGood > 0
                    ? round($actualTotalCost / $actualGood, 6)
                    : 0,

                'cost_variance_amount' => $actualTotalCost - $plannedTotalCost,

                'recent_production' => $recentProduction,
            ],
        ]);
    }

    public function productionCosts(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = ProductionOrder::where('company_id', $company->id)
            ->with([
                'finishedProduct',
                'inputs' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->with('product'),
                'overheads' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds),
                'outputs' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->with('product'),
            ]);

        $this->applyOrderFilters($query, $request);

        $orders = $query->orderByDesc('created_at')->get();

        $rows = $orders->map(function ($order) {
            $inputs = $order->inputs ?? collect();
            $overheads = $order->overheads ?? collect();

            $bomStandardCost = $inputs->sum(function ($input) {
                $plannedQty = (float) $input->planned_quantity;
                $unitCost = (float) data_get($input->metadata, 'unit_cost', $input->unit_cost ?? 0);
                return $plannedQty * $unitCost;
            });

            $actualMaterialCost = (float) $inputs->sum('total_cost');

            $directLabour = (float) $overheads
                ->whereIn('cost_type', ['labour', 'salary', 'direct_labour'])
                ->sum('amount');

            $indirectOverhead = (float) $overheads
                ->whereNotIn('cost_type', ['labour', 'salary', 'direct_labour'])
                ->sum('amount');

            $actualTotal = $actualMaterialCost + $directLabour + $indirectOverhead;

            return [
                'production_order_id' => $order->id,
                'production_plan_id' => $order->production_plan_id,
                'production_number' => $order->production_number,
                'finished_product' => $order->finishedProduct?->name,
                'status' => $order->status,

                'planned_quantity' => (float) $order->planned_output_quantity,
                'actual_good_quantity' => (float) $order->actual_good_quantity,

                'bom_standard_cost' => $this->money($bomStandardCost),
                'planned_total_cost' => $this->money($order->planned_total_cost),

                'direct_material_cost' => $this->money($actualMaterialCost),
                'direct_labour_cost' => $this->money($directLabour),
                'indirect_overhead_cost' => $this->money($indirectOverhead),

                'actual_total_cost' => $this->money($actualTotal ?: $order->actual_total_cost),
                'actual_unit_cost' => (float) $order->actual_unit_cost,

                'cost_variance_amount' => $this->money(($actualTotal ?: $order->actual_total_cost) - (float) $order->planned_total_cost),

                'created_at' => $order->created_at,
                'completed_at' => $order->completed_at,
            ];
        });

        return response()->json([
            'message' => 'Production cost report fetched successfully.',
            'data' => [
                'summary' => [
                    'orders' => $rows->count(),
                    'planned_quantity' => $rows->sum('planned_quantity'),
                    'actual_good_quantity' => $rows->sum('actual_good_quantity'),
                    'bom_standard_cost' => $this->money($rows->sum('bom_standard_cost')),
                    'planned_total_cost' => $this->money($rows->sum('planned_total_cost')),
                    'direct_material_cost' => $this->money($rows->sum('direct_material_cost')),
                    'direct_labour_cost' => $this->money($rows->sum('direct_labour_cost')),
                    'indirect_overhead_cost' => $this->money($rows->sum('indirect_overhead_cost')),
                    'actual_total_cost' => $this->money($rows->sum('actual_total_cost')),
                    'cost_variance_amount' => $this->money($rows->sum('cost_variance_amount')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }

    public function materialUsage(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = DB::table('production_order_inputs as poi')
            ->join('production_orders as po', 'po.id', '=', 'poi.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'poi.product_id')
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.product_id', '=', 'poi.product_id')
                    ->on('pc.company_id', '=', 'poi.company_id')
                    ->on('pc.branch_id', '=', 'poi.branch_id');
            })
            ->where('po.company_id', $company->id)
            ->where('poi.company_id', $company->id)
            ->whereIn('poi.branch_id', $branchIds);

        $this->applyOrderFilters($query, $request, 'po');

        /*
        |--------------------------------------------------------------------------
        | Important:
        | Material usage should not default to draft orders.
        | Draft orders are BOM/planned lines, not actual usage.
        |--------------------------------------------------------------------------
        */
        if (!$request->boolean('include_draft')) {
            $query->whereIn('po.status', [
                'materials_issued',
                'in_progress',
                'completed',
            ]);
        }

        $rows = $query
            ->select([
                'po.id as production_order_id',
                'po.production_plan_id',
                'po.production_number',
                'po.status as order_status',
                'po.completed_at',
                'po.created_at',
                'po.planned_output_quantity',
                'po.actual_good_quantity',

                'p.name as material',
                'p.code as material_code',

                'poi.planned_quantity',
                'poi.reserved_quantity',
                'poi.issued_quantity',
                'poi.returned_quantity',
                'poi.wasted_quantity',
                'poi.unit_cost',
                'poi.total_cost',
                'poi.currency',
                'poi.metadata',

                DB::raw('COALESCE(NULLIF(poi.unit_cost, 0), pc.average_cost, 0) as effective_unit_cost'),
            ])
            ->orderByDesc('po.created_at')
            ->get()
            ->map(function ($row) {
                $plannedQty = (float) $row->planned_quantity;
                $issuedQty = (float) $row->issued_quantity;
                $returnedQty = (float) $row->returned_quantity;
                $wastedQty = (float) $row->wasted_quantity;

                $netConsumed = max($issuedQty - $returnedQty, 0);

                $unitCost = (float) $row->effective_unit_cost;
                $totalCost = (float) $row->total_cost;

                if ($totalCost <= 0 && $issuedQty > 0) {
                    $totalCost = $netConsumed * $unitCost;
                }

                return [
                    'production_order_id' => $row->production_order_id,
                    'production_plan_id' => $row->production_plan_id,
                    'production_number' => $row->production_number,
                    'finished_product_quantity_planned' => (float) $row->planned_output_quantity,
                    'finished_product_quantity_actual' => (float) $row->actual_good_quantity,

                    'material' => $row->material,
                    'material_code' => $row->material_code,
                    'uom' => data_get(json_decode($row->metadata, true), 'unit', null),

                    'planned_quantity' => $plannedQty,
                    'reserved_quantity' => (float) $row->reserved_quantity,
                    'issued_quantity' => $issuedQty,
                    'returned_quantity' => $returnedQty,
                    'wasted_quantity' => $wastedQty,
                    'net_consumed_quantity' => $netConsumed,

                    'unit_cost' => $this->money($unitCost),
                    'total_cost' => $this->money($totalCost),

                    'currency' => $row->currency,
                    'order_status' => $row->order_status,
                    'created_at' => $row->created_at,
                    'completed_at' => $row->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Material usage report fetched successfully.',
            'data' => [
                'summary' => [
                    'lines' => $rows->count(),
                    'planned_quantity' => $rows->sum('planned_quantity'),
                    'issued_quantity' => $rows->sum('issued_quantity'),
                    'returned_quantity' => $rows->sum('returned_quantity'),
                    'wasted_quantity' => $rows->sum('wasted_quantity'),
                    'net_consumed_quantity' => $rows->sum('net_consumed_quantity'),
                    'total_cost' => $this->money($rows->sum('total_cost')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }

    public function finishedGoodsReceived(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = DB::table('production_orders as po')
            ->leftJoin('production_order_outputs as poo', function ($join) use ($company, $branchIds) {
                $join->on('poo.production_order_id', '=', 'po.id')
                    ->where('poo.company_id', $company->id)
                    ->whereIn('poo.branch_id', $branchIds);
            })
            ->leftJoin('products as p', 'p.id', '=', 'po.finished_product_id')
            ->where('po.company_id', $company->id);

        $this->applyOrderFilters($query, $request, 'po');

        if (!$request->boolean('include_uncompleted')) {
            $query->where('po.status', 'completed');
        }

        $rows = $query
            ->select([
                'po.id as production_order_id',
                'po.production_plan_id',
                'po.production_number',
                'po.status',
                'p.name as finished_product',
                'p.code as finished_product_code',
                'po.planned_output_quantity',
                'po.actual_good_quantity',
                'po.actual_reject_quantity',
                'po.actual_waste_quantity',
                'po.actual_unit_cost',
                'po.actual_total_cost',
                'po.currency',
                'po.completed_at',
                'po.created_at',
                'poo.quantity_produced',
                'poo.unit_cost as output_unit_cost',
                'poo.total_cost as output_total_cost',
            ])
            ->orderByDesc('po.completed_at')
            ->get()
            ->map(function ($row) {
                $quantityProduced = (float) ($row->quantity_produced ?: $row->actual_good_quantity);
                $unitCost = (float) ($row->output_unit_cost ?: $row->actual_unit_cost);
                $totalCost = (float) ($row->output_total_cost ?: $row->actual_total_cost);

                return [
                    'production_order_id' => $row->production_order_id,
                    'production_plan_id' => $row->production_plan_id,
                    'production_number' => $row->production_number,
                    'finished_product' => $row->finished_product,
                    'finished_product_code' => $row->finished_product_code,
                    'planned_quantity' => (float) $row->planned_output_quantity,
                    'quantity_received' => $quantityProduced,
                    'quantity_rejected' => (float) $row->actual_reject_quantity,
                    'quantity_wasted' => (float) $row->actual_waste_quantity,
                    'unit_cost' => $this->money($unitCost),
                    'total_cost' => $this->money($totalCost),
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'completed_at' => $row->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Finished goods received report fetched successfully.',
            'data' => [
                'summary' => [
                    'lines' => $rows->count(),
                    'planned_quantity' => $rows->sum('planned_quantity'),
                    'quantity_received' => $rows->sum('quantity_received'),
                    'quantity_rejected' => $rows->sum('quantity_rejected'),
                    'quantity_wasted' => $rows->sum('quantity_wasted'),
                    'total_cost' => $this->money($rows->sum('total_cost')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }

    public function workInProgress(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = ProductionOrder::where('company_id', $company->id)
            ->with([
                'finishedProduct',
                'inputs' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->with('product'),
                'overheads' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds),
            ])
            ->whereIn('status', [
                'approved',
                'materials_reserved',
                'materials_issued',
                'in_progress',
            ]);

        $this->applyOrderFilters($query, $request);

        $rows = $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($order) {
                $materialCost = (float) ($order->inputs ?? collect())->sum('total_cost');
                $labourCost = (float) ($order->overheads ?? collect())
                    ->whereIn('cost_type', ['labour', 'salary', 'direct_labour'])
                    ->sum('amount');

                $overheadCost = (float) ($order->overheads ?? collect())
                    ->whereNotIn('cost_type', ['labour', 'salary', 'direct_labour'])
                    ->sum('amount');

                $wipValue = $materialCost + $labourCost + $overheadCost;

                return [
                    'production_order_id' => $order->id,
                    'production_plan_id' => $order->production_plan_id,
                    'production_number' => $order->production_number,
                    'finished_product' => $order->finishedProduct?->name,
                    'status' => $order->status,
                    'planned_quantity' => (float) $order->planned_output_quantity,
                    'issued_material_cost' => $this->money($materialCost),
                    'direct_labour_cost' => $this->money($labourCost),
                    'indirect_overhead_cost' => $this->money($overheadCost),
                    'wip_value' => $this->money($wipValue),
                    'created_at' => $order->created_at,
                    'started_at' => $order->started_at,
                ];
            });

        return response()->json([
            'message' => 'Work in progress report fetched successfully.',
            'data' => [
                'summary' => [
                    'orders' => $rows->count(),
                    'planned_quantity' => $rows->sum('planned_quantity'),
                    'issued_material_cost' => $this->money($rows->sum('issued_material_cost')),
                    'direct_labour_cost' => $this->money($rows->sum('direct_labour_cost')),
                    'indirect_overhead_cost' => $this->money($rows->sum('indirect_overhead_cost')),
                    'wip_value' => $this->money($rows->sum('wip_value')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }

    public function wastage(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = DB::table('production_order_inputs as poi')
            ->join('production_orders as po', 'po.id', '=', 'poi.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'poi.product_id')
            ->where('po.company_id', $company->id)
            ->where('poi.company_id', $company->id)
            ->whereIn('poi.branch_id', $branchIds)
            ->where(function ($q) {
                $q->where('poi.wasted_quantity', '>', 0)
                    ->orWhere('po.actual_waste_quantity', '>', 0)
                    ->orWhere('po.actual_reject_quantity', '>', 0);
            });

        $this->applyOrderFilters($query, $request, 'po');

        $rows = $query
            ->select([
                'po.id as production_order_id',
                'po.production_plan_id',
                'po.production_number',
                'po.status',
                'po.actual_reject_quantity',
                'po.actual_waste_quantity',
                'po.completed_at',
                'p.name as material',
                'p.code as material_code',
                'poi.wasted_quantity',
                'poi.unit_cost',
                'poi.currency',
            ])
            ->orderByDesc('po.completed_at')
            ->get()
            ->map(function ($row) {
                $materialWasteCost = (float) $row->wasted_quantity * (float) $row->unit_cost;

                return [
                    'production_order_id' => $row->production_order_id,
                    'production_plan_id' => $row->production_plan_id,
                    'production_number' => $row->production_number,
                    'material' => $row->material,
                    'material_code' => $row->material_code,
                    'input_wasted_quantity' => (float) $row->wasted_quantity,
                    'finished_goods_reject_quantity' => (float) $row->actual_reject_quantity,
                    'finished_goods_waste_quantity' => (float) $row->actual_waste_quantity,
                    'unit_cost' => $this->money($row->unit_cost),
                    'waste_cost' => $this->money($materialWasteCost),
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'completed_at' => $row->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Production wastage report fetched successfully.',
            'data' => [
                'summary' => [
                    'lines' => $rows->count(),
                    'input_wasted_quantity' => $rows->sum('input_wasted_quantity'),
                    'finished_goods_reject_quantity' => $rows->sum('finished_goods_reject_quantity'),
                    'finished_goods_waste_quantity' => $rows->sum('finished_goods_waste_quantity'),
                    'waste_cost' => $this->money($rows->sum('waste_cost')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }

    public function productionPlanHistory(Request $request, string $planId)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $plan = ProductionPlan::where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->where('id', $planId)
            ->with(['items.recipe', 'items.finishedProduct'])
            ->firstOrFail();

        $orders = ProductionOrder::where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->where('production_plan_id', $plan->id)
            ->with([
                'finishedProduct',
                'inputs' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->with('product'),
                'overheads' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds),
                'outputs' => fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->with('product'),
            ])
            ->orderBy('created_at')
            ->get();

        $orderIds = $orders->pluck('id')->values();

        $stockMovements = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->where('sm.company_id', $company->id)
            ->whereIn('sm.branch_id', $branchIds)
            ->where('sm.reference_type', 'production_order')
            ->whereIn('sm.reference_id', $orderIds)
            ->select([
                'sm.id',
                'sm.reference_id as production_order_id',
                'sm.movement_type',
                'sm.quantity_in',
                'sm.quantity_out',
                'sm.unit_cost',
                'sm.total_cost',
                'sm.currency',
                'sm.notes',
                'sm.created_at',
                'p.name as product_name',
                'p.code as product_code',
            ])
            ->orderBy('sm.created_at')
            ->get();

        $materials = $orders->flatMap(function ($order) {
            return ($order->inputs ?? collect())->map(function ($input) use ($order) {
                $issued = (float) $input->issued_quantity;
                $returned = (float) $input->returned_quantity;
                $wasted = (float) $input->wasted_quantity;
                $netConsumed = max($issued - $returned, 0);

                return [
                    'production_order_id' => $order->id,
                    'production_number' => $order->production_number,
                    'material' => $input->product?->name,
                    'material_code' => $input->product?->code,
                    'planned_quantity' => (float) $input->planned_quantity,
                    'reserved_quantity' => (float) $input->reserved_quantity,
                    'issued_quantity' => $issued,
                    'returned_quantity' => $returned,
                    'wasted_quantity' => $wasted,
                    'net_consumed_quantity' => $netConsumed,
                    'unit_cost' => (float) $input->unit_cost,
                    'total_cost' => (float) $input->total_cost,
                    'currency' => $input->currency,
                    'status' => $order->status,
                ];
            });
        })->values();

        $expenses = $orders->flatMap(function ($order) {
            return ($order->overheads ?? collect())->map(function ($overhead) use ($order) {
                $costType = strtolower((string) $overhead->cost_type);

                $accountingClass = in_array($costType, ['labour', 'salary', 'direct_labour'], true)
                    ? 'DIRECT_LABOUR'
                    : 'INDIRECT_OVERHEAD';

                return [
                    'production_order_id' => $order->id,
                    'production_number' => $order->production_number,
                    'cost_type' => $overhead->cost_type,
                    'accounting_class' => $accountingClass,
                    'description' => $overhead->description,
                    'amount' => (float) $overhead->amount,
                    'currency' => $overhead->currency,
                    'created_at' => $overhead->created_at,
                ];
            });
        })->values();

        $finishedGoods = $orders->flatMap(function ($order) {
            return ($order->outputs ?? collect())->map(function ($output) use ($order) {
                return [
                    'production_order_id' => $order->id,
                    'production_number' => $order->production_number,
                    'finished_product' => $output->product?->name ?: $order->finishedProduct?->name,
                    'quantity_received' => (float) $output->quantity_produced,
                    'quantity_rejected' => (float) ($output->quantity_rejected ?? $order->actual_reject_quantity),
                    'quantity_wasted' => (float) ($output->quantity_wasted ?? $order->actual_waste_quantity),
                    'unit_cost' => (float) $output->unit_cost,
                    'total_cost' => (float) $output->total_cost,
                    'currency' => $output->currency,
                    'created_at' => $output->created_at,
                ];
            });
        })->values();

        $accountingLedger = collect();

        foreach ($materials as $line) {
            if ((float) $line['issued_quantity'] > 0) {
                $accountingLedger->push([
                    'date' => null,
                    'production_order_id' => $line['production_order_id'],
                    'production_number' => $line['production_number'],
                    'account' => 'Work In Progress - Materials',
                    'debit' => (float) $line['total_cost'],
                    'credit' => 0,
                    'description' => 'Raw material consumed: ' . $line['material'],
                    'source' => 'MATERIAL_ISSUE',
                    'currency' => $line['currency'],
                ]);

                $accountingLedger->push([
                    'date' => null,
                    'production_order_id' => $line['production_order_id'],
                    'production_number' => $line['production_number'],
                    'account' => 'Raw Materials Inventory',
                    'debit' => 0,
                    'credit' => (float) $line['total_cost'],
                    'description' => 'Raw material issued from stores: ' . $line['material'],
                    'source' => 'MATERIAL_ISSUE',
                    'currency' => $line['currency'],
                ]);
            }
        }

        foreach ($expenses as $expense) {
            $accountingLedger->push([
                'date' => $expense['created_at'],
                'production_order_id' => $expense['production_order_id'],
                'production_number' => $expense['production_number'],
                'account' => $expense['accounting_class'] === 'DIRECT_LABOUR'
                    ? 'Work In Progress - Direct Labour'
                    : 'Work In Progress - Factory Overheads',
                'debit' => (float) $expense['amount'],
                'credit' => 0,
                'description' => $expense['description'] ?: $expense['cost_type'],
                'source' => $expense['accounting_class'],
                'currency' => $expense['currency'],
            ]);
        }

        foreach ($finishedGoods as $fg) {
            $accountingLedger->push([
                'date' => $fg['created_at'],
                'production_order_id' => $fg['production_order_id'],
                'production_number' => $fg['production_number'],
                'account' => 'Finished Goods Inventory',
                'debit' => (float) $fg['total_cost'],
                'credit' => 0,
                'description' => 'Finished goods received: ' . $fg['finished_product'],
                'source' => 'FINISHED_GOODS_RECEIPT',
                'currency' => $fg['currency'],
            ]);

            $accountingLedger->push([
                'date' => $fg['created_at'],
                'production_order_id' => $fg['production_order_id'],
                'production_number' => $fg['production_number'],
                'account' => 'Work In Progress',
                'debit' => 0,
                'credit' => (float) $fg['total_cost'],
                'description' => 'Production completed and transferred to finished goods',
                'source' => 'FINISHED_GOODS_RECEIPT',
                'currency' => $fg['currency'],
            ]);
        }

        return response()->json([
            'message' => 'Production plan history fetched successfully.',
            'data' => [
                'plan' => [
                    'id' => $plan->id,
                    'plan_number' => $plan->plan_number,
                    'name' => $plan->name,
                    'status' => $plan->status,
                    'period_start' => $plan->period_start,
                    'period_end' => $plan->period_end,
                ],

                'summary' => [
                    'orders' => $orders->count(),
                    'planned_quantity' => (float) $orders->sum('planned_output_quantity'),
                    'actual_good_quantity' => (float) $orders->sum('actual_good_quantity'),
                    'actual_reject_quantity' => (float) $orders->sum('actual_reject_quantity'),
                    'actual_waste_quantity' => (float) $orders->sum('actual_waste_quantity'),

                    'direct_material_cost' => $this->money($materials->sum('total_cost')),
                    'direct_labour_cost' => $this->money($expenses->where('accounting_class', 'DIRECT_LABOUR')->sum('amount')),
                    'indirect_overhead_cost' => $this->money($expenses->where('accounting_class', 'INDIRECT_OVERHEAD')->sum('amount')),
                    'finished_goods_value' => $this->money($finishedGoods->sum('total_cost')),
                ],

                'orders' => $orders->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'production_number' => $order->production_number,
                        'finished_product' => $order->finishedProduct?->name,
                        'status' => $order->status,
                        'planned_quantity' => (float) $order->planned_output_quantity,
                        'actual_good_quantity' => (float) $order->actual_good_quantity,
                        'actual_total_cost' => (float) $order->actual_total_cost,
                        'actual_unit_cost' => (float) $order->actual_unit_cost,
                        'created_at' => $order->created_at,
                        'completed_at' => $order->completed_at,
                    ];
                })->values(),

                'material_usage' => $materials,
                'expenses' => $expenses,
                'finished_goods' => $finishedGoods,
                'stock_movements' => $stockMovements,
                'accounting_ledger' => $accountingLedger->values(),
            ],
        ]);
    }

    public function accountingLedger(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        if ($request->filled('production_plan_id')) {
            return $this->productionPlanHistory($request, $request->production_plan_id);
        }

        return response()->json([
            'message' => 'Please provide production_plan_id for accounting ledger.',
            'data' => null,
        ], 422);
    }

    public function materialRequirements(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        $query = MaterialRequirement::where('company_id', $company->id)
            ->whereIn('branch_id', $this->reportBranchIds($request, (string) $company->id))
            ->with('product');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('production_plan_id')) {
            $query->where('production_plan_id', $request->production_plan_id);
        }

        return response()->json([
            'message' => 'Material requirement report fetched successfully.',
            'data' => $query->orderByDesc('created_at')->paginate($request->per_page ?? 50),
        ]);
    }

    public function materialShortageAlerts(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        $query = MaterialRequirement::where('company_id', $company->id)
            ->whereIn('branch_id', $this->reportBranchIds($request, (string) $company->id))
            ->where('shortage_quantity', '>', 0)
            ->with('product');

        if ($request->filled('production_plan_id')) {
            $query->where('production_plan_id', $request->production_plan_id);
        }

        $rows = $query->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'production_plan_id' => $row->production_plan_id,
                'product' => $row->product?->name,
                'product_code' => $row->product?->code,
                'required_quantity' => (float) $row->required_quantity,
                'available_quantity' => (float) $row->available_quantity,
                'shortage_quantity' => (float) $row->shortage_quantity,
                'status' => $row->status,
            ];
        });

        return response()->json([
            'message' => 'Material shortage alerts fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function variance(Request $request)
    {
        [$company, $permission] = $this->canViewReports();
        if ($permission !== true) return $permission;

        $query = ProductionOrder::where('company_id', $company->id)
            ->where('status', 'completed')
            ->with('finishedProduct');

        $this->applyOrderFilters($query, $request);

        $rows = $query
            ->orderByDesc('completed_at')
            ->get()
            ->map(function ($order) {
                $plannedQty = (float) $order->planned_output_quantity;
                $actualGood = (float) $order->actual_good_quantity;
                $plannedCost = (float) $order->planned_total_cost;
                $actualCost = (float) $order->actual_total_cost;

                return [
                    'production_order_id' => $order->id,
                    'production_plan_id' => $order->production_plan_id,
                    'production_number' => $order->production_number,
                    'finished_product' => $order->finishedProduct?->name,

                    'planned_quantity' => $plannedQty,
                    'actual_good_quantity' => $actualGood,
                    'quantity_variance' => $actualGood - $plannedQty,

                    'planned_total_cost' => $plannedCost,
                    'actual_total_cost' => $actualCost,
                    'cost_variance_amount' => $actualCost - $plannedCost,

                    'planned_unit_cost' => (float) $order->planned_unit_cost,
                    'actual_unit_cost' => (float) $order->actual_unit_cost,
                    'unit_cost_variance' => (float) $order->actual_unit_cost - (float) $order->planned_unit_cost,

                    'completed_at' => $order->completed_at,
                ];
            });

        return response()->json([
            'message' => 'Production variance report fetched successfully.',
            'data' => [
                'summary' => [
                    'orders' => $rows->count(),
                    'planned_quantity' => $rows->sum('planned_quantity'),
                    'actual_good_quantity' => $rows->sum('actual_good_quantity'),
                    'quantity_variance' => $rows->sum('quantity_variance'),
                    'planned_total_cost' => $this->money($rows->sum('planned_total_cost')),
                    'actual_total_cost' => $this->money($rows->sum('actual_total_cost')),
                    'cost_variance_amount' => $this->money($rows->sum('cost_variance_amount')),
                ],
                'rows' => $rows->values(),
            ],
        ]);
    }
}
