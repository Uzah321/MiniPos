<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use function App\Helpers\hasPermission;
use Modules\Manufacturing\Models\ProductionRecipe;
use Modules\Manufacturing\Models\ProductionRecipeItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;

class ProductionRecipeController extends Controller
{
    private function canManage()
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_manage_recipes',
            $company->id
        );

        return [$company, $permission];
    }

    private function canView()
    {
        $company = app('company');

        $permission = hasPermission(
            auth()->user()->role_id,
            'manufacturing_view_recipes',
            $company->id
        );

        return [$company, $permission];
    }

    public function index(Request $request)
    {
        [$company, $permission] = $this->canView();
        if ($permission !== true) {
            return $permission;
        }

        $query = ProductionRecipe::where('company_id', $company->id)
            ->with([
                'finishedProduct.uom',
                'items.product.uom',
                'items.product.costs',
                'items.product.stocks',
                'items.uom',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('finished_product_id')) {
            $query->where('finished_product_id', $request->finished_product_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'message' => 'Production recipes fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 100),
        ]);
    }

    public function show($id)
    {
        [$company, $permission] = $this->canView();
        if ($permission !== true) {
            return $permission;
        }

        $recipe = ProductionRecipe::where('company_id', $company->id)
            ->with([
                'finishedProduct.uom',
                'items.product.uom',
                'items.product.costs',
                'items.product.stocks',
                'items.uom',
            ])
            ->findOrFail($id);

        return response()->json([
            'message' => 'Production recipe fetched successfully.',
            'data' => $recipe,
        ]);
    }

    public function store(Request $request)
    {
        [$company, $permission] = $this->canManage();
        if ($permission !== true) {
            return $permission;
        }

        $validated = $this->validateRecipe($request);

        return DB::transaction(function () use ($request, $validated, $company) {
            $branchId = $validated['branch_id'] ?? null;
            $baseCurrency = $validated['currency'] ?? $this->getBaseCurrency($request);

            $finishedProduct = Product::where('company_id', $company->id)
                ->where('id', $validated['finished_product_id'])
                ->firstOrFail();

            $recipe = ProductionRecipe::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'finished_product_id' => $finishedProduct->id,
                'name' => $validated['name'],
                'code' => $validated['code'] ?? $this->generateRecipeCode($company->id),
                'costing_basis' => $validated['costing_basis'] ?? 'per_unit',
                'batch_output_quantity' => (float) $validated['batch_output_quantity'],
                'standard_wastage_percent' => (float) ($validated['standard_wastage_percent'] ?? 0),
                'is_active' => $validated['is_active'] ?? true,
                'metadata' => [
                    'bom_management_version' => 'enterprise_average_cost_v1',
                    'finished_product_name' => $finishedProduct->name,
                    'finished_product_code' => $finishedProduct->code,
                    'currency' => $baseCurrency,
                    'basis_explanation' => $this->basisExplanation($validated['costing_basis'] ?? 'per_unit'),
                    'material_cost' => 0,
                    'direct_labour_cost' => (float) ($validated['direct_labour_cost'] ?? 0),
                    'factory_overhead_cost' => (float) ($validated['factory_overhead_cost'] ?? 0),
                    'total_cost' => 0,
                    'cost_per_unit' => 0,
                ],
                'created_by' => auth()->id(),
            ]);

            $summary = $this->syncRecipeItems(
                $recipe,
                $validated['items'] ?? [],
                $company->id,
                $branchId,
                $baseCurrency
            );

            $this->updateRecipeCostSummary($recipe, $summary, $validated);

            $recipe->load([
                'finishedProduct.uom',
                'items.product.uom',
                'items.product.costs',
                'items.product.stocks',
                'items.uom',
            ]);

            return response()->json([
                'message' => 'Production recipe created successfully.',
                'data' => $recipe,
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        [$company, $permission] = $this->canManage();
        if ($permission !== true) {
            return $permission;
        }

        $validated = $this->validateRecipe($request, true);

        return DB::transaction(function () use ($request, $validated, $company, $id) {
            $recipe = ProductionRecipe::where('company_id', $company->id)
                ->findOrFail($id);

            $branchId = $validated['branch_id'] ?? $recipe->branch_id;
            $baseCurrency = $validated['currency'] ?? data_get($recipe->metadata, 'currency') ?? $this->getBaseCurrency($request);

            $finishedProduct = Product::where('company_id', $company->id)
                ->where('id', $validated['finished_product_id'])
                ->firstOrFail();

            $recipe->update([
                'branch_id' => $branchId,
                'finished_product_id' => $finishedProduct->id,
                'name' => $validated['name'],
                'code' => $validated['code'] ?? $recipe->code,
                'costing_basis' => $validated['costing_basis'] ?? $recipe->costing_basis,
                'batch_output_quantity' => (float) $validated['batch_output_quantity'],
                'standard_wastage_percent' => (float) ($validated['standard_wastage_percent'] ?? 0),
                'is_active' => $validated['is_active'] ?? $recipe->is_active,
                'metadata' => array_merge((array) $recipe->metadata, [
                    'bom_management_version' => 'enterprise_average_cost_v1',
                    'finished_product_name' => $finishedProduct->name,
                    'finished_product_code' => $finishedProduct->code,
                    'currency' => $baseCurrency,
                    'basis_explanation' => $this->basisExplanation($validated['costing_basis'] ?? $recipe->costing_basis),
                ]),
            ]);

            ProductionRecipeItem::where('production_recipe_id', $recipe->id)->delete();

            $summary = $this->syncRecipeItems(
                $recipe,
                $validated['items'] ?? [],
                $company->id,
                $branchId,
                $baseCurrency
            );

            $this->updateRecipeCostSummary($recipe, $summary, $validated);

            $recipe->load([
                'finishedProduct.uom',
                'items.product.uom',
                'items.product.costs',
                'items.product.stocks',
                'items.uom',
            ]);

            return response()->json([
                'message' => 'Production recipe updated successfully.',
                'data' => $recipe,
            ]);
        });
    }

    public function destroy($id)
    {
        [$company, $permission] = $this->canManage();
        if ($permission !== true) {
            return $permission;
        }

        $recipe = ProductionRecipe::where('company_id', $company->id)
            ->findOrFail($id);

        $recipe->delete();

        return response()->json([
            'message' => 'Production recipe deleted successfully.',
        ]);
    }

    private function validateRecipe(Request $request, bool $updating = false): array
    {
        $company = app('company');
        $availableUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        return $request->validate([
            'branch_id' => ['nullable', 'string'],
            'finished_product_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'costing_basis' => ['nullable', 'in:per_unit,per_batch'],
            'batch_output_quantity' => ['required', 'numeric', 'gt:0'],
            'standard_wastage_percent' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'direct_labour_cost' => ['nullable', 'numeric', 'min:0'],
            'factory_overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.uom_id' => ['nullable', 'uuid', $availableUom()],
            'items.*.quantity_required' => ['required', 'numeric', 'gt:0'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0'],
            'items.*.metadata' => ['nullable', 'array'],
        ]);
    }

    private function syncRecipeItems(
        ProductionRecipe $recipe,
        array $items,
        string $companyId,
        ?string $branchId,
        string $baseCurrency
    ): array {
        $materialCost = 0;
        $lines = [];

        foreach ($items as $item) {
            $product = Product::where('company_id', $companyId)
                ->where('id', $item['product_id'])
                ->with(['uom'])
                ->firstOrFail();

            $quantityRequired = (float) $item['quantity_required'];
            $wastagePercent = (float) ($item['wastage_percent'] ?? 0);
            $wasteQuantity = $quantityRequired * ($wastagePercent / 100);
            $totalQuantity = $quantityRequired + $wasteQuantity;

            $averageCost = $this->getProductAverageCost($companyId, $product->id, $branchId);
            $lastCost = $this->getProductLastCost($companyId, $product->id, $branchId);
            $currency = $this->getProductCostCurrency($companyId, $product->id, $branchId, $baseCurrency);
            $availableStock = $this->getAvailableStock($companyId, $product->id, $branchId);

            $lineCost = $totalQuantity * $averageCost;
            $materialCost += $lineCost;

            $metadata = array_merge((array) ($item['metadata'] ?? []), [
                'product_code' => $product->code,
                'product_name' => $product->name,
                'product_type' => $product->product_type,
                'unit' => optional($product->uom)->code,
                'entered_quantity_required' => $quantityRequired,
                'backend_quantity_required' => $quantityRequired,
                'quantity_basis' => $recipe->costing_basis,
                'wastage_percent' => $wastagePercent,
                'waste_quantity' => round($wasteQuantity, 6),
                'total_quantity' => round($totalQuantity, 6),

                // Important fixed costing fields
                'unit_cost' => round($averageCost, 6),
                'average_cost' => round($averageCost, 6),
                'last_cost' => round($lastCost, 6),
                'currency' => $currency,
                'available_stock' => round($availableStock, 6),
                'line_cost' => round($lineCost, 6),
                'cost_source' => 'product_costs.average_cost',
            ]);

            $line = ProductionRecipeItem::create([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'production_recipe_id' => $recipe->id,
                'product_id' => $product->id,
                'uom_id' => $item['uom_id'] ?? $product->base_uom_id,
                'quantity_required' => $quantityRequired,
                'wastage_percent' => $wastagePercent,
                'is_active' => true,
                'metadata' => $metadata,
            ]);

            $lines[] = $line;
        }

        return [
            'material_cost' => round($materialCost, 6),
            'lines' => $lines,
        ];
    }

    private function updateRecipeCostSummary(ProductionRecipe $recipe, array $summary, array $validated): void
    {
        $materialCost = (float) ($summary['material_cost'] ?? 0);
        $directLabourCost = (float) ($validated['direct_labour_cost'] ?? data_get($recipe->metadata, 'direct_labour_cost', 0));
        $factoryOverheadCost = (float) ($validated['factory_overhead_cost'] ?? data_get($recipe->metadata, 'factory_overhead_cost', 0));

        $totalCost = $materialCost + $directLabourCost + $factoryOverheadCost;
        $outputQty = (float) $recipe->batch_output_quantity;
        $costPerUnit = $outputQty > 0 ? $totalCost / $outputQty : 0;

        $recipe->update([
            'metadata' => array_merge((array) $recipe->metadata, [
                'material_cost' => round($materialCost, 6),
                'direct_labour_cost' => round($directLabourCost, 6),
                'factory_overhead_cost' => round($factoryOverheadCost, 6),
                'total_cost' => round($totalCost, 6),
                'cost_per_unit' => round($costPerUnit, 6),
                'frontend_material_cost' => round($materialCost, 6),
                'frontend_cost_per_unit' => round($costPerUnit, 6),
            ]),
        ]);
    }

    private function getProductAverageCost(string $companyId, string $productId, ?string $branchId = null): float
    {
        $query = ProductCost::where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $cost = $query->orderByDesc('updated_at')->first();

        return (float) ($cost?->average_cost ?? 0);
    }

    private function getProductLastCost(string $companyId, string $productId, ?string $branchId = null): float
    {
        $query = ProductCost::where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $cost = $query->orderByDesc('updated_at')->first();

        return (float) ($cost?->last_cost ?? $cost?->average_cost ?? 0);
    }

    private function getProductCostCurrency(
        string $companyId,
        string $productId,
        ?string $branchId = null,
        string $fallback = 'USD'
    ): string {
        $query = ProductCost::where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $cost = $query->orderByDesc('updated_at')->first();

        if (!empty($cost?->currency)) {
            return $cost->currency;
        }

        $product = Product::where('company_id', $companyId)
            ->where('id', $productId)
            ->with(['prices' => function ($q) use ($branchId) {
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }

                $q->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderByDesc('updated_at');
            }])
            ->first();

        $priceCurrency = optional($product?->prices?->first())->currency;

        return $priceCurrency ?: $fallback;
    }

    private function getAvailableStock(string $companyId, string $productId, ?string $branchId = null): float
    {
        $query = ProductStock::where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->value('available_quantity');
    }

    private function getBaseCurrency(Request $request): string
    {
        return $request->input('currency')
            ?: $request->input('base_currency')
                ?: 'USD';
    }

    private function basisExplanation(string $basis): string
    {
        if ($basis === 'per_batch') {
            return 'User entered material quantities for the full batch output.';
        }

        return 'User entered material quantities per one finished unit.';
    }

    private function generateRecipeCode(string $companyId): string
    {
        $count = ProductionRecipe::where('company_id', $companyId)->count() + 1;

        do {
            $code = 'BOM-' . str_pad($count, 6, '0', STR_PAD_LEFT);
            $exists = ProductionRecipe::where('company_id', $companyId)
                ->where('code', $code)
                ->exists();

            $count++;
        } while ($exists);

        return $code;
    }
}
