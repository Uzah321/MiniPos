<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Manufacturing\MaterialRequirementService;
use App\Services\Manufacturing\ProductionPlanningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Support\ManufacturingAudit;
use function App\Helpers\hasPermission;

use Modules\Manufacturing\Models\ProductionPlan;


class ProductionPlanController extends Controller
{
    use ManufacturingAudit;

    public function index(Request $request)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'manufacturing_view', $company->id);
        if ($permission !== true) return $permission;

        $query = ProductionPlan::where('company_id', $company->id)
            ->with(['items.finishedProduct', 'items.recipe', 'materialRequirements.product'])
            ->latest();

        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('date_from')) $query->whereDate('period_start', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('period_end', '<=', $request->date_to);

        return response()->json(['message' => 'Production plans fetched successfully.', 'data' => $query->paginate($request->per_page ?? 25)]);
    }

    public function store(Request $request, ProductionPlanningService $planningService)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'manufacturing_create_plan', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'name' => 'required|string|max:255',
            'planning_basis' => 'required|in:manual,sales_forecast,sales_order,minimum_stock,reorder_level',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'items' => 'required|array|min:1',
            'items.*.finished_product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'items.*.production_recipe_id' => ['required', Rule::exists('production_recipes', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'items.*.forecast_quantity' => 'nullable|numeric|min:0',
            'items.*.safety_stock_quantity' => 'nullable|numeric|min:0',
            'items.*.planned_quantity' => 'nullable|numeric|min:0.0001',
            'items.*.planned_labour_cost' => 'nullable|numeric|min:0',
            'items.*.planned_overhead_cost' => 'nullable|numeric|min:0',
            'items.*.required_by' => 'nullable|date',
        ]);

        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);

        return DB::transaction(function () use ($request, $company, $planningService) {
            $plan = ProductionPlan::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'plan_number' => $this->generatePlanNumber($company->id, $request->branch_id),
                'name' => $request->name,
                'planning_basis' => $request->planning_basis,
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'metadata' => $request->metadata ?? [],
            ]);

            foreach ($request->items as $item) {
                $planningService->createPlanItemFromRequest($plan, $item);
            }

            $this->writeManufacturingAudit($request, [
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'action' => 'production_plan_created',
                'description' => 'Production plan created.',
                'auditable_type' => ProductionPlan::class,
                'auditable_id' => $plan->id,
                'new_values' => $plan->fresh(['items'])->toArray(),
                'changed_fields' => ['production_plans', 'production_plan_items'],
            ]);

            return response()->json(['message' => 'Production plan created successfully.', 'data' => $plan->fresh(['items.finishedProduct', 'items.recipe'])], 201);
        });
    }

    public function show(string $id)
    {
        $company = app('company');
        $plan = ProductionPlan::where('company_id', $company->id)
            ->where('id', $id)
            ->with(['items.finishedProduct', 'items.recipe.items.product', 'materialRequirements.product', 'productionOrders'])
            ->first();
        if (!$plan) return response()->json(['message' => 'Production plan not found.'], 404);
        return response()->json(['message' => 'Production plan fetched successfully.', 'data' => $plan]);
    }

    public function approve(Request $request, string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'manufacturing_approve_plan', $company->id);
        if ($permission !== true) return $permission;

        $plan = ProductionPlan::where('company_id', $company->id)->where('id', $id)->first();
        if (!$plan) return response()->json(['message' => 'Production plan not found.'], 404);
        if ($plan->status !== 'draft') return response()->json(['message' => 'Only draft plans can be approved.'], 422);

        $old = $plan->toArray();
        $plan->update(['status' => 'approved', 'approved_by' => auth()->id()]);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $plan->branch_id,
            'action' => 'production_plan_approved',
            'description' => 'Production plan approved.',
            'auditable_type' => ProductionPlan::class,
            'auditable_id' => $plan->id,
            'old_values' => $old,
            'new_values' => $plan->fresh()->toArray(),
            'changed_fields' => ['status', 'approved_by'],
        ]);

        return response()->json(['message' => 'Production plan approved successfully.', 'data' => $plan->fresh()]);
    }

    public function calculateMaterials(Request $request, string $id, MaterialRequirementService $service)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'manufacturing_create_plan', $company->id);
        if ($permission !== true) return $permission;

        $plan = ProductionPlan::where('company_id', $company->id)->where('id', $id)->first();
        if (!$plan) return response()->json(['message' => 'Production plan not found.'], 404);

        $requirements = $service->calculateForPlan($plan);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $plan->branch_id,
            'action' => 'production_material_requirements_calculated',
            'description' => 'Material requirements calculated for production plan.',
            'auditable_type' => ProductionPlan::class,
            'auditable_id' => $plan->id,
            'new_values' => collect($requirements)->map->toArray()->values()->all(),
            'changed_fields' => ['material_requirements'],
        ]);

        return response()->json(['message' => 'Material requirements calculated successfully.', 'data' => $plan->fresh(['materialRequirements.product'])]);
    }

    public function convertToOrders(Request $request, string $id, ProductionPlanningService $service)
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

        $plan = ProductionPlan::where('company_id', $company->id)
            ->where('id', $id)
            ->with(['items.recipe.items', 'materialRequirements'])
            ->first();

        if (!$plan) {
            return response()->json([
                'message' => 'Production plan not found.',
            ], 404);
        }

        if ($plan->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved production plans can be converted to production orders.',
            ], 422);
        }

        if (!$plan->items()->exists()) {
            return response()->json([
                'message' => 'This production plan has no plan items to convert.',
            ], 422);
        }

        if (!$plan->materialRequirements()->exists()) {
            return response()->json([
                'message' => 'Calculate material requirements before converting this plan.',
            ], 422);
        }

        $hasShortage = $plan->materialRequirements()
            ->where('shortage_quantity', '>', 0)
            ->exists();

        if ($hasShortage) {
            return response()->json([
                'message' => 'Cannot convert this plan because some material requirements still have shortages.',
            ], 422);
        }

        $orders = $service->convertPlanToProductionOrders($plan);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $plan->branch_id,
            'action' => 'production_plan_converted_to_orders',
            'description' => 'Approved production plan converted to production orders.',
            'auditable_type' => ProductionPlan::class,
            'auditable_id' => $plan->id,
            'new_values' => collect($orders)->map->toArray()->values()->all(),
            'changed_fields' => [
                'production_plans.status',
                'production_orders',
                'production_order_inputs',
            ],
        ]);

        return response()->json([
            'message' => 'Production plan converted to orders successfully.',
            'data' => $plan->fresh(['productionOrders.inputs', 'productionOrders.finishedProduct']),
        ]);
    }

    public function cancel(Request $request, string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'manufacturing_approve_plan', $company->id);
        if ($permission !== true) return $permission;

        $plan = ProductionPlan::where('company_id', $company->id)->where('id', $id)->first();
        if (!$plan) return response()->json(['message' => 'Production plan not found.'], 404);
        if ($plan->status === 'converted_to_orders') return response()->json(['message' => 'Converted plans cannot be cancelled from here. Cancel orders individually.'], 422);

        $old = $plan->toArray();
        $plan->update(['status' => 'cancelled']);

        $this->writeManufacturingAudit($request, [
            'company_id' => $company->id,
            'branch_id' => $plan->branch_id,
            'action' => 'production_plan_cancelled',
            'description' => 'Production plan cancelled.',
            'auditable_type' => ProductionPlan::class,
            'auditable_id' => $plan->id,
            'old_values' => $old,
            'new_values' => $plan->fresh()->toArray(),
            'changed_fields' => ['status'],
        ]);

        return response()->json(['message' => 'Production plan cancelled successfully.', 'data' => $plan->fresh()]);
    }

    private function generatePlanNumber(string $companyId, string $branchId): string
    {
        $date = now()->format('ymd');
        $count = ProductionPlan::where('company_id', $companyId)->where('branch_id', $branchId)->whereDate('created_at', now()->toDateString())->count() + 1;
        do {
            $number = 'PLAN-' . $date . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
            $exists = ProductionPlan::where('plan_number', $number)->exists();
            $count++;
        } while ($exists);
        return $number;
    }
}
