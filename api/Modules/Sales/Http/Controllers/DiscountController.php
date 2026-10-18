<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\Sales\DiscountEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\Discount;
use Modules\Sales\Models\DiscountCategory;
use Modules\Sales\Models\DiscountProduct;
use Modules\Sales\Models\DiscountRolePermission;
use Modules\Sales\Models\DiscountType;

use function App\Helpers\hasPermission;

class DiscountController extends Controller
{
    public function __construct(
        private readonly DiscountEngineService $discountEngine,
        private readonly CompanyBranchContextService $branchContext
    ) {}

    public function index(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discounts', $company->id);
        if ($permission !== true) return $permission;

        $query = Discount::query()
            ->with([
                'discountType',
                'productTargets.product',
                'categoryTargets.category',
                'rolePermissions.role',
            ])
            ->where('company_id', $company->id)
            ->whereNotNull('discount_type_id')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->q . '%';

                $query->where(function ($query) use ($q) {
                    $query->where('name', 'like', $q)
                        ->orWhere('code', 'like', $q)
                        ->orWhere('coupon_code', 'like', $q);
                });
            })
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
            )
            ->orderBy('priority')
            ->orderBy('name');

        $page = $query->paginate((int) $request->input('per_page', 30));
        $this->hydrateTargetNames($page->getCollection(), $company->id);

        return response()->json([
            'message' => 'Discounts fetched successfully.',
            'data' => $page,
        ]);
    }

    public function show(string $id)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discounts', $company->id);
        if ($permission !== true) return $permission;

        $discount = Discount::query()
            ->with([
                'discountType',
                'productTargets.product',
                'categoryTargets.category',
                'rolePermissions.role',
            ])
            ->where('company_id', $company->id)
            ->whereNotNull('discount_type_id')
            ->findOrFail($id);

        $this->hydrateTargetNames(collect([$discount]), $company->id);

        return response()->json([
            'message' => 'Discount fetched successfully.',
            'data' => $discount,
        ]);
    }

    public function types()
    {
        $company = app('company');

        return response()->json([
            'message' => 'Discount types fetched successfully.',
            'data' => DiscountType::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'manage_discounts', $company->id);
        if ($permission !== true) return $permission;

        $data = $this->validateDiscount($request);
        $data['branch_id'] = $this->resolveBranchId($request);
        $data = $this->normaliseTargetFlags($data, $request);

        return DB::transaction(function () use ($data, $request, $company) {
            $discount = Discount::create([
                ...$data,
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'created_by' => auth()->id(),
            ]);

            $this->syncTargetsAndRoles($discount, $request);

            $fresh = $discount->fresh([
                'discountType',
                'productTargets.product',
                'categoryTargets.category',
                'rolePermissions.role',
            ]);

            $this->hydrateTargetNames(collect([$fresh]), $company->id);

            return response()->json([
                'message' => 'Discount created successfully.',
                'data' => $fresh,
            ], 201);
        });
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'manage_discounts', $company->id);
        if ($permission !== true) return $permission;

        $discount = Discount::where('company_id', $company->id)
            ->whereNotNull('discount_type_id')->findOrFail($id);
        $data = $this->validateDiscount($request, $discount->id);
        $data['branch_id'] = $this->resolveBranchId($request);
        $data = $this->normaliseTargetFlags($data, $request);

        return DB::transaction(function () use ($discount, $data, $request, $company) {
            $discount->update($data);
            $this->syncTargetsAndRoles($discount, $request);

            $fresh = $discount->fresh([
                'discountType',
                'productTargets.product',
                'categoryTargets.category',
                'rolePermissions.role',
            ]);

            $this->hydrateTargetNames(collect([$fresh]), $company->id);

            return response()->json([
                'message' => 'Discount updated successfully.',
                'data' => $fresh,
            ]);
        });
    }

    public function destroy(string $id)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'manage_discounts', $company->id);
        if ($permission !== true) return $permission;

        $discount = Discount::where('company_id', $company->id)
            ->whereNotNull('discount_type_id')->findOrFail($id);
        $discount->delete();

        return response()->json([
            'message' => 'Discount deleted successfully.',
        ]);
    }

    public function eligible(Request $request)
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        if (!$this->discountEngine->discountModuleEnabled($company->id, $branchId)) {
            return response()->json([
                'message' => 'Discount module is disabled.',
                'data' => [],
            ]);
        }

        $rows = $this->discountEngine->listEligibleDiscounts([
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'product_id' => $request->product_id,
            'category_id' => $request->category_id,
            'sale_amount' => $request->sale_amount ?? $request->gross_amount ?? $request->line_amount,
            'quantity' => $request->quantity ?? 1,
            'customer_id' => $request->customer_id,
            'coupon_code' => $request->coupon_code,
            'cashier_role_id' => auth()->user()?->role_id,
        ]);

        $globalRequiresManager = $this->discountEngine->manualDiscountRequiresManager($company->id, $branchId);

        $rows = collect($rows)->map(function ($row) use ($globalRequiresManager) {
            $row['requires_manager_approval'] = (bool) ($row['requires_manager_approval'] ?? false) || $globalRequiresManager;
            return $row;
        })->values()->all();

        return response()->json([
            'message' => 'Eligible discounts fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function calculate(Request $request)
    {
        $company = app('company');
        $branchId = $this->resolveBranchId($request);

        $validator = Validator::make($request->all(), [
            'discount_id' => ['required', 'string', 'max:36'],
            'product_id' => ['nullable', 'string', 'max:36'],
            'category_id' => ['nullable', 'string', 'max:36'],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'customer_id' => ['nullable', 'string', 'max:36'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'string', 'max:36'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->discountEngine->calculateDiscount([
            ...$request->all(),
            'company_id' => $company->id,
            'branch_id' => $branchId,
        ]);

        $globalRequiresManager = $this->discountEngine->manualDiscountRequiresManager($company->id, $branchId);

        $result['requires_manager_approval'] = (bool) ($result['requires_manager_approval'] ?? false) || $globalRequiresManager;
        $result['manager_approval_reason'] = $globalRequiresManager
            ? 'General Setting requires manager approval for discounts.'
            : ($result['manager_approval_reason'] ?? null);

        return response()->json([
            'message' => 'Discount calculated successfully.',
            'data' => $result,
        ]);
    }

    private function validateDiscount(Request $request, ?string $discountId = null): array
    {
        $company = app('company');

        return Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'discount_type_id' => [
                'required',
                'uuid',
                Rule::exists('discount_types', 'id')->where('company_id', $company->id),
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('discounts', 'code')
                    ->where(fn ($query) => $query
                        ->where('company_id', $company->id)
                        ->whereNull('deleted_at'))
                    ->ignore($discountId),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('discounts', 'name')
                    ->where(fn ($query) => $query
                        ->where('company_id', $company->id)
                        ->whereNull('deleted_at'))
                    ->ignore($discountId),
            ],
            'description' => ['nullable', 'string'],
            'discount_basis' => ['required', 'in:percentage,fixed_amount,fixed_price'],
            'value' => [
                'required',
                'numeric',
                'min:0',
                Rule::when($request->input('discount_basis') === 'percentage', ['max:100']),
            ],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_sale_amount' => ['nullable', 'numeric', 'min:0'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'applies_to_all_products' => ['nullable', 'boolean'],
            'applies_to_all_categories' => ['nullable', 'boolean'],
            'requires_customer' => ['nullable', 'boolean'],
            'requires_coupon_code' => ['nullable', 'boolean'],
            'coupon_code' => ['nullable', 'required_if:requires_coupon_code,true', 'string', 'max:100'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'requires_manager_approval' => ['nullable', 'boolean'],
            'requires_reason' => ['nullable', 'boolean'],
            'allow_stacking' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1'],
            'rules' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'uuid',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => [
                'uuid',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'role_permissions' => ['nullable', 'array'],
            'role_permissions.*.role_id' => [
                'required_with:role_permissions',
                'uuid',
                Rule::exists('roles', 'id')->where('company_id', $company->id),
            ],
            'role_permissions.*.max_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'role_permissions.*.max_amount' => ['nullable', 'numeric', 'min:0'],
            'role_permissions.*.can_apply_without_manager' => ['nullable', 'boolean'],
        ])->validate();
    }

    private function normaliseTargetFlags(array $data, Request $request): array
    {
        $productIds = collect((array) $request->input('product_ids', []))->filter()->unique()->values();
        $categoryIds = collect((array) $request->input('category_ids', []))->filter()->unique()->values();

        $data['applies_to_all_products'] = $productIds->isEmpty();
        $data['applies_to_all_categories'] = $categoryIds->isEmpty();

        return $data;
    }

    private function syncTargetsAndRoles(Discount $discount, Request $request): void
    {
        DiscountProduct::where('discount_id', $discount->id)->delete();
        DiscountCategory::where('discount_id', $discount->id)->delete();
        DiscountRolePermission::where('discount_id', $discount->id)->delete();

        foreach (collect((array) $request->input('product_ids', []))->filter()->unique()->values() as $productId) {
            DiscountProduct::create([
                'id' => (string) Str::uuid(),
                'company_id' => $discount->company_id,
                'discount_id' => $discount->id,
                'product_id' => $productId,
            ]);
        }

        foreach (collect((array) $request->input('category_ids', []))->filter()->unique()->values() as $categoryId) {
            DiscountCategory::create([
                'id' => (string) Str::uuid(),
                'company_id' => $discount->company_id,
                'discount_id' => $discount->id,
                'category_id' => $categoryId,
            ]);
        }

        foreach ((array) $request->input('role_permissions', []) as $row) {
            if (empty($row['role_id'])) {
                continue;
            }

            DiscountRolePermission::create([
                'id' => (string) Str::uuid(),
                'company_id' => $discount->company_id,
                'discount_id' => $discount->id,
                'role_id' => $row['role_id'],
                'max_percentage' => $row['max_percentage'] ?? null,
                'max_amount' => $row['max_amount'] ?? null,
                'can_apply_without_manager' => filter_var($row['can_apply_without_manager'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    private function hydrateTargetNames($discounts, string $companyId): void
    {
        $discounts = collect($discounts)->filter();

        if ($discounts->isEmpty()) {
            return;
        }

        $productIds = $discounts
            ->flatMap(fn ($discount) => $discount->productTargets?->pluck('product_id') ?? collect())
            ->filter()
            ->unique()
            ->values();

        $categoryIds = $discounts
            ->flatMap(fn ($discount) => $discount->categoryTargets?->pluck('category_id') ?? collect())
            ->filter()
            ->unique()
            ->values();

        $roleIds = $discounts
            ->flatMap(fn ($discount) => $discount->rolePermissions?->pluck('role_id') ?? collect())
            ->filter()
            ->unique()
            ->values();

        $products = collect();

        if ($productIds->isNotEmpty() && Schema::hasTable('products')) {
            $products = DB::table('products')
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');
        }

        $categories = collect();

        if ($categoryIds->isNotEmpty() && Schema::hasTable('product_categories')) {
            $categories = DB::table('product_categories')
                ->where('company_id', $companyId)
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy('id');
        }

        $roles = collect();

        if ($roleIds->isNotEmpty() && Schema::hasTable('roles')) {
            $roles = DB::table('roles')
                ->where('company_id', $companyId)
                ->whereIn('id', $roleIds)
                ->get()
                ->keyBy('id');
        }

        foreach ($discounts as $discount) {
            foreach (($discount->productTargets ?? []) as $target) {
                $product = $products->get($target->product_id);

                if (!$product) {
                    $target->setAttribute('product_missing', true);
                    $target->setAttribute('product_display', [
                        'id' => $target->product_id,
                        'name' => 'Missing product: ' . $target->product_id,
                        'code' => null,
                        'sku' => null,
                        'barcode' => null,
                    ]);
                    continue;
                }

                $display = [
                    'id' => $product->id,
                    'name' => $product->name ?? $product->product_name ?? $product->description ?? $product->code ?? $product->id,
                    'code' => $product->code ?? null,
                    'sku' => $product->sku ?? null,
                    'barcode' => $product->barcode ?? null,
                    'category_id' => $product->category_id ?? null,
                    'allow_discount' => $product->allow_discount ?? true,
                    'is_active' => $product->is_active ?? true,
                ];

                $target->setAttribute('product_display', $display);

                if (!$target->relationLoaded('product') || !$target->product) {
                    $target->setAttribute('product', $display);
                }
            }

            foreach (($discount->categoryTargets ?? []) as $target) {
                $category = $categories->get($target->category_id);

                if (!$category) {
                    $target->setAttribute('category_missing', true);
                    $target->setAttribute('category_display', [
                        'id' => $target->category_id,
                        'name' => 'Missing category: ' . $target->category_id,
                        'code' => null,
                    ]);
                    continue;
                }

                $display = [
                    'id' => $category->id,
                    'name' => $category->name ?? $category->category_name ?? $category->description ?? $category->code ?? $category->id,
                    'code' => $category->code ?? null,
                ];

                $target->setAttribute('category_display', $display);

                if (!$target->relationLoaded('category') || !$target->category) {
                    $target->setAttribute('category', $display);
                }
            }

            foreach (($discount->rolePermissions ?? []) as $target) {
                $role = $roles->get($target->role_id);

                if (!$role) {
                    continue;
                }

                $display = [
                    'id' => $role->id,
                    'name' => $role->name ?? $role->description ?? $role->id,
                    'group' => $role->group ?? null,
                    'description' => $role->description ?? null,
                ];

                $target->setAttribute('role_display', $display);

                if (!$target->relationLoaded('role') || !$target->role) {
                    $target->setAttribute('role', $display);
                }
            }
        }
    }

    private function resolveBranchId(Request $request): ?string
    {
        return $this->branchContext->resolve(
            $request,
            (string) app('company')->id
        );
    }
}
