<?php
//
//namespace Modules\FoodManufacturing\Http\Controllers;
//
//use App\Http\Controllers\Controller;
//
//
//use App\Services\FoodManufacturing\FoodManufacturingService;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Validator;
//use Modules\FoodManufacturing\Models\FoodRecipe;
//use Modules\FoodManufacturing\Models\FoodRecipeIngredient;
//use Modules\Product\Models\Product;
//
//class FoodRecipeController extends Controller
//{
//    public function index(Request $request)
//    {
//        $company = app('company');
//
//        $query = FoodRecipe::where('company_id', $company->id)
//            ->with(['menuProduct.uom', 'ingredients.ingredientProduct.uom']);
//
//        if ($request->filled('recipe_type')) {
//            $query->where('recipe_type', $request->recipe_type);
//        }
//
//        if ($request->filled('menu_product_id')) {
//            $query->where('menu_product_id', $request->menu_product_id);
//        }
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhereHas('menuProduct', function ($productQuery) use ($q) {
//                        $productQuery->where('name', 'like', "%{$q}%")
//                            ->orWhere('code', 'like', "%{$q}%")
//                            ->orWhere('sku', 'like', "%{$q}%");
//                    });
//            });
//        }
//
//        return response()->json([
//            'message' => 'Food recipes fetched successfully.',
//            'data' => $query->latest()->paginate((int) $request->get('per_page', 25)),
//        ]);
//    }
//
//    public function store(Request $request, FoodManufacturingService $service)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $validator = Validator::make($request->all(), [
//            'menu_product_id' => 'required|exists:products,id',
//            'name' => 'required|string|max:255',
//            'recipe_type' => 'required|in:menu,manufacturing,prep',
//            'yield_quantity' => 'required|numeric|min:0.000001',
//            'currency' => 'required|string|size:3',
//            'source_stock_area' => 'nullable|in:front,back',
//            'destination_stock_area' => 'nullable|in:front,back',
//            'packaging_cost' => 'nullable|numeric|min:0',
//            'labour_cost' => 'nullable|numeric|min:0',
//            'overhead_cost' => 'nullable|numeric|min:0',
//            'ingredients' => 'required|array|min:1',
//            'ingredients.*.ingredient_product_id' => 'required|exists:products,id',
//            'ingredients.*.quantity' => 'required|numeric|min:0.000001',
//            'ingredients.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
//            'ingredients.*.notes' => 'nullable|string',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $menuProduct = Product::where('company_id', $company->id)->where('id', $request->menu_product_id)->firstOrFail();
//
//        return DB::transaction(function () use ($request, $company, $user, $menuProduct, $service) {
//            if ($request->boolean('make_active', true)) {
//                FoodRecipe::where('company_id', $company->id)
//                    ->where('menu_product_id', $menuProduct->id)
//                    ->where('recipe_type', $request->recipe_type)
//                    ->update(['is_active' => false]);
//            }
//
//            $recipe = FoodRecipe::create([
//                'company_id' => $company->id,
//                'menu_product_id' => $menuProduct->id,
//                'name' => $request->name,
//                'recipe_type' => $request->recipe_type,
//                'version_no' => $this->nextVersion($company->id, $menuProduct->id, $request->recipe_type),
//                'yield_quantity' => $request->yield_quantity,
//                'currency' => strtoupper($request->currency),
//                'source_stock_area' => $request->source_stock_area ?: ($request->recipe_type === 'menu' ? 'front' : 'back'),
//                'destination_stock_area' => $request->destination_stock_area ?: 'back',
//                'packaging_cost' => $request->packaging_cost ?? 0,
//                'labour_cost' => $request->labour_cost ?? 0,
//                'overhead_cost' => $request->overhead_cost ?? 0,
//                'is_active' => $request->boolean('make_active', true),
//                'created_by' => $user?->id,
//            ]);
//
//            foreach ($request->ingredients as $ingredient) {
//                FoodRecipeIngredient::create([
//                    'company_id' => $company->id,
//                    'food_recipe_id' => $recipe->id,
//                    'ingredient_product_id' => $ingredient['ingredient_product_id'],
//                    'quantity' => $ingredient['quantity'],
//                    'wastage_percent' => $ingredient['wastage_percent'] ?? 0,
//                    'notes' => $ingredient['notes'] ?? null,
//                ]);
//            }
//
//            $branchId = $request->branch_id ?: ($user?->branch_id ?? null);
//            if ($branchId) {
//                $service->saveCostSnapshot($company->id, $branchId, $recipe);
//            }
//
//            return response()->json([
//                'message' => 'Food recipe/BOM created successfully.',
//                'data' => $recipe->fresh(['menuProduct.uom', 'ingredients.ingredientProduct.uom']),
//            ], 201);
//        });
//    }
//
//    public function show(string $id)
//    {
//        $company = app('company');
//
//        $recipe = FoodRecipe::where('company_id', $company->id)
//            ->where('id', $id)
//            ->with(['menuProduct.uom', 'ingredients.ingredientProduct.uom'])
//            ->firstOrFail();
//
//        return response()->json([
//            'message' => 'Food recipe fetched successfully.',
//            'data' => $recipe,
//        ]);
//    }
//
//    public function update(Request $request, string $id, FoodManufacturingService $service)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $recipe = FoodRecipe::where('company_id', $company->id)->where('id', $id)->firstOrFail();
//
//        $validator = Validator::make($request->all(), [
//            'name' => 'sometimes|required|string|max:255',
//            'yield_quantity' => 'sometimes|required|numeric|min:0.000001',
//            'currency' => 'sometimes|required|string|size:3',
//            'source_stock_area' => 'nullable|in:front,back',
//            'destination_stock_area' => 'nullable|in:front,back',
//            'packaging_cost' => 'nullable|numeric|min:0',
//            'labour_cost' => 'nullable|numeric|min:0',
//            'overhead_cost' => 'nullable|numeric|min:0',
//            'is_active' => 'nullable|boolean',
//            'ingredients' => 'nullable|array|min:1',
//            'ingredients.*.ingredient_product_id' => 'required_with:ingredients|exists:products,id',
//            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.000001',
//            'ingredients.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
//            'ingredients.*.notes' => 'nullable|string',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        return DB::transaction(function () use ($request, $recipe, $company, $user, $service) {
//            if ($request->boolean('is_active') === true) {
//                FoodRecipe::where('company_id', $company->id)
//                    ->where('menu_product_id', $recipe->menu_product_id)
//                    ->where('recipe_type', $recipe->recipe_type)
//                    ->where('id', '!=', $recipe->id)
//                    ->update(['is_active' => false]);
//            }
//
//            $recipe->fill($request->only([
//                'name',
//                'yield_quantity',
//                'source_stock_area',
//                'destination_stock_area',
//                'packaging_cost',
//                'labour_cost',
//                'overhead_cost',
//                'is_active',
//            ]));
//
//            if ($request->filled('currency')) {
//                $recipe->currency = strtoupper($request->currency);
//            }
//
//            $recipe->updated_by = $user?->id;
//            $recipe->save();
//
//            if ($request->has('ingredients')) {
//                FoodRecipeIngredient::where('food_recipe_id', $recipe->id)->delete();
//
//                foreach ($request->ingredients as $ingredient) {
//                    FoodRecipeIngredient::create([
//                        'company_id' => $company->id,
//                        'food_recipe_id' => $recipe->id,
//                        'ingredient_product_id' => $ingredient['ingredient_product_id'],
//                        'quantity' => $ingredient['quantity'],
//                        'wastage_percent' => $ingredient['wastage_percent'] ?? 0,
//                        'notes' => $ingredient['notes'] ?? null,
//                    ]);
//                }
//            }
//
//            $branchId = $request->branch_id ?: ($user?->branch_id ?? null);
//            if ($branchId) {
//                $service->saveCostSnapshot($company->id, $branchId, $recipe);
//            }
//
//            return response()->json([
//                'message' => 'Food recipe/BOM updated successfully.',
//                'data' => $recipe->fresh(['menuProduct.uom', 'ingredients.ingredientProduct.uom']),
//            ]);
//        });
//    }
//
//    public function destroy(string $id)
//    {
//        $company = app('company');
//
//        $recipe = FoodRecipe::where('company_id', $company->id)->where('id', $id)->firstOrFail();
//        $recipe->delete();
//
//        return response()->json(['message' => 'Food recipe deleted successfully.']);
//    }
//
//    public function cost(Request $request, string $id, FoodManufacturingService $service)
//    {
//        $company = app('company');
//        $user = auth()->user();
//        $branchId = $request->branch_id ?: ($user?->branch_id ?? null);
//
//        if (!$branchId) {
//            return response()->json(['message' => 'Branch is required for costing.'], 422);
//        }
//
//        $recipe = FoodRecipe::where('company_id', $company->id)
//            ->where('id', $id)
//            ->with(['ingredients.ingredientProduct.uom', 'menuProduct'])
//            ->firstOrFail();
//
//        return response()->json([
//            'message' => 'Recipe cost calculated successfully.',
//            'data' => $service->calculateRecipeCost($company->id, $branchId, $recipe),
//        ]);
//    }
//
//    public function linkMenuRecipe(Request $request, string $menuProductId)
//    {
//        $company = app('company');
//
//        $validator = Validator::make($request->all(), [
//            'recipe_id' => 'required|exists:food_recipes,id',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
//        }
//
//        FoodRecipe::where('company_id', $company->id)
//            ->where('menu_product_id', $menuProductId)
//            ->where('recipe_type', 'menu')
//            ->update(['is_active' => false]);
//
//        $recipe = FoodRecipe::where('company_id', $company->id)
//            ->where('id', $request->recipe_id)
//            ->where('menu_product_id', $menuProductId)
//            ->where('recipe_type', 'menu')
//            ->firstOrFail();
//
//        $recipe->is_active = true;
//        $recipe->save();
//
//        return response()->json([
//            'message' => 'Recipe linked to menu item successfully.',
//            'data' => $recipe->fresh(['menuProduct', 'ingredients.ingredientProduct']),
//        ]);
//    }
//
//    private function nextVersion(string $companyId, string $menuProductId, string $recipeType): int
//    {
//        return ((int) FoodRecipe::where('company_id', $companyId)
//                ->where('menu_product_id', $menuProductId)
//                ->where('recipe_type', $recipeType)
//                ->max('version_no')) + 1;
//    }
//}


namespace Modules\FoodManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\FoodManufacturing\FoodManufacturingService;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Support\FiltersTableColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\FoodManufacturing\Models\FoodRecipeIngredient;
use Modules\Product\Models\Product;

class FoodRecipeController extends Controller
{
    use FiltersTableColumns;

    public function costReportDownload(
        Request $request,
        string $format,
        FoodManufacturingService $service,
        EnterpriseReportDownloadService $downloads
    ) {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $rawIds = $request->input('menu_product_ids', []);
        $productIds = collect(is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return response()->json([
                'message' => 'Select at least one menu item to export.',
                'errors' => ['menu_product_ids' => ['At least one menu item is required.']],
            ], 422);
        }

        $products = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $productIds)
            ->where('product_type', 'finished_good')
            ->get()
            ->keyBy(fn ($product) => (string) $product->id);

        $recipes = FoodRecipe::query()
            ->where('company_id', $company->id)
            ->where('recipe_type', 'menu')
            ->whereIn('menu_product_id', $products->keys())
            ->with(['ingredients.ingredientProduct.uom', 'menuProduct'])
            ->orderByDesc('is_active')
            ->orderByDesc('version_no')
            ->get()
            ->groupBy(fn ($recipe) => (string) $recipe->menu_product_id)
            ->map(fn ($group) => $group->first());

        $summaryRows = [];
        $ingredientRows = [];

        foreach ($productIds as $productId) {
            $product = $products->get((string) $productId);
            if (!$product) continue;

            $recipe = $recipes->get((string) $product->id);
            if (!$recipe) {
                $summaryRows[] = [
                    $product->code ?: $product->sku ?: '-', $product->name, 'No linked recipe', '-',
                    0, 'USD', 0, 0, 0, 0, 0, 0,
                ];
                continue;
            }

            $cost = $service->calculateRecipeCost($company->id, $branchId, $recipe);
            $summaryRows[] = [
                $product->code ?: $product->sku ?: '-',
                $product->name,
                $recipe->name,
                $recipe->recipe_type,
                $cost['yield_quantity'] ?? 0,
                $cost['currency'] ?? $recipe->currency ?? 'USD',
                $cost['ingredient_cost'] ?? 0,
                $cost['packaging_cost'] ?? 0,
                $cost['labour_cost'] ?? 0,
                $cost['overhead_cost'] ?? 0,
                $cost['total_cost'] ?? 0,
                $cost['cost_per_unit'] ?? 0,
            ];

            $ingredientProducts = $recipe->ingredients
                ->mapWithKeys(fn ($ingredient) => [(string) $ingredient->ingredient_product_id => $ingredient->ingredientProduct]);

            foreach ($cost['lines'] ?? [] as $line) {
                $ingredient = $ingredientProducts->get((string) ($line['ingredient_product_id'] ?? ''));
                $ingredientRows[] = [
                    $product->name,
                    $ingredient?->code ?: $ingredient?->sku ?: '-',
                    $line['ingredient_name'] ?? $ingredient?->name ?? '-',
                    $line['uom'] ?? $ingredient?->uom?->code ?? $ingredient?->uom?->name ?? '-',
                    $line['net_quantity'] ?? 0,
                    $line['wastage_percent'] ?? 0,
                    $line['gross_quantity'] ?? 0,
                    $line['unit_cost'] ?? 0,
                    $line['line_cost'] ?? 0,
                    $line['currency'] ?? $cost['currency'] ?? 'USD',
                ];
            }
        }

        $title = 'Menu Item Recipe Cost Report';
        $sections = [
            [
                'title' => 'Menu Item Cost Summary',
                'headers' => ['Menu Code', 'Menu Item', 'Recipe', 'Type', 'Yield Qty', 'Currency', 'Ingredient Cost', 'Packaging', 'Labour', 'Overhead', 'Total Cost', 'Average Cost / Unit'],
                'rows' => $summaryRows,
            ],
            [
                'title' => 'Ingredient Cost Detail',
                'headers' => ['Menu Item', 'Ingredient Code', 'Ingredient', 'UOM', 'Net Qty', 'Wastage %', 'Gross Qty', 'Unit Cost', 'Line Cost', 'Currency'],
                'rows' => $ingredientRows,
            ],
        ];
        $filename = 'menu-item-recipe-cost-report-' . now()->format('Ymd-His') . '.' . $format;

        if ($format === 'xlsx') {
            return $downloads->downloadSpreadsheet($title, $filename, $sections, true);
        }

        return $downloads->downloadPdf($title, $filename, $sections, null, 'a4', 'landscape', true);
    }

    public function index(Request $request)
    {
        $company = app('company');

        $query = FoodRecipe::where('company_id', $company->id)
            ->with($this->recipeDetailRelations());

        if ($request->filled('recipe_type')) {
            $query->where('recipe_type', $request->recipe_type);
        }

        if ($request->filled('menu_product_id')) {
            $query->where('menu_product_id', $request->menu_product_id);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhereHas('menuProduct', function ($productQuery) use ($q) {
                        $productQuery->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%");
                    });
            });
        }

        return response()->json([
            'message' => 'Food recipes fetched successfully.',
            'data' => $query->latest()->paginate((int)$request->get('per_page', 25)),
        ]);
    }

    public function store(Request $request, FoodManufacturingService $service)
    {
        $company = app('company');
        $user = auth()->user();
        $companyProduct = fn () => Rule::exists('products', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        $validator = Validator::make($request->all(), [
            'menu_product_id' => ['nullable', 'uuid', $companyProduct()],
            'produced_product_id' => ['nullable', 'uuid', $companyProduct()],
            'name' => 'required|string|max:255',
            'recipe_type' => 'required|in:menu,manufacturing,prep',
            'yield_quantity' => 'required|numeric|min:0.000001',
            'yield_uom_id' => ['nullable', 'uuid', $companyUom()],
            'currency' => 'required|string|size:3',
            'source_stock_area' => 'nullable|in:front,back',
            'destination_stock_area' => 'nullable|in:front,back',
            'packaging_cost' => 'nullable|numeric|min:0',
            'labour_cost' => 'nullable|numeric|min:0',
            'overhead_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.ingredient_product_id' => ['required', 'uuid', 'distinct', $companyProduct()],
            'ingredients.*.uom_id' => ['nullable', 'uuid', $companyUom()],
            'ingredients.*.line_type' => 'nullable|in:ingredient,packaging,consumable,sub_recipe',
            'ingredients.*.quantity' => 'required|numeric|min:0.000001',
            'ingredients.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
            'ingredients.*.notes' => 'nullable|string',
        ]);

        $validator->after(function ($validator) use ($request) {
            $recipeType = $request->input('recipe_type');
            $menuProductId = $request->input('menu_product_id');
            $producedProductId = $request->input('produced_product_id');
            $outputProductId = $recipeType === 'menu'
                ? $menuProductId
                : $producedProductId;

            if ($recipeType === 'menu' && !$menuProductId) {
                $validator->errors()->add('menu_product_id', 'A menu recipe must be linked to a menu item.');
            }

            if ($recipeType !== 'menu' && !$producedProductId) {
                $validator->errors()->add('produced_product_id', 'Select the product produced by this recipe.');
            }

            $ingredientIds = collect($request->input('ingredients', []))
                ->pluck('ingredient_product_id')
                ->filter()
                ->map(fn ($id) => (string) $id);

            if ($outputProductId && $ingredientIds->contains((string) $outputProductId)) {
                $validator->errors()->add('ingredients', 'A recipe cannot contain its own output product as an ingredient.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $menuProduct = null;
        $producedProduct = null;

        if ($request->recipe_type === 'menu' && $request->filled('menu_product_id')) {
            $menuProduct = Product::where('company_id', $company->id)
                ->where('id', $request->menu_product_id)
                ->firstOrFail();
        }

        if ($request->recipe_type !== 'menu' && $request->filled('produced_product_id')) {
            $producedProduct = Product::where('company_id', $company->id)
                ->where('id', $request->produced_product_id)
                ->firstOrFail();
        }

        $branchId = $this->resolveBranchId($request, false);

        return DB::transaction(function () use ($request, $company, $user, $menuProduct, $producedProduct, $service, $branchId) {
            if ($request->boolean('make_active', true)) {
                $this->recipeFamilyQuery(
                    $company->id,
                    $menuProduct?->id,
                    $producedProduct?->id,
                    $request->recipe_type
                )->update(['is_active' => false]);
            }

            $recipe = FoodRecipe::create($this->onlyExistingTableColumns('food_recipes', [
                'company_id' => $company->id,
                'menu_product_id' => $menuProduct?->id,
                'produced_product_id' => $producedProduct?->id,
                'name' => $request->name,
                'recipe_type' => $request->recipe_type,
                'version_no' => $this->nextVersion(
                    $company->id,
                    $menuProduct?->id,
                    $producedProduct?->id,
                    $request->recipe_type
                ),
                'yield_quantity' => $request->yield_quantity,
                'yield_uom_id' => $request->yield_uom_id ?: ($producedProduct?->base_uom_id ?: $menuProduct?->base_uom_id),
                'currency' => strtoupper($request->currency),
                'source_stock_area' => $request->source_stock_area ?: ($request->recipe_type === 'menu' ? 'front' : 'back'),
                'destination_stock_area' => $request->destination_stock_area ?: 'back',
                'packaging_cost' => $request->packaging_cost ?? 0,
                'labour_cost' => $request->labour_cost ?? 0,
                'overhead_cost' => $request->overhead_cost ?? 0,
                'notes' => $request->notes,
                'is_active' => $request->boolean('make_active', true),
                'created_by' => $user?->id,
            ]));

            foreach ($request->ingredients as $ingredient) {
                $ingredientProduct = Product::where('company_id', $company->id)
                    ->where('id', $ingredient['ingredient_product_id'])
                    ->firstOrFail();

                FoodRecipeIngredient::create($this->onlyExistingTableColumns('food_recipe_ingredients', [
                    'company_id' => $company->id,
                    'food_recipe_id' => $recipe->id,
                    'ingredient_product_id' => $ingredient['ingredient_product_id'],
                    'uom_id' => $ingredient['uom_id'] ?? $ingredientProduct->base_uom_id,
                    'line_type' => $this->normaliseLineType($ingredient['line_type'] ?? null, $ingredientProduct),
                    'quantity' => $ingredient['quantity'],
                    'wastage_percent' => $ingredient['wastage_percent'] ?? 0,
                    'notes' => $ingredient['notes'] ?? null,
                ]));
            }

            $service->assertNoRecipeCycle($company->id, $recipe->fresh('ingredients.ingredientProduct'));

            if ($branchId) {
                $service->saveCostSnapshot($company->id, $branchId, $recipe);
            }

            return response()->json([
                'message' => 'Food recipe/BOM created successfully.',
                'data' => $recipe->fresh($this->recipeDetailRelations()),
            ], 201);
        });
    }

    public function show(string $id)
    {
        $company = app('company');

        $recipe = FoodRecipe::where('company_id', $company->id)
            ->where('id', $id)
            ->with($this->recipeDetailRelations())
            ->firstOrFail();

        return response()->json([
            'message' => 'Food recipe fetched successfully.',
            'data' => $recipe,
        ]);
    }

    public function update(Request $request, string $id, FoodManufacturingService $service)
    {
        $company = app('company');
        $user = auth()->user();

        $recipe = FoodRecipe::where('company_id', $company->id)->where('id', $id)->firstOrFail();
        $companyProduct = fn () => Rule::exists('products', 'id')
            ->where(fn ($query) => $query->where('company_id', $company->id));
        $companyUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        $validator = Validator::make($request->all(), [
            'menu_product_id' => ['nullable', 'uuid', $companyProduct()],
            'produced_product_id' => ['nullable', 'uuid', $companyProduct()],
            'name' => 'sometimes|required|string|max:255',
            'recipe_type' => 'sometimes|required|in:menu,manufacturing,prep',
            'yield_quantity' => 'sometimes|required|numeric|min:0.000001',
            'yield_uom_id' => ['nullable', 'uuid', $companyUom()],
            'currency' => 'sometimes|required|string|size:3',
            'source_stock_area' => 'nullable|in:front,back',
            'destination_stock_area' => 'nullable|in:front,back',
            'packaging_cost' => 'nullable|numeric|min:0',
            'labour_cost' => 'nullable|numeric|min:0',
            'overhead_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'ingredients' => 'nullable|array|min:1',
            'ingredients.*.ingredient_product_id' => ['required_with:ingredients', 'uuid', 'distinct', $companyProduct()],
            'ingredients.*.uom_id' => ['nullable', 'uuid', $companyUom()],
            'ingredients.*.line_type' => 'nullable|in:ingredient,packaging,consumable,sub_recipe',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.000001',
            'ingredients.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
            'ingredients.*.notes' => 'nullable|string',
        ]);

        $validator->after(function ($validator) use ($request, $recipe) {
            $recipeType = $request->input('recipe_type', $recipe->recipe_type);
            $menuProductId = $request->exists('menu_product_id')
                ? $request->input('menu_product_id')
                : $recipe->menu_product_id;
            $producedProductId = $request->exists('produced_product_id')
                ? $request->input('produced_product_id')
                : $recipe->produced_product_id;
            $outputProductId = $recipeType === 'menu'
                ? $menuProductId
                : $producedProductId;

            if ($recipeType === 'menu' && !$menuProductId) {
                $validator->errors()->add('menu_product_id', 'A menu recipe must be linked to a menu item.');
            }

            if ($recipeType !== 'menu' && !$producedProductId) {
                $validator->errors()->add('produced_product_id', 'Select the product produced by this recipe.');
            }

            if ($request->has('ingredients')) {
                $ingredientIds = collect($request->input('ingredients', []))
                    ->pluck('ingredient_product_id')
                    ->filter()
                    ->map(fn ($id) => (string) $id);

                if ($outputProductId && $ingredientIds->contains((string) $outputProductId)) {
                    $validator->errors()->add('ingredients', 'A recipe cannot contain its own output product as an ingredient.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $this->resolveBranchId($request, false);

        return DB::transaction(function () use ($request, $recipe, $company, $user, $service, $branchId) {
            $targetRecipeType = $request->input('recipe_type', $recipe->recipe_type);
            $targetMenuProductId = $targetRecipeType === 'menu'
                ? ($request->exists('menu_product_id') ? $request->input('menu_product_id') : $recipe->menu_product_id)
                : null;
            $targetProducedProductId = $targetRecipeType === 'menu'
                ? null
                : ($request->exists('produced_product_id') ? $request->input('produced_product_id') : $recipe->produced_product_id);
            $familyChanged = (string) $recipe->recipe_type !== (string) $targetRecipeType
                || (string) $recipe->menu_product_id !== (string) $targetMenuProductId
                || (string) $recipe->produced_product_id !== (string) $targetProducedProductId;
            $bomDefinitionChanged = $familyChanged || $request->hasAny([
                'ingredients',
                'yield_quantity',
                'yield_uom_id',
                'currency',
                'source_stock_area',
                'destination_stock_area',
                'packaging_cost',
                'labour_cost',
                'overhead_cost',
            ]);
            $willBeActive = $request->has('is_active')
                ? $request->boolean('is_active')
                : (bool) $recipe->is_active;

            // Plans and batches pin this number. Advance it whenever the material
            // definition is saved so a previously calculated plan cannot silently
            // post stock using changed quantities or costs.
            if ($bomDefinitionChanged) {
                $recipe->version_no = $this->nextVersion(
                    $company->id,
                    $targetMenuProductId,
                    $targetProducedProductId,
                    $targetRecipeType
                );
            }

            if ($willBeActive) {
                $this->recipeFamilyQuery(
                    $company->id,
                    $targetMenuProductId,
                    $targetProducedProductId,
                    $targetRecipeType
                )->where('id', '!=', $recipe->id)->update(['is_active' => false]);
            }

            $recipe->fill($this->onlyExistingTableColumns('food_recipes', $request->only([
                'name',
                'recipe_type',
                'yield_quantity',
                'yield_uom_id',
                'source_stock_area',
                'destination_stock_area',
                'packaging_cost',
                'labour_cost',
                'overhead_cost',
                'notes',
                'is_active',
            ])));
            $recipe->menu_product_id = $targetMenuProductId;
            $recipe->produced_product_id = $targetProducedProductId;

            if ($request->filled('currency')) {
                $recipe->currency = strtoupper($request->currency);
            }

            $recipe->updated_by = $user?->id;
            $recipe->save();

            if ($request->has('ingredients')) {
                FoodRecipeIngredient::where('food_recipe_id', $recipe->id)->delete();

                foreach ($request->ingredients as $ingredient) {
                    $ingredientProduct = Product::where('company_id', $company->id)
                        ->where('id', $ingredient['ingredient_product_id'])
                        ->firstOrFail();

                    FoodRecipeIngredient::create($this->onlyExistingTableColumns('food_recipe_ingredients', [
                        'company_id' => $company->id,
                        'food_recipe_id' => $recipe->id,
                        'ingredient_product_id' => $ingredient['ingredient_product_id'],
                        'uom_id' => $ingredient['uom_id'] ?? $ingredientProduct->base_uom_id,
                        'line_type' => $this->normaliseLineType($ingredient['line_type'] ?? null, $ingredientProduct),
                        'quantity' => $ingredient['quantity'],
                        'wastage_percent' => $ingredient['wastage_percent'] ?? 0,
                        'notes' => $ingredient['notes'] ?? null,
                    ]));
                }
            }

            $service->assertNoRecipeCycle($company->id, $recipe->fresh('ingredients.ingredientProduct'));

            if ($branchId) {
                $service->saveCostSnapshot($company->id, $branchId, $recipe);
            }

            return response()->json([
                'message' => 'Food recipe/BOM updated successfully.',
                'data' => $recipe->fresh($this->recipeDetailRelations()),
            ]);
        });
    }

    public function destroy(string $id)
    {
        $company = app('company');

        $recipe = FoodRecipe::where('company_id', $company->id)->where('id', $id)->firstOrFail();

        if ($recipe->batches()->exists()) {
            return response()->json([
                'message' => 'This recipe has production history and cannot be deleted. Deactivate it instead.',
                'code' => 'RECIPE_HAS_PRODUCTION_HISTORY',
            ], 409);
        }

        $recipe->delete();

        return response()->json(['message' => 'Food recipe deleted successfully.']);
    }

    public function cost(Request $request, string $id, FoodManufacturingService $service)
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $recipe = FoodRecipe::where('company_id', $company->id)
            ->where('id', $id)
            ->with($this->recipeDetailRelations())
            ->firstOrFail();

        return response()->json([
            'message' => 'Recipe cost calculated successfully.',
            'data' => $service->calculateRecipeCost($company->id, $branchId, $recipe),
        ]);
    }

    public function linkMenuRecipe(Request $request, string $menuProductId)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'recipe_id' => [
                'required',
                'uuid',
                Rule::exists('food_recipes', 'id')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        Product::where('company_id', $company->id)
            ->where('id', $menuProductId)
            ->firstOrFail();

        $recipe = DB::transaction(function () use ($company, $request, $menuProductId) {
            // Resolve and lock the requested tenant recipe before changing the
            // currently active link, so a bad request cannot leave every BOM off.
            $recipe = FoodRecipe::where('company_id', $company->id)
                ->where('id', $request->recipe_id)
                ->where('menu_product_id', $menuProductId)
                ->where('recipe_type', 'menu')
                ->lockForUpdate()
                ->firstOrFail();

            FoodRecipe::where('company_id', $company->id)
                ->where('menu_product_id', $menuProductId)
                ->where('recipe_type', 'menu')
                ->where('id', '!=', $recipe->id)
                ->update(['is_active' => false]);

            $recipe->is_active = true;
            $recipe->save();

            return $recipe;
        });

        return response()->json([
            'message' => 'Recipe linked to menu item successfully.',
            'data' => $recipe->fresh(['menuProduct', 'ingredients.ingredientProduct']),
        ]);
    }

    /** @return array<int, string> */
    private function recipeDetailRelations(): array
    {
        $relations = [
            'menuProduct.uom',
            'producedProduct.uom',
            'ingredients.ingredientProduct.uom',
        ];

        if ($this->tableHasColumn('food_recipes', 'yield_uom_id')) {
            $relations[] = 'yieldUom';
        }
        if ($this->tableHasColumn('food_recipe_ingredients', 'uom_id')) {
            $relations[] = 'ingredients.uom';
        }

        return $relations;
    }

    private function recipeFamilyQuery(
        string $companyId,
        ?string $menuProductId,
        ?string $producedProductId,
        string $recipeType
    )
    {
        $query = FoodRecipe::where('company_id', $companyId)
            ->where('recipe_type', $recipeType);

        if ($menuProductId) {
            $query->where('menu_product_id', $menuProductId);
        } else {
            $query->whereNull('menu_product_id')
                ->when(
                    $producedProductId,
                    fn ($family) => $family->where('produced_product_id', $producedProductId),
                    fn ($family) => $family->whereNull('produced_product_id')
                );
        }

        return $query;
    }

    private function normaliseLineType(?string $requested, Product $product): string
    {
        $lineType = strtolower(trim((string) $requested));
        if (in_array($lineType, ['ingredient', 'packaging', 'consumable', 'sub_recipe'], true)) {
            return $lineType;
        }

        return match (strtolower((string) $product->product_type)) {
            'packaging' => 'packaging',
            'consumable', 'consumables' => 'consumable',
            'semi_finished', 'semi_finished_good' => 'sub_recipe',
            default => 'ingredient',
        };
    }

    private function resolveBranchId(Request $request, bool $required = true): ?string
    {
        $company = app('company');
        $user = $request->user();
        abort_if(!$user, 401, 'Unauthenticated.');

        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $roleName = $normalise(data_get($user, 'role.name'));
        $userType = $normalise(data_get($user, 'user_type'));
        $isSuperAdmin = in_array($roleName, ['super_admin', 'super_administrator'], true)
            || in_array($userType, ['super_admin', 'super_administrator'], true);

        $accessibleBranchIds = collect();
        if (!$isSuperAdmin && method_exists($user, 'branches')) {
            $accessibleBranchIds = $user->branches()
                ->where('company_branches.company_id', $company->id)
                ->pluck('company_branches.id');
        }
        if (!$isSuperAdmin && $user->branch_id) {
            $accessibleBranchIds->push((string) $user->branch_id);
        }
        $accessibleBranchIds = $accessibleBranchIds
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $branchId = $request->input('branch_id')
            ?: $request->header('X-Branch-ID')
            ?: ($user->branch_id ?? null);

        if (!$branchId && !$isSuperAdmin && $accessibleBranchIds->count() === 1) {
            $branchId = $accessibleBranchIds->first();
        }

        if (!$branchId) {
            abort_if($required, 422, 'Branch is required for recipe costing.');
            return null;
        }

        $belongsToCompany = DB::table('company_branches')
            ->where('company_id', $company->id)
            ->where('id', $branchId)
            ->whereNull('deleted_at')
            ->exists();
        abort_unless($belongsToCompany, 422, 'The selected branch does not belong to this company.');

        if (!$isSuperAdmin) {
            abort_unless(
                $accessibleBranchIds->contains((string) $branchId),
                403,
                'You are not allocated to the selected branch.'
            );
        }

        return (string) $branchId;
    }

    private function nextVersion(
        string $companyId,
        ?string $menuProductId,
        ?string $producedProductId,
        string $recipeType
    ): int {
        return ((int) $this->recipeFamilyQuery(
            $companyId,
            $menuProductId,
            $producedProductId,
            $recipeType
        )->withTrashed()->max('version_no')) + 1;
    }
}
