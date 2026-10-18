<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Company\Models\AuditTrail;
use Modules\Currency\Models\Currency;
use Modules\Product\Models\PriceLevel;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductPrice;
use Modules\Product\Models\ProductPriceAdjustment;
use Modules\RolesAndPermission\Models\Role;
use function App\Helpers\hasPermission;

class PriceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getPriceLevels(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $query = PriceLevel::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'message' => 'Price levels fetched successfully.',
            'data' => $query->get(),
        ], 200);
    }

    public function storePriceLevel(Request $request)
    {

        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('price_levels', 'name')->withoutTrashed()->where('company_id', $company->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('price_levels', 'code')->withoutTrashed()->where('company_id', $company->id),
            ],
            'description' => 'nullable|string',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->boolean('is_default')) {
            PriceLevel::where('company_id', $company->id)->update(['is_default' => false]);
        }

        $level = PriceLevel::create([
            'company_id' => $company->id,
            'name' => $request->name,
            'code' => $request->code,
            'description' => $request->description,
            'is_default' => $request->is_default ?? false,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json([
            'message' => 'Price level created successfully.',
            'data' => $level,
        ], 201);
    }

    public function updatePriceLevel(Request $request, string $id)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $level = PriceLevel::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$level) {
            return response()->json(['message' => 'Price level not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('price_levels', 'name')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->ignore($level->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('price_levels', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->ignore($level->id),
            ],
            'description' => 'nullable|string',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->boolean('is_default')) {
            PriceLevel::where('company_id', $company->id)
                ->where('id', '!=', $level->id)
                ->update(['is_default' => false]);
        }

        $level->update([
            'name' => $request->name,
            'code' => $request->code,
            'description' => $request->description,
            'is_default' => $request->is_default ?? $level->is_default,
            'is_active' => $request->is_active ?? $level->is_active,
        ]);

        return response()->json([
            'message' => 'Price level updated successfully.',
            'data' => $level->fresh(),
        ]);
    }
    public function deletePriceLevel(string $id)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $level = PriceLevel::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$level) {
            return response()->json(['message' => 'Price level not found.'], 404);
        }

        if ($level->is_default) {
            return response()->json([
                'message' => 'Default price level cannot be deleted.',
            ], 422);
        }

        $level->delete();

        return response()->json([
            'message' => 'Price level deleted successfully.',
        ]);
    }



    public function getRolePriceLevels(string $roleId)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->with('priceLevels')
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Role not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Role price levels fetched successfully.',
            'data' => [
                'role' => $role,
                'assigned_price_level_ids' => $role->priceLevels->pluck('id')->values(),
            ],
        ], 200);
    }

    public function syncRolePriceLevels(Request $request, string $roleId)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            // present, not required: an empty list removes every price level.
            'price_level_ids' => 'present|array',
            'price_level_ids.*' => 'exists:price_levels,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Role not found.',
            ], 404);
        }

        $validPriceLevelIds = PriceLevel::where('company_id', $company->id)
            ->whereIn('id', $request->price_level_ids)
            ->pluck('id')
            ->values()
            ->toArray();

        DB::beginTransaction();

        try {
            $syncData = [];

            foreach ($validPriceLevelIds as $priceLevelId) {
                $syncData[$priceLevelId] = [
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                ];
            }

            $role->priceLevels()->sync($syncData);

            DB::commit();

            return response()->json([
                'message' => 'Role price levels updated successfully.',
                'data' => $role->load('priceLevels'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating role price levels.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getProductPricesForCashier(Request $request, string $productId)
    {

        $company = app('company');
        $user = auth()->user();
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchIds
        );
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];



        $allowedPriceLevelIds = DB::table('role_price_level')
            ->where('company_id', $company->id)
            ->where('role_id', $user->role_id)
            ->pluck('price_level_id')
            ->toArray();

        $productVisible = $this->modeVisibleProducts((string) $company->id, $scope['branch_ids'])
            ->where('id', $productId)
            ->exists();
        if (!$productVisible) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $query = ProductPrice::where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->whereIn('price_level_id', $allowedPriceLevelIds)
            ->with('priceLevel')
            ->orderByDesc('is_default')
            ->orderBy('price');

        if ($branchId) {
            $query->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            });
        }

        return response()->json([
            'message' => 'Visible product prices fetched successfully.',
            'data' => $query->get(),
        ], 200);
    }

    public function productPriceListForLoggedInBranch(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $scope = app(BusinessModeBranchScopeService::class)
            ->resolve($request, (string) $company->id);
        $branchId = $scope['context_branch_id'];

        if (!$branchId) {
            return response()->json([
                'message' => 'Logged-in user branch could not be resolved.',
            ], 422);
        }

        $query = $this->modeVisibleProducts((string) $company->id, $scope['branch_ids'])
            ->with([
                'category',
                'supplier',
                'uom',
                'prices' => function ($priceQuery) use ($company, $branchId) {
                    $priceQuery->where('company_id', $company->id)
                        ->where(function ($sub) use ($branchId) {
                            $sub->whereNull('branch_id')
                                ->orWhere('branch_id', $branchId);
                        })
                        ->with(['priceLevel', 'branch'])
                        ->orderByDesc('is_default')
                        ->orderBy('price');
                },
            ])
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('price_level_id')) {
            $priceLevelId = $request->price_level_id;

            $query->whereHas('prices', function ($priceQuery) use ($company, $branchId, $priceLevelId) {
                $priceQuery->where('company_id', $company->id)
                    ->where('price_level_id', $priceLevelId)
                    ->where(function ($sub) use ($branchId) {
                        $sub->whereNull('branch_id')
                            ->orWhere('branch_id', $branchId);
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 50);

        $products = $query->paginate($perPage);

        return response()->json([
            'message' => 'Product price list fetched successfully.',
            'branch_id' => $branchId,
            'data' => $products,
        ], 200);
    }

    public function adjustProductPrice(Request $request, string $productId)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $scope = app(BusinessModeBranchScopeService::class)
            ->resolve($request, (string) $company->id);
        $loggedInBranchId = $scope['context_branch_id'];

        if (!$loggedInBranchId) {
            return response()->json([
                'message' => 'Logged-in user branch could not be resolved.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'price_level_id' => [
                'required',
                Rule::exists('price_levels', 'id')->where('company_id', $company->id),
            ],
            'price' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'reason' => 'nullable|string|max:1000',
            'adjustment_type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = $this->modeVisibleProducts((string) $company->id, $scope['branch_ids'])
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $priceLevel = PriceLevel::where('company_id', $company->id)
            ->where('id', $request->price_level_id)
            ->first();

        DB::beginTransaction();

        try {
            $currencyCode = $this->validateSelectedBaseCurrency(
                $company->id,
                $loggedInBranchId,
                $request->currency
            );

            $price = ProductPrice::where('company_id', $company->id)
                ->where('product_id', $product->id)
                ->where('branch_id', $loggedInBranchId)
                ->where('price_level_id', $request->price_level_id)
                ->first();

            $oldValues = $price ? [
                'product_price_id' => $price->id,
                'price_level_id' => $price->price_level_id,
                'price_level_name' => $priceLevel?->name,
                'branch_id' => $price->branch_id,
                'price' => $price->price,
                'currency' => $price->currency,
                'is_default' => $price->is_default,
                'is_active' => $price->is_active,
                'starts_at' => $price->starts_at,
                'ends_at' => $price->ends_at,
            ] : null;

            $oldPrice = $price?->price;
            $action = $price ? 'product_price_adjusted' : 'product_price_created';

            if ($request->boolean('is_default')) {
                ProductPrice::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where(function ($sub) use ($loggedInBranchId) {
                        $sub->whereNull('branch_id')
                            ->orWhere('branch_id', $loggedInBranchId);
                    })
                    ->update(['is_default' => false]);
            }

            if ($price) {
                $price->update([
                    'price' => (float) $request->price,
                    'currency' => $currencyCode,
                    'is_default' => $request->has('is_default')
                        ? $request->boolean('is_default')
                        : $price->is_default,
                    'is_active' => $request->has('is_active')
                        ? $request->boolean('is_active')
                        : $price->is_active,
                    'starts_at' => $request->starts_at,
                    'ends_at' => $request->ends_at,
                ]);
            } else {
                $price = ProductPrice::create([
                    'company_id' => $company->id,
                    'branch_id' => $loggedInBranchId,
                    'product_id' => $product->id,
                    'price_level_id' => $request->price_level_id,
                    'price' => (float) $request->price,
                    'currency' => $currencyCode,
                    'is_default' => $request->boolean('is_default', false),
                    'is_active' => $request->boolean('is_active', true),
                    'starts_at' => $request->starts_at,
                    'ends_at' => $request->ends_at,
                ]);
            }

            $price->refresh();

            $newValues = [
                'product_price_id' => $price->id,
                'price_level_id' => $price->price_level_id,
                'price_level_name' => $priceLevel?->name,
                'branch_id' => $price->branch_id,
                'price' => $price->price,
                'currency' => $price->currency,
                'is_default' => $price->is_default,
                'is_active' => $price->is_active,
                'starts_at' => $price->starts_at,
                'ends_at' => $price->ends_at,
            ];

            $changedFields = [];

            if ($oldValues) {
                foreach ($newValues as $key => $value) {
                    if (($oldValues[$key] ?? null) != $value) {
                        $changedFields[] = $key;
                    }
                }
            } else {
                $changedFields = array_keys($newValues);
            }

            ProductPriceAdjustment::create([
                'company_id' => $company->id,
                'branch_id' => $loggedInBranchId,
                'product_id' => $product->id,
                'product_price_id' => $price->id,
                'price_level_id' => $request->price_level_id,
                'old_price' => $oldPrice,
                'new_price' => (float) $request->price,
                'currency' => $currencyCode,
                'adjustment_type' => $request->adjustment_type ?? 'manual',
                'reason' => $request->reason,
                'adjusted_by' => $user->id,
                'adjusted_at' => now(),
                'metadata' => [
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'price_level_name' => $priceLevel?->name,
                    'branch_id' => $loggedInBranchId,
                    'currency' => $currencyCode,
                ],
            ]);

            AuditTrail::create([
                'company_id' => $company->id,
                'branch_id' => $loggedInBranchId,
                'user_id' => $user->id,
                'user_name' => $user->name ?? null,
                'user_email' => $user->email ?? null,
                'user_role' => $user->role?->name ?? $user->role_id ?? null,

                'action' => $action,
                'module' => 'pricing',
                'description' => $oldValues
                    ? "Changed selling price for {$product->name}"
                    : "Created selling price for {$product->name}",

                'auditable_type' => ProductPrice::class,
                'auditable_id' => $price->id,

                'old_values' => $oldValues,
                'new_values' => $newValues,
                'changed_fields' => $changedFields,

                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_name' => $request->header('X-Device-Name'),
                'terminal_name' => $request->header('X-Terminal-Name'),
                'session_id' => $this->getSafeSessionId($request),

                'status' => 'success',
                'error_message' => null,

                'metadata' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'price_level_id' => $request->price_level_id,
                    'price_level_name' => $priceLevel?->name,
                    'currency' => $currencyCode,
                    'reason' => $request->reason,
                    'adjustment_type' => $request->adjustment_type ?? 'manual',
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Product price adjusted successfully.',
                'currency' => $currencyCode,
                'data' => $price->fresh(['priceLevel', 'branch']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            try {
                AuditTrail::create([
                    'company_id' => $company->id,
                    'branch_id' => $loggedInBranchId,
                    'user_id' => $user->id,
                    'user_name' => $user->name ?? null,
                    'user_email' => $user->email ?? null,
                    'user_role' => $user->role?->name ?? $user->role_id ?? null,

                    'action' => 'product_price_adjust_failed',
                    'module' => 'pricing',
                    'description' => "Failed to adjust selling price for {$product->name}",

                    'auditable_type' => Product::class,
                    'auditable_id' => $product->id,

                    'old_values' => null,
                    'new_values' => [
                        'price_level_id' => $request->price_level_id,
                        'price' => $request->price,
                        'currency' => $request->currency,
                    ],
                    'changed_fields' => ['price', 'currency'],

                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'device_name' => $request->header('X-Device-Name'),
                    'terminal_name' => $request->header('X-Terminal-Name'),
                    'session_id' => $this->getSafeSessionId($request),

                    'status' => 'failed',
                    'error_message' => $e->getMessage(),

                    'metadata' => [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_code' => $product->code,
                        'requested_currency' => $request->currency,
                        'reason' => $request->reason,
                        'adjustment_type' => $request->adjustment_type ?? 'manual',
                    ],
                ]);
            } catch (\Exception $auditException) {
                logger()->error('Failed to write failed price adjustment audit trail.', [
                    'error' => $auditException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Something went wrong while adjusting product price.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function productPriceAdjustmentHistory(Request $request, string $productId)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'price_management', $company->id);
        if ($result !== true) return $result;

        $scope = app(BusinessModeBranchScopeService::class)
            ->resolve($request, (string) $company->id);
        $loggedInBranchId = $scope['context_branch_id'];

        $product = $this->modeVisibleProducts((string) $company->id, $scope['branch_ids'])
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $query = ProductPriceAdjustment::where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->where('branch_id', $loggedInBranchId)
            ->with(['priceLevel','adjudtedby'])
            ->orderByDesc('adjusted_at');

        $perPage = (int) $request->get('per_page', 50);

        return response()->json([
            'message' => 'Product price adjustment history fetched successfully.',
            'data' => $query->paginate($perPage),
        ], 200);
    }

    private function validateSelectedBaseCurrency(
        string $companyId,
        ?string $branchId,
        ?string $currencyCode
    ): string {
        if (!$currencyCode) {
            throw new \Exception('Currency is required.');
        }

        $currencyCode = strtoupper(trim($currencyCode));

        $currency = Currency::where('company_id', $companyId)
            ->where('code', $currencyCode)
            ->where('is_active', true)
            ->where('is_base_currency', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->first();

        if (!$currency) {
            throw new \Exception(
                'Selected currency must exist and must be the active base currency.'
            );
        }

        return $currencyCode;
    }

    private function modeVisibleProducts(string $companyId, array $branchIds)
    {
        $query = Product::where('company_id', $companyId);

        if (!Schema::hasColumn('products', 'is_global')) {
            return $query;
        }

        return $query->where(function ($scope) use ($branchIds) {
            $scope->where('is_global', true)
                ->orWhereIn('branch_id', $branchIds);
        });
    }

    private function getSafeSessionId(Request $request): ?string
    {
        try {
            return $request->hasSession() ? $request->session()->getId() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('product::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('product::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('product::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
