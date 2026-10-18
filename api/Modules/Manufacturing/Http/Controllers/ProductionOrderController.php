<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Manufacturing\ProductionOrderService;
use App\Services\Manufacturing\ProductionPlanningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderInput;
use Modules\Manufacturing\Models\ProductionOrderOverhead;
use Modules\Manufacturing\Support\ManufacturingAudit;
use function App\Helpers\hasPermission;

class ProductionOrderController extends Controller
{
    use ManufacturingAudit;

    private array $orderRelations = [
        'plan',
        'planItem.finishedProduct',
        'planItem.recipe',
        'finishedProduct.uom',
        'recipe.items.product.uom',
        'inputs.product.uom',
        'outputs.product.uom',
        'overheads',
    ];

    public function index(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_view',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $query = ProductionOrder::where('company_id', $company->id)
            ->with($this->orderRelations)
            ->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('finished_product_id')) {
            $query->where('finished_product_id', $request->finished_product_id);
        }

        if ($request->filled('production_plan_id')) {
            $query->where('production_plan_id', $request->production_plan_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('q')) {
            $q = trim($request->q);

            $query->where(function ($sub) use ($q) {
                $sub->where('production_number', 'like', "%{$q}%")
                    ->orWhere('batch_number', 'like', "%{$q}%")
                    ->orWhereHas('finishedProduct', function ($productQuery) use ($q) {
                        $productQuery->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%");
                    })
                    ->orWhereHas('plan', function ($planQuery) use ($q) {
                        $planQuery->where('plan_number', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%");
                    });
            });
        }

        return response()->json([
            'message' => 'Production orders fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 25),
        ]);
    }

    public function store(Request $request, ProductionPlanningService $planningService)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_create_order',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $availableUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],

            'production_plan_id' => ['nullable', Rule::exists('production_plans', 'id')->where('company_id', $company->id)],
            'production_plan_item_id' => ['nullable', Rule::exists('production_plan_items', 'id')->where('company_id', $company->id)],

            'production_recipe_id' => ['nullable', Rule::exists('production_recipes', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'finished_product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],

            'planned_output_quantity' => 'required|numeric|min:0.0001',
            'currency' => 'required|string|size:3',

            'planned_start_date' => 'nullable|date',
            'planned_end_date' => 'nullable|date|after_or_equal:planned_start_date',
            'batch_number' => 'nullable|string|max:255',

            'inputs' => 'required|array|min:1',
            'inputs.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'inputs.*.uom_id' => ['nullable', 'uuid', $availableUom()],
            'inputs.*.planned_quantity' => 'required|numeric|min:0.0001',
            'inputs.*.unit_cost' => 'nullable|numeric|min:0',

            'overheads' => 'nullable|array',
            'overheads.*.cost_type' => 'required|in:labour,salary,electricity,fuel,water,rent,machine_maintenance,depreciation,other',
            'overheads.*.description' => 'nullable|string|max:255',
            'overheads.*.amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request, $company, $planningService) {
            $currency = strtoupper($request->currency);

            $plannedRawMaterialCost = collect($request->inputs)->sum(function ($input) {
                return (float) ($input['planned_quantity'] ?? 0) * (float) ($input['unit_cost'] ?? 0);
            });

            $plannedOverheadCost = collect($request->overheads ?? [])->sum(function ($overhead) {
                return (float) ($overhead['amount'] ?? 0);
            });

            $plannedLabourCost = collect($request->overheads ?? [])->sum(function ($overhead) {
                return $overhead['cost_type'] === 'labour'
                    ? (float) ($overhead['amount'] ?? 0)
                    : 0;
            });

            $plannedTotalCost = $plannedRawMaterialCost + $plannedOverheadCost;
            $plannedUnitCost = $request->planned_output_quantity > 0
                ? $plannedTotalCost / (float) $request->planned_output_quantity
                : 0;

            $order = ProductionOrder::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,

                'production_plan_id' => $request->production_plan_id,
                'production_plan_item_id' => $request->production_plan_item_id,

                'production_recipe_id' => $request->production_recipe_id,
                'finished_product_id' => $request->finished_product_id,

                'production_number' => $planningService->generateProductionNumber(
                    $company->id,
                    $request->branch_id
                ),

                'status' => 'draft',
                'batch_number' => $request->batch_number,
                'batch_size' => $request->planned_output_quantity,
                'planned_output_quantity' => $request->planned_output_quantity,

                'planned_raw_material_cost' => $plannedRawMaterialCost,
                'planned_labour_cost' => $plannedLabourCost,
                'planned_overhead_cost' => $plannedOverheadCost,
                'planned_total_cost' => $plannedTotalCost,
                'planned_unit_cost' => $plannedUnitCost,

                'currency' => $currency,
                'planned_start_date' => $request->planned_start_date,
                'planned_end_date' => $request->planned_end_date,
                'created_by' => auth()->id(),

                'metadata' => array_merge($request->metadata ?? [], [
                    'source' => $request->production_plan_id ? 'production_plan' : 'manual_order',
                    'frontend_planned_raw_material_cost' => $plannedRawMaterialCost,
                    'frontend_planned_overhead_cost' => $plannedOverheadCost,
                    'frontend_planned_total_cost' => $plannedTotalCost,
                    'frontend_planned_unit_cost' => $plannedUnitCost,
                ]),
            ]);

            foreach ($request->inputs as $input) {
                $unitCost = (float) ($input['unit_cost'] ?? 0);
                $plannedQuantity = (float) ($input['planned_quantity'] ?? 0);
                $totalCost = $unitCost * $plannedQuantity;

                ProductionOrderInput::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $request->branch_id,
                    'production_order_id' => $order->id,

                    'product_id' => $input['product_id'],
                    'uom_id' => $input['uom_id'] ?? null,

                    'planned_quantity' => $plannedQuantity,
                    'reserved_quantity' => 0,
                    'issued_quantity' => 0,
                    'returned_quantity' => 0,
                    'wasted_quantity' => 0,

                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'currency' => $currency,

                    'metadata' => $input['metadata'] ?? [],
                ]);
            }

            foreach ($request->overheads ?? [] as $overhead) {
                ProductionOrderOverhead::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $request->branch_id,
                    'production_order_id' => $order->id,

                    'cost_type' => $overhead['cost_type'],
                    'description' => $overhead['description'] ?? null,
                    'amount' => $overhead['amount'],
                    'currency' => $currency,

                    'metadata' => $overhead['metadata'] ?? [],
                    'created_by' => auth()->id(),
                ]);
            }

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'action' => 'production_order_created',
                'description' => 'Production order created.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $order->id,
                'new_values' => $freshOrder->toArray(),
                'changed_fields' => [
                    'production_orders',
                    'production_order_inputs',
                    'production_order_overheads',
                ],
            ]);

            return response()->json([
                'message' => 'Production order created successfully.',
                'data' => $freshOrder,
            ], 201);
        });
    }

    public function show(string $id)
    {
        $company = app('company');

        $order = ProductionOrder::where('company_id', $company->id)
            ->where('id', $id)
            ->with($this->orderRelations)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Production order not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Production order fetched successfully.',
            'data' => $order,
        ]);
    }

    public function approve(Request $request, string $id)
    {
        return $this->statusChange(
            $request,
            $id,
            'approved',
            'manufacturing_approve_order',
            'production_order_approved',
            ['draft']
        );
    }

    public function reserveMaterials(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_issue_materials',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if ($order->status !== 'approved') {
            return response()->json(['message' => 'Only approved orders can reserve materials.'], 422);
        }

        try {
            $old = $order->toArray();

            $service->reserveMaterials($order);

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $freshOrder->branch_id,
                'action' => 'production_materials_reserved',
                'description' => 'Materials reserved for production order.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $freshOrder->id,
                'old_values' => $old,
                'new_values' => $freshOrder->toArray(),
                'changed_fields' => [
                    'status',
                    'production_order_inputs.reserved_quantity',
                    'product_stocks.reserved_quantity',
                    'product_stocks.available_quantity',
                ],
            ]);

            return response()->json([
                'message' => 'Production materials reserved successfully.',
                'data' => $freshOrder,
            ]);
        } catch (\Throwable $e) {
            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'action' => 'production_materials_reserve_failed',
                'description' => 'Failed to reserve production materials.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $order->id,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to reserve materials.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function issueMaterials(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_issue_materials',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if (!in_array($order->status, ['approved', 'materials_reserved'], true)) {
            return response()->json([
                'message' => 'Only approved or reserved orders can issue materials.',
            ], 422);
        }

        try {
            $old = $order->toArray();

            $service->issueMaterials($order);

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $freshOrder->branch_id,
                'action' => 'production_materials_issued',
                'description' => 'Raw materials issued to production.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $freshOrder->id,
                'old_values' => $old,
                'new_values' => $freshOrder->toArray(),
                'changed_fields' => [
                    'status',
                    'production_order_inputs.issued_quantity',
                    'product_stocks',
                    'stock_movements',
                ],
            ]);

            return response()->json([
                'message' => 'Production materials issued successfully.',
                'data' => $freshOrder,
            ]);
        } catch (\Throwable $e) {
            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'action' => 'production_materials_issue_failed',
                'description' => 'Failed to issue production materials.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $order->id,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to issue materials.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function start(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_complete_order',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if ($order->status !== 'materials_issued') {
            return response()->json([
                'message' => 'Only material-issued orders can be started.',
            ], 422);
        }

        $old = $order->toArray();

        $service->start($order);

        $freshOrder = $this->freshOrder($order->id);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $freshOrder->branch_id,
            'action' => 'production_order_started',
            'description' => 'Production order started.',
            'auditable_type' => ProductionOrder::class,
            'auditable_id' => $freshOrder->id,
            'old_values' => $old,
            'new_values' => $freshOrder->toArray(),
            'changed_fields' => ['status', 'started_at'],
        ]);

        return response()->json([
            'message' => 'Production order started successfully.',
            'data' => $freshOrder,
        ]);
    }

    public function addOverhead(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_add_overheads',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'cost_type' => 'required|in:labour,salary,electricity,fuel,water,rent,machine_maintenance,depreciation,other',
            'description' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'expense_id' => 'nullable|string|max:36',
            'payroll_id' => 'nullable|string|max:36',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Cannot add overhead to completed or cancelled orders.',
            ], 422);
        }

        $overhead = $service->addOverhead($order, $request->all());

        $freshOrder = $this->freshOrder($order->id);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $order->branch_id,
            'action' => 'production_overhead_added',
            'description' => 'Production overhead added.',
            'auditable_type' => ProductionOrderOverhead::class,
            'auditable_id' => $overhead->id,
            'new_values' => $overhead->toArray(),
            'changed_fields' => ['production_order_overheads'],
        ]);

        return response()->json([
            'message' => 'Production overhead added successfully.',
            'data' => $freshOrder,
            'overhead' => $overhead,
        ], 201);
    }

    public function returnMaterial(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_issue_materials',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'input_id' => 'required|string|max:36',
            'quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        try {
            $input = $service->returnMaterial(
                $order,
                $request->input_id,
                (float) $request->quantity,
                $request->notes
            );

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'action' => 'production_material_returned',
                'description' => 'Unused material returned from production.',
                'auditable_type' => ProductionOrderInput::class,
                'auditable_id' => $input->id,
                'new_values' => $input->toArray(),
                'changed_fields' => [
                    'returned_quantity',
                    'product_stocks',
                    'stock_movements',
                ],
            ]);

            return response()->json([
                'message' => 'Production material returned successfully.',
                'data' => $freshOrder,
                'input' => $input,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to return material.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function recordWastage(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_complete_order',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'input_id' => 'required|string|max:36',
            'quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        try {
            $input = $service->recordWastage(
                $order,
                $request->input_id,
                (float) $request->quantity,
                $request->notes
            );

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'action' => 'production_material_wastage_recorded',
                'description' => 'Production material wastage recorded.',
                'auditable_type' => ProductionOrderInput::class,
                'auditable_id' => $input->id,
                'new_values' => $input->toArray(),
                'changed_fields' => [
                    'wasted_quantity',
                    'stock_movements',
                ],
            ]);

            return response()->json([
                'message' => 'Production wastage recorded successfully.',
                'data' => $freshOrder,
                'input' => $input,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to record wastage.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function complete(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_complete_order',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'actual_good_quantity' => 'required|numeric|min:0.0001',
            'actual_reject_quantity' => 'nullable|numeric|min:0',
            'actual_waste_quantity' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if (!in_array($order->status, ['materials_issued', 'in_progress'], true)) {
            return response()->json([
                'message' => 'Only material-issued or in-progress orders can be completed.',
            ], 422);
        }

        try {
            $old = $order->load(['inputs', 'overheads'])->toArray();

            $service->complete(
                $order,
                (float) $request->actual_good_quantity,
                (float) ($request->actual_reject_quantity ?? 0),
                (float) ($request->actual_waste_quantity ?? 0)
            );

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $freshOrder->branch_id,
                'action' => 'production_order_completed',
                'description' => 'Production order completed and finished goods received into stock.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $freshOrder->id,
                'old_values' => $old,
                'new_values' => $freshOrder->toArray(),
                'changed_fields' => [
                    'status',
                    'actual_good_quantity',
                    'actual_unit_cost',
                    'production_order_outputs',
                    'product_stocks',
                    'product_costs',
                    'stock_movements',
                ],
            ]);

            return response()->json([
                'message' => 'Production order completed successfully.',
                'data' => $freshOrder,
            ]);
        } catch (\Throwable $e) {
            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'action' => 'production_order_complete_failed',
                'description' => 'Failed to complete production order.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $order->id,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to complete production.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(Request $request, string $id, ProductionOrderService $service)
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_cancel_order',
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        try {
            $old = $order->load('inputs')->toArray();

            $service->cancel($order);

            $freshOrder = $this->freshOrder($order->id);

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $freshOrder->branch_id,
                'action' => 'production_order_cancelled',
                'description' => 'Production order cancelled.',
                'auditable_type' => ProductionOrder::class,
                'auditable_id' => $freshOrder->id,
                'old_values' => $old,
                'new_values' => $freshOrder->toArray(),
                'changed_fields' => [
                    'status',
                    'reserved_quantity',
                ],
            ]);

            return response()->json([
                'message' => 'Production order cancelled successfully.',
                'data' => $freshOrder,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to cancel production order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function statusChange(
        Request $request,
        string $id,
        string $status,
        string $permissionName,
        string $auditAction,
        array $allowedFrom
    ) {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            $permissionName,
            $company->id
        );

        if ($permission !== true) {
            return $permission;
        }

        $order = $this->findOrder($company->id, $id);

        if (!$order) {
            return response()->json(['message' => 'Production order not found.'], 404);
        }

        if (!in_array($order->status, $allowedFrom, true)) {
            return response()->json([
                'message' => 'Invalid production order status transition.',
            ], 422);
        }

        $old = $order->toArray();

        $order->status = $status;

        if ($status === 'approved') {
            $order->approved_by = auth()->id();
        }

        $order->save();

        $freshOrder = $this->freshOrder($order->id);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $freshOrder->branch_id,
            'action' => $auditAction,
            'description' => 'Production order status changed to ' . $status . '.',
            'auditable_type' => ProductionOrder::class,
            'auditable_id' => $freshOrder->id,
            'old_values' => $old,
            'new_values' => $freshOrder->toArray(),
            'changed_fields' => ['status'],
        ]);

        return response()->json([
            'message' => 'Production order status updated successfully.',
            'data' => $freshOrder,
        ]);
    }

    private function findOrder(string $companyId, string $id): ?ProductionOrder
    {
        return ProductionOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->first();
    }

    private function freshOrder(string $id): ProductionOrder
    {
        return ProductionOrder::with($this->orderRelations)->findOrFail($id);
    }
}
