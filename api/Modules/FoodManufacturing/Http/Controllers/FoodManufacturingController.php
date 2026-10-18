<?php

namespace Modules\FoodManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\FoodManufacturing\FoodManufacturingService;
use App\Support\FiltersTableColumns;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\FoodManufacturing\Exports\FoodManufacturingProductionReportExport;
use Modules\FoodManufacturing\Models\FoodManufacturingBatch;
use Modules\FoodManufacturing\Models\FoodManufacturingProductionPlan;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;

class FoodManufacturingController extends Controller
{
    use FiltersTableColumns;

    public function index(Request $request): JsonResponse
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $query = FoodManufacturingBatch::query()
            ->where('company_id', $company->id)
            ->with($this->batchDetailRelations());

        if ($branchId) { $query->where('branch_id', $branchId); }
        if ($request->filled('recipe_id')) { $query->where('food_recipe_id', $request->recipe_id); }
        if ($request->filled('produced_product_id')) { $query->where('produced_product_id', $request->produced_product_id); }
        if ($request->filled('source_stock_area')) { $query->where('source_stock_area', $request->source_stock_area); }
        if ($request->filled('destination_stock_area')) { $query->where('destination_stock_area', $request->destination_stock_area); }
        if ($request->filled('date_from')) { $query->whereDate('created_at', '>=', $request->date_from); }
        if ($request->filled('date_to')) { $query->whereDate('created_at', '<=', $request->date_to); }

        return response()->json([
            'message' => 'Food manufacturing batches fetched successfully.',
            'data' => $query->latest()->paginate((int) $request->get('per_page', 25)),
        ]);
    }

    public function store(Request $request, FoodManufacturingService $service): JsonResponse
    {
        $company = app('company');
        $user = auth()->user();
        $companyBranch = fn () => Rule::exists('company_branches', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyRecipe = fn () => Rule::exists('food_recipes', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyProduct = fn () => Rule::exists('products', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyPlan = fn () => Rule::exists('food_manufacturing_production_plans', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'uuid', $companyBranch()],
            'recipe_id' => ['required', 'uuid', $companyRecipe()],
            'produced_product_id' => ['required', 'uuid', $companyProduct()],
            'source_stock_area' => 'nullable|in:front,back',
            'destination_stock_area' => 'nullable|in:front,back',
            'stock_area' => 'nullable|in:front,back',
            'planned_yield_quantity' => 'nullable|numeric|min:0',
            'actual_yield_quantity' => 'required|numeric|min:0.000001',
            'notes' => 'nullable|string',
            'plan_id' => ['nullable', 'uuid', $companyPlan()],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $branchId = $this->resolveBranchId($request, true);
        if (!$branchId) {
            return response()->json(['message' => 'Branch is required.'], 422);
        }

        $operation = DB::transaction(function () use ($request, $company, $branchId, $service) {
            $plan = null;
            if ($request->filled('plan_id')) {
                // Lock the plan before posting stock so two browser retries cannot
                // manufacture the same plan twice.
                $plan = FoodManufacturingProductionPlan::where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('id', $request->plan_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $plan->food_recipe_id !== (string) $request->recipe_id
                    || (string) $plan->produced_product_id !== (string) $request->produced_product_id) {
                    return ['response' => response()->json([
                        'message' => 'The selected production plan does not match this recipe and output product.',
                        'code' => 'PRODUCTION_PLAN_MISMATCH',
                    ], 422)];
                }

                if (in_array($plan->status, ['produced', 'closed', 'cancelled'], true)) {
                    return ['response' => response()->json([
                        'message' => 'This production plan has already been completed or closed.',
                        'code' => 'PRODUCTION_PLAN_NOT_OPEN',
                    ], 409)];
                }
            }

            $batch = $service->recordManufacturingBatch(
                companyId: $company->id,
                branchId: $branchId,
                recipeId: $request->recipe_id,
                actualYieldQuantity: (float) $request->actual_yield_quantity,
                notes: $request->notes,
                options: [
                    'produced_product_id' => $request->produced_product_id,
                    'source_stock_area' => $request->source_stock_area ?: $request->stock_area ?: 'back',
                    'destination_stock_area' => $request->destination_stock_area ?: 'back',
                    'planned_yield_quantity' => (float) ($request->planned_yield_quantity ?: $request->actual_yield_quantity),
                    'expected_recipe_version_no' => $plan?->recipe_version_no,
                ]
            );

            if ($plan) {
                $plan->update(['status' => 'produced', 'actual_quantity' => $batch->actual_yield_quantity]);
            }

            return ['batch' => $batch];
        });

        if (isset($operation['response'])) {
            return $operation['response'];
        }

        $batch = $operation['batch'];

        return response()->json([
            'message' => 'Food manufacturing batch recorded successfully.',
            'data' => $batch,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $company = app('company');
        $branchId = $this->resolveBranchId(request());

        $batch = FoodManufacturingBatch::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('id', $id)
            ->with($this->batchDetailRelations())
            ->firstOrFail();

        return response()->json(['message' => 'Food manufacturing batch fetched successfully.', 'data' => $batch]);
    }

    public function report(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Food manufacturing production report fetched successfully.',
            'data' => $this->buildReportPayload($request),
        ]);
    }

    public function downloadExcel(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadExcel(
            $this->buildReportPayload($request),
            'food-manufacturing-production-' . now()->format('Ymd-His') . '.xls'
        );
    }

    public function downloadPdf(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadPdf(
            $this->buildReportPayload($request),
            'food-manufacturing-production-' . now()->format('Ymd-His') . '.pdf'
        );
    }

    public function materialPlan(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Production material plan calculated successfully.',
            'data' => $this->buildMaterialPlanPayload($request),
        ]);
    }

    public function saveMaterialPlan(Request $request): JsonResponse
    {
        $company = app('company');
        $user = auth()->user();
        $companyBranch = fn () => Rule::exists('company_branches', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyRecipe = fn () => Rule::exists('food_recipes', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyProduct = fn () => Rule::exists('products', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'uuid', $companyBranch()],
            'recipe_id' => ['required', 'uuid', $companyRecipe()],
            'produced_product_id' => ['required', 'uuid', $companyProduct()],
            'planned_quantity' => 'required|numeric|min:0.000001',
            'source_stock_area' => 'required|in:front,back',
            'destination_stock_area' => 'required|in:front,back',
            'planned_for' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $branchId = $this->resolveBranchId($request, true);
        if (!$branchId) {
            return response()->json(['message' => 'Branch is required.'], 422);
        }

        $planPayload = $this->buildMaterialPlanPayload($request->merge(['branch_id' => $branchId]));
        $summary = $planPayload['summary'] ?? [];
        $recipe = $planPayload['recipe'] ?? [];
        $configuredOutputId = $recipe['produced_product_id'] ?? null;
        if ($configuredOutputId && (string) $configuredOutputId !== (string) $request->produced_product_id) {
            return response()->json([
                'message' => 'The selected output product does not match the versioned BOM output.',
                'code' => 'BOM_OUTPUT_MISMATCH',
            ], 422);
        }

        $plan = FoodManufacturingProductionPlan::create($this->onlyExistingTableColumns('food_manufacturing_production_plans', [
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'plan_number' => $this->nextPlanNumber($company->id, $branchId),
            'food_recipe_id' => $request->recipe_id,
            'recipe_version_no' => (int) ($recipe['version_no'] ?? 0) ?: null,
            'produced_product_id' => $request->produced_product_id,
            'source_stock_area' => $request->source_stock_area,
            'destination_stock_area' => $request->destination_stock_area,
            'planned_quantity' => (float) $request->planned_quantity,
            'status' => ((bool) ($summary['can_produce'] ?? false)) ? 'ready' : 'shortage',
            'currency' => $summary['currency_code'] ?? 'USD',
            'required_value' => (float) ($summary['required_value'] ?? 0),
            'shortage_value' => (float) ($summary['shortage_value'] ?? 0),
            'shortage_line_count' => (int) ($summary['shortage_line_count'] ?? 0),
            'can_produce' => (bool) ($summary['can_produce'] ?? false),
            'planned_for' => $request->planned_for,
            'notes' => $request->notes,
            'summary' => $summary + ['recipe_name' => $recipe['name'] ?? null],
            'lines' => $planPayload['lines'] ?? [],
            'exploded_lines' => $planPayload['exploded_lines'] ?? [],
            'subrecipe_requirements' => $planPayload['subrecipes'] ?? [],
            'created_by' => $user?->id,
        ]));

        return response()->json([
            'message' => 'Production plan saved successfully.',
            'data' => $plan->fresh(['recipe', 'producedProduct', 'branch']),
        ], 201);
    }

    public function plans(Request $request): JsonResponse
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $query = FoodManufacturingProductionPlan::query()
            ->where('company_id', $company->id)
            ->with(['recipe', 'producedProduct', 'branch']);

        if ($branchId) { $query->where('branch_id', $branchId); }
        if ($request->filled('recipe_id')) { $query->where('food_recipe_id', $request->recipe_id); }
        if ($request->filled('produced_product_id')) { $query->where('produced_product_id', $request->produced_product_id); }
        if ($request->filled('status')) { $query->where('status', $request->status); }
        if ($request->filled('date_from')) { $query->whereDate('created_at', '>=', $request->date_from); }
        if ($request->filled('date_to')) { $query->whereDate('created_at', '<=', $request->date_to); }

        return response()->json([
            'message' => 'Production plans fetched successfully.',
            'data' => $query->latest()->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    public function showPlan(string $id): JsonResponse
    {
        $company = app('company');
        $branchId = $this->resolveBranchId(request());

        $plan = FoodManufacturingProductionPlan::where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('id', $id)
            ->with(['recipe', 'producedProduct', 'branch'])
            ->firstOrFail();

        return response()->json(['message' => 'Production plan fetched successfully.', 'data' => $plan]);
    }

    public function materialPlanExcel(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadPlanningExcel(
            $this->buildMaterialPlanPayload($request),
            'food-manufacturing-material-plan-' . now()->format('Ymd-His') . '.xls'
        );
    }

    public function materialPlanPdf(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadPlanningPdf(
            $this->buildMaterialPlanPayload($request),
            'food-manufacturing-material-plan-' . now()->format('Ymd-His') . '.pdf'
        );
    }

    public function deletePlan(string $id): JsonResponse
    {
        $company = app('company');
        $branchId = $this->resolveBranchId(request());

        $plan = FoodManufacturingProductionPlan::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('id', $id)
            ->firstOrFail();

        if (in_array($plan->status, ['produced', 'closed'], true)) {
            return response()->json([
                'message' => 'This production plan cannot be deleted because it has already been produced or closed.',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Production plan deleted successfully.',
        ]);
    }

    public function plansExcel(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadSavedPlansExcel(
            $this->buildSavedPlansPayload($request),
            'food-manufacturing-saved-plans-' . now()->format('Ymd-His') . '.xls'
        );
    }

    public function plansPdf(Request $request)
    {
        return FoodManufacturingProductionReportExport::downloadSavedPlansPdf(
            $this->buildSavedPlansPayload($request),
            'food-manufacturing-saved-plans-' . now()->format('Ymd-His') . '.pdf'
        );
    }

    private function buildSavedPlansPayload(Request $request): array
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $query = FoodManufacturingProductionPlan::query()
            ->where('company_id', $company->id)
            ->with(['recipe', 'producedProduct', 'branch']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('recipe_id')) {
            $query->where('food_recipe_id', $request->recipe_id);
        }

        if ($request->filled('produced_product_id')) {
            $query->where('produced_product_id', $request->produced_product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('source_stock_area')) {
            $query->where('source_stock_area', $request->source_stock_area);
        }

        if ($request->filled('destination_stock_area')) {
            $query->where('destination_stock_area', $request->destination_stock_area);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $plans = $query->latest()->limit(1000)->get()->map(function ($plan) {
            return [
                'id' => $plan->id,
                'plan_number' => $plan->plan_number,
                'created_at' => optional($plan->created_at)->format('Y-m-d H:i:s'),
                'planned_for' => $plan->planned_for,
                'branch_name' => $plan->branch?->name ?? $plan->branch?->branch_name,
                'recipe_id' => $plan->food_recipe_id,
                'recipe_name' => $plan->recipe?->name,
                'produced_product_id' => $plan->produced_product_id,
                'produced_product_name' => $plan->producedProduct?->name,
                'source_stock_area' => $plan->source_stock_area,
                'destination_stock_area' => $plan->destination_stock_area,
                'planned_quantity' => (float) $plan->planned_quantity,
                'actual_quantity' => (float) ($plan->actual_quantity ?? 0),
                'status' => $plan->status,
                'can_produce' => (bool) $plan->can_produce,
                'required_value' => (float) $plan->required_value,
                'shortage_value' => (float) $plan->shortage_value,
                'shortage_line_count' => (int) $plan->shortage_line_count,
                'currency' => $plan->currency ?: 'USD',
                'notes' => $plan->notes,
                'lines' => $plan->lines ?: [],
                'summary' => $plan->summary ?: [],
            ];
        })->values();

        return [
            'filters' => [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'recipe_id' => $request->input('recipe_id'),
                'produced_product_id' => $request->input('produced_product_id'),
                'status' => $request->input('status'),
                'source_stock_area' => $request->input('source_stock_area'),
                'destination_stock_area' => $request->input('destination_stock_area'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            'summary' => [
                'plan_count' => $plans->count(),
                'ready_count' => $plans->where('status', 'ready')->count(),
                'shortage_count' => $plans->where('status', 'shortage')->count(),
                'produced_count' => $plans->where('status', 'produced')->count(),
                'planned_quantity' => round($plans->sum('planned_quantity'), 6),
                'actual_quantity' => round($plans->sum('actual_quantity'), 6),
                'required_value' => round($plans->sum('required_value'), 6),
                'shortage_value' => round($plans->sum('shortage_value'), 6),
                'currency_code' => $plans->pluck('currency')->filter()->unique()->count() === 1
                    ? $plans->pluck('currency')->filter()->first()
                    : 'MULTI',
            ],
            'plans' => $plans->all(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function buildReportPayload(Request $request): array
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);
        $dateFrom = $request->input('date_from') ?: now()->startOfMonth()->toDateString();
        $dateTo = $request->input('date_to') ?: now()->toDateString();

        $batchQuery = FoodManufacturingBatch::query()
            ->where('company_id', $company->id)
            ->with($this->batchDetailRelations())
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        if ($branchId) { $batchQuery->where('branch_id', $branchId); }
        if ($request->filled('recipe_id')) { $batchQuery->where('food_recipe_id', $request->recipe_id); }
        if ($request->filled('produced_product_id')) { $batchQuery->where('produced_product_id', $request->produced_product_id); }
        if ($request->filled('source_stock_area')) { $batchQuery->where('source_stock_area', $request->source_stock_area); }
        if ($request->filled('destination_stock_area')) { $batchQuery->where('destination_stock_area', $request->destination_stock_area); }

        $batchModels = $batchQuery->latest()->get();
        $batches = [];
        $lines = [];

        foreach ($batchModels as $batch) {
            $planned = (float) ($batch->planned_yield_quantity ?? 0);
            $actual = (float) ($batch->actual_yield_quantity ?? 0);
            $batchNumber = $this->batchNumber($batch);

            $batches[] = [
                'id' => $batch->id,
                'batch_number' => $batchNumber,
                'created_at' => optional($batch->created_at)->format('Y-m-d H:i:s'),
                'branch_name' => $batch->branch?->name ?? $batch->branch?->branch_name,
                'recipe_id' => $batch->food_recipe_id,
                'recipe_version_no' => (int) ($batch->recipe_version_no ?: $batch->recipe?->version_no),
                'recipe_name' => $batch->recipe?->name,
                'recipe_type' => $batch->recipe?->recipe_type,
                'produced_product_id' => $batch->produced_product_id,
                'produced_product_name' => $batch->producedProduct?->name,
                'produced_product_code' => $batch->producedProduct?->code,
                'produced_product_type' => $batch->producedProduct?->product_type,
                'source_stock_area' => $batch->source_stock_area,
                'destination_stock_area' => $batch->destination_stock_area,
                'planned_yield_quantity' => round($planned, 6),
                'actual_yield_quantity' => round($actual, 6),
                'yield_variance' => round($actual - $planned, 6),
                'yield_variance_percent' => $planned > 0 ? round((($actual - $planned) / $planned) * 100, 6) : 0,
                'ingredient_cost' => (float) $batch->ingredient_cost,
                'wastage_cost' => (float) $batch->wastage_cost,
                'packaging_cost' => (float) $batch->packaging_cost,
                'labour_cost' => (float) $batch->labour_cost,
                'overhead_cost' => (float) $batch->overhead_cost,
                'total_cost' => (float) $batch->total_cost,
                'unit_cost' => (float) $batch->unit_cost,
                'currency' => $batch->currency ?: 'USD',
                'notes' => $batch->notes,
            ];

            foreach ($batch->lines as $line) {
                $lines[] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batchNumber,
                    'recipe_name' => $batch->recipe?->name,
                    'ingredient_product_id' => $line->ingredient_product_id,
                    'ingredient_name' => $line->ingredientProduct?->name,
                    'ingredient_code' => $line->ingredientProduct?->code,
                    'ingredient_type' => $line->ingredientProduct?->product_type,
                    'line_type' => $line->line_type ?: 'ingredient',
                    'uom' => $line->uom?->code ?: $line->uom?->name ?: $line->ingredientProduct?->uom?->code ?: $line->ingredientProduct?->uom?->name,
                    'stock_area' => $line->stock_area,
                    'net_quantity' => (float) $line->net_quantity,
                    'wastage_percent' => (float) $line->wastage_percent,
                    'wastage_quantity' => (float) $line->wastage_quantity,
                    'gross_quantity' => (float) $line->gross_quantity,
                    'unit_cost' => (float) $line->unit_cost,
                    'line_cost' => (float) $line->line_cost,
                    'currency' => $line->currency ?: $batch->currency ?: 'USD',
                ];
            }
        }

        $plans = FoodManufacturingProductionPlan::where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->with(['recipe', 'producedProduct'])
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'plan_number' => $plan->plan_number,
                'created_at' => optional($plan->created_at)->format('Y-m-d H:i:s'),
                'recipe_name' => $plan->recipe?->name,
                'produced_product_name' => $plan->producedProduct?->name,
                'planned_quantity' => (float) $plan->planned_quantity,
                'source_stock_area' => $plan->source_stock_area,
                'destination_stock_area' => $plan->destination_stock_area,
                'status' => $plan->status,
                'shortage_line_count' => (int) $plan->shortage_line_count,
                'shortage_value' => (float) $plan->shortage_value,
                'required_value' => (float) $plan->required_value,
                'currency' => $plan->currency,
            ])->values()->all();

        $totalCost = collect($batches)->sum('total_cost');
        $actualYield = collect($batches)->sum('actual_yield_quantity');
        $plannedYield = collect($batches)->sum('planned_yield_quantity');
        $currency = collect($batches)->pluck('currency')->filter()->unique()->count() === 1
            ? collect($batches)->pluck('currency')->filter()->first()
            : 'MULTI';

        return [
            'filters' => [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'date_from' => Carbon::parse($dateFrom)->toDateString(),
                'date_to' => Carbon::parse($dateTo)->toDateString(),
                'recipe_id' => $request->input('recipe_id'),
                'produced_product_id' => $request->input('produced_product_id'),
                'source_stock_area' => $request->input('source_stock_area'),
                'destination_stock_area' => $request->input('destination_stock_area'),
            ],
            'summary' => [
                'batch_count' => count($batches),
                'plan_count' => count($plans),
                'planned_yield' => round($plannedYield, 6),
                'actual_yield' => round($actualYield, 6),
                'yield_variance' => round($actualYield - $plannedYield, 6),
                'yield_variance_percent' => $plannedYield > 0 ? round((($actualYield - $plannedYield) / $plannedYield) * 100, 6) : 0,
                'total_cost' => round($totalCost, 6),
                'ingredient_cost' => round(collect($batches)->sum('ingredient_cost'), 6),
                'wastage_cost' => round(collect($batches)->sum('wastage_cost'), 6),
                'packaging_cost' => round(collect($batches)->sum('packaging_cost'), 6),
                'labour_cost' => round(collect($batches)->sum('labour_cost'), 6),
                'overhead_cost' => round(collect($batches)->sum('overhead_cost'), 6),
                'unit_cost_average' => $actualYield > 0 ? round($totalCost / $actualYield, 6) : 0,
                'currency_code' => $currency,
            ],
            'batches' => $batches,
            'lines' => $lines,
            'plans' => $plans,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function buildMaterialPlanPayload(Request $request): array
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);
        $recipeId = $request->input('recipe_id');
        $producedProductId = $request->input('produced_product_id');
        $sourceStockArea = in_array($request->input('source_stock_area'), ['front', 'back'], true) ? $request->input('source_stock_area') : 'back';
        $destinationStockArea = in_array($request->input('destination_stock_area'), ['front', 'back'], true) ? $request->input('destination_stock_area') : 'back';
        $plannedQuantity = (float) ($request->input('planned_quantity') ?: $request->input('actual_yield_quantity') ?: 0);

        if (!$branchId) {
            return ['summary' => ['message' => 'Branch is required.'], 'lines' => []];
        }
        if (!$recipeId || $plannedQuantity <= 0) {
            return ['summary' => ['message' => 'Select recipe and planned quantity.'], 'lines' => []];
        }

        $recipe = FoodRecipe::query()
            ->where('company_id', $company->id)
            ->where('id', $recipeId)
            ->with($this->planningRecipeRelations())
            ->firstOrFail();

        $baseYield = max((float) ($recipe->yield_quantity ?: 1), 0.000001);
        $multiplier = $plannedQuantity / $baseYield;
        $currency = $recipe->currency ?: 'USD';
        $lines = [];

        foreach ($recipe->ingredients as $ingredient) {
            $product = $ingredient->ingredientProduct;
            $stock = ProductStock::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->ingredient_product_id)
                ->where('stock_area', $sourceStockArea)
                ->first();

            $cost = ProductCost::query()
                ->where('company_id', $company->id)
                ->where('product_id', $ingredient->ingredient_product_id)
                ->where(function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId)->orWhereNull('branch_id');
                })
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
                ->first();

            $netQty = (float) $ingredient->quantity * $multiplier;
            $wastagePercent = (float) ($ingredient->wastage_percent ?? 0);
            $wastageQty = $netQty * ($wastagePercent / 100);
            $requiredQty = $netQty + $wastageQty;
            $availableQty = (float) ($stock?->available_quantity ?? $stock?->quantity_on_hand ?? 0);
            $shortageQty = max($requiredQty - $availableQty, 0);
            $unitCost = (float) ($cost?->average_cost ?: $cost?->last_cost ?: 0);
            $lineCurrency = $cost?->currency ?: $currency;

            $lines[] = [
                'ingredient_product_id' => $ingredient->ingredient_product_id,
                'ingredient_name' => $product?->name,
                'ingredient_code' => $product?->code ?: $product?->sku,
                'ingredient_type' => $product?->product_type,
                'line_type' => $ingredient->line_type ?: 'ingredient',
                'uom_id' => $ingredient->uom_id ?: $product?->base_uom_id,
                'uom' => $ingredient->uom?->code ?: $ingredient->uom?->name ?: $product?->uom?->code ?: $product?->uom?->name ?: 'EACH',
                'source_recipe_id' => $recipe->id,
                'source_recipe_version' => (int) $recipe->version_no,
                'source_stock_area' => $sourceStockArea,
                'net_quantity' => round($netQty, 6),
                'wastage_percent' => round($wastagePercent, 6),
                'wastage_quantity' => round($wastageQty, 6),
                'required_quantity' => round($requiredQty, 6),
                'available_quantity' => round($availableQty, 6),
                'shortage_quantity' => round($shortageQty, 6),
                'unit_cost' => round($unitCost, 6),
                'required_value' => round($requiredQty * $unitCost, 6),
                'shortage_value' => round($shortageQty * $unitCost, 6),
                'currency' => $lineCurrency,
            ];
        }

        $explosion = app(FoodManufacturingService::class)
            ->explodeRecipeRequirements($company->id, $recipe, $plannedQuantity);
        $explodedLines = collect($explosion['lines'] ?? [])->map(function (array $line) use ($company, $branchId, $sourceStockArea, $currency) {
            $stock = ProductStock::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('product_id', $line['ingredient_product_id'])
                ->where('stock_area', $sourceStockArea)
                ->first();
            $cost = ProductCost::query()
                ->where('company_id', $company->id)
                ->where('product_id', $line['ingredient_product_id'])
                ->where(function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId)->orWhereNull('branch_id');
                })
                ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
                ->first();

            $required = (float) ($line['required_quantity'] ?? 0);
            $available = (float) ($stock?->available_quantity ?? $stock?->quantity_on_hand ?? 0);
            $shortage = max($required - $available, 0);
            $unitCost = (float) ($cost?->average_cost ?: $cost?->last_cost ?: 0);
            $lineCurrency = $cost?->currency ?: $currency;

            return $line + [
                'source_stock_area' => $sourceStockArea,
                'available_quantity' => round($available, 6),
                'shortage_quantity' => round($shortage, 6),
                'unit_cost' => round($unitCost, 6),
                'required_value' => round($required * $unitCost, 6),
                'shortage_value' => round($shortage * $unitCost, 6),
                'currency' => $lineCurrency,
                'cost_missing' => $unitCost <= 0,
            ];
        })->values();

        return [
            'filters' => [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'recipe_id' => $recipeId,
                'planned_quantity' => $plannedQuantity,
                'produced_product_id' => $recipe->produced_product_id ?: $recipe->menu_product_id,
                'source_stock_area' => $sourceStockArea,
                'destination_stock_area' => $destinationStockArea,
            ],
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'recipe_type' => $recipe->recipe_type,
                'version_no' => (int) $recipe->version_no,
                'yield_quantity' => (float) $recipe->yield_quantity,
                'yield_uom_id' => $recipe->yield_uom_id,
                'yield_uom' => $recipe->yieldUom?->code ?: $recipe->yieldUom?->name,
                'produced_product_id' => $producedProductId ?: $recipe->produced_product_id ?: $recipe->menu_product_id,
                'produced_product_name' => $recipe->producedProduct?->name ?: $recipe->menuProduct?->name,
            ],
            'summary' => [
                'planned_quantity' => round($plannedQuantity, 6),
                'line_count' => count($lines),
                'shortage_line_count' => collect($lines)->filter(fn ($line) => (float) $line['shortage_quantity'] > 0)->count(),
                'required_value' => round(collect($lines)->sum('required_value'), 6),
                'shortage_value' => round(collect($lines)->sum('shortage_value'), 6),
                'currency_code' => collect($lines)->pluck('currency')->filter()->unique()->count() === 1 ? collect($lines)->pluck('currency')->filter()->first() : 'MULTI',
                'can_produce' => collect($lines)->every(fn ($line) => (float) $line['shortage_quantity'] <= 0.000001),
                'has_nested_bom' => count($explosion['subrecipes'] ?? []) > 0,
                'bom_max_depth' => (int) ($explosion['max_depth'] ?? 0),
                'subrecipe_count' => count($explosion['subrecipes'] ?? []),
                'exploded_line_count' => $explodedLines->count(),
                'exploded_shortage_line_count' => $explodedLines->filter(fn ($line) => (float) $line['shortage_quantity'] > 0.000001)->count(),
                'exploded_required_value' => round($explodedLines->sum('required_value'), 6),
                'exploded_shortage_value' => round($explodedLines->sum('shortage_value'), 6),
                'missing_cost_line_count' => $explodedLines->where('cost_missing', true)->count(),
            ],
            'lines' => $lines,
            'exploded_lines' => $explodedLines->all(),
            'subrecipes' => $explosion['subrecipes'] ?? [],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /** @return array<int, string> */
    private function batchDetailRelations(): array
    {
        $relations = [
            'recipe.menuProduct.uom',
            'recipe.producedProduct.uom',
            'producedProduct.uom',
            'lines.ingredientProduct.uom',
            'branch',
        ];

        if ($this->tableHasColumn('food_manufacturing_batch_lines', 'uom_id')) {
            $relations[] = 'lines.uom';
        }

        return $relations;
    }

    /** @return array<int, string> */
    private function planningRecipeRelations(): array
    {
        $relations = [
            'ingredients.ingredientProduct.uom',
            'menuProduct',
            'producedProduct',
        ];

        if ($this->tableHasColumn('food_recipe_ingredients', 'uom_id')) {
            $relations[] = 'ingredients.uom';
        }
        if ($this->tableHasColumn('food_recipes', 'yield_uom_id')) {
            $relations[] = 'yieldUom';
        }

        return $relations;
    }

    private function nextPlanNumber(string $companyId, string $branchId): string
    {
        $count = FoodManufacturingProductionPlan::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', now()->toDateString())
                ->count() + 1;

        return 'PLAN-' . now()->format('Ymd') . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function batchNumber(FoodManufacturingBatch $batch): string
    {
        if (Schema::hasColumn('food_manufacturing_batches', 'batch_number') && !empty($batch->batch_number)) {
            return (string) $batch->batch_number;
        }

        return (string) $batch->id;
    }

    private function resolveBranchId(Request $request, bool $required = false): ?string
    {
        $company = app('company');
        $user = $request->user();
        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $roleName = $normalise(data_get($user, 'role.name'));
        $userType = $normalise(data_get($user, 'user_type'));
        $isSuperAdmin = in_array($roleName, ['super_admin', 'super_administrator'], true)
            || in_array($userType, ['super_admin', 'super_administrator'], true)
            || (bool) data_get($user, 'is_super_admin', false);

        $allocatedBranchIds = collect();
        if ($user && method_exists($user, 'branches')) {
            $allocatedBranchIds = $user->branches()
                ->where('company_branches.company_id', $company->id)
                ->pluck('company_branches.id');
        }
        if ($user?->branch_id) {
            $allocatedBranchIds->push((string) $user->branch_id);
        }
        $allocatedBranchIds = $allocatedBranchIds->map(fn ($id) => (string) $id)->filter()->unique()->values();

        $branchId = $request->input('branch_id')
            ?: $request->header('X-Branch-ID')
            ?: ($user?->branch_id ?? null);

        if (!$branchId && !$isSuperAdmin && $allocatedBranchIds->count() === 1) {
            $branchId = $allocatedBranchIds->first();
        }

        if (!$branchId) {
            if ($required || (!$isSuperAdmin && $allocatedBranchIds->count() > 1)) {
                abort(422, 'Select a branch for this hospitality operation.');
            }

            return null;
        }

        $resolvedBranchId = $company->branches()->whereKey($branchId)->value('id');
        abort_unless($resolvedBranchId, 422, 'The selected branch does not belong to this company.');
        abort_unless(
            $isSuperAdmin || $allocatedBranchIds->contains((string) $resolvedBranchId),
            403,
            'You are not allocated to the selected branch.'
        );

        return (string) $resolvedBranchId;
    }
}
