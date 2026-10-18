<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\Hospitality\HospitalityModifierOptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\BusinessModeBranchScopeService;
use Modules\Product\Models\Product;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\Company\Models\Shop;
use Modules\Sales\Models\ProductModifier;
use Modules\Sales\Models\ProductModifierAssignment;
use Modules\Sales\Models\ProductPrepMessage;
use Modules\Sales\Models\ProductPrepMessageAssignment;

use function App\Helpers\hasPermission;

class FastFoodPrepModifierController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Product Options
    |--------------------------------------------------------------------------
    | Used by POS when cashier clicks a menu item.
    */
    public function productOptions(Request $request, string $productId)
    {
        $company = app('company');
        $branchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
        $brandId = $branchId
            ? $this->brandIdForBranch((string) $company->id, (string) $branchId)
            : null;

        $prepMessages = ProductPrepMessageAssignment::query()
            ->with('prepMessage')
            ->where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->when($brandId && Schema::hasColumn('product_prep_message_assignments', 'brand_id'),
                fn ($query) => $query->where('brand_id', $brandId))
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => $row->prepMessage)
            ->filter(fn ($row) => $row && (bool) $row->is_active)
            ->values();

        $modifiers = ProductModifierAssignment::query()
            ->with('modifier')
            ->where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->when($brandId && Schema::hasColumn('product_modifier_assignments', 'brand_id'),
                fn ($query) => $query->where('brand_id', $brandId))
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => $row->modifier)
            ->filter(fn ($row) => $row && (bool) $row->is_active)
            ->values();

        // Brand catalogue modifier groups (imported from the BOM workbooks)
        // are offered alongside directly assigned modifiers.
        $catalogueModifiers = app(HospitalityModifierOptionService::class)
            ->optionsForMenuItem((string) $company->id, $branchId ? (string) $branchId : null, $productId);
        $modifiers = $modifiers->concat($catalogueModifiers)->values();

        return response()->json([
            'message' => 'Product prep messages and modifiers fetched successfully.',
            'data' => [
                'prep_messages' => $prepMessages,
                'modifiers' => $modifiers,
            ],
        ]);
    }

    /**
     * Back Office variant of productOptions.
     *
     * Menu configuration is a branch-scoped administrative operation and must
     * not require a registered selling till. The original /options endpoint
     * remains till-bound for POS use.
     */
    public function configurationProductOptions(
        Request $request,
        string $productId,
        BusinessModeBranchScopeService $branchScope
    ) {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $branchScope->resolve($request, (string) $company->id, $requestedBranchIds);
        $branchId = (string) ($requestedBranchIds[0] ?? $scope['context_branch_id']);

        $productExists = $this->configurableMenuProducts((string) $company->id, $branchId)
            ->where('id', $productId)
            ->exists();

        if (!$productExists) {
            return response()->json(['message' => 'Menu item not found in the active Hospitality workspace.'], 404);
        }

        $request->merge([
            'branch_id' => $branchId,
        ]);

        return $this->productOptions($request, $productId);
    }

    /*
    |--------------------------------------------------------------------------
    | Prep Message CRUD
    |--------------------------------------------------------------------------
    */

    public function prepMessages(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $query = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->withCount([
                'assignments as assigned_products_count' => function ($query) use ($branchId) {
                    $query->where('is_active', true)
                        ->where(function ($scope) use ($branchId) {
                            $scope->whereNull('branch_id')->orWhere('branch_id', $branchId);
                        });
                },
            ])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->when($request->q, function ($query) use ($request) {
                $term = trim($request->q);

                $query->where(function ($sub) use ($term) {
                    $sub->where('message', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%");
                });
            })
            ->orderBy('message');

        return response()->json([
            'message' => 'Prep messages fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 50),
        ]);
    }

    public function showPrepMessage(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $message = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with([
                'assignments' => function ($query) use ($branchId) {
                    $query->where(function ($scope) use ($branchId) {
                        $scope->whereNull('branch_id')->orWhere('branch_id', $branchId);
                    })->with('product')->orderBy('sort_order');
                },
            ])
            ->first();

        if (!$message) {
            return response()->json([
                'message' => 'Prep message not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Prep message fetched successfully.',
            'data' => $message,
        ]);
    }

    public function storePrepMessage(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('product_prep_messages', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id ?: null)),
            ],
            'allow_free_text' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        return DB::transaction(function () use ($request, $company, $branchId) {
            $message = ProductPrepMessage::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'brand_id' => $this->brandIdForBranch((string) $company->id, $branchId),
                'branch_id' => $branchId,
                'message' => trim($request->message),
                'code' => $request->code ?: null,
                'allow_free_text' => $request->boolean('allow_free_text', false),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->syncPrepMessageProducts(
                prepMessage: $message,
                productIds: $request->input('product_ids', []),
                branchId: $branchId
            );

            return response()->json([
                'message' => 'Prep message saved successfully.',
                'data' => $message->fresh(),
            ], 201);
        });
    }

    public function updatePrepMessage(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $message = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$message) {
            return response()->json([
                'message' => 'Prep message not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('product_prep_messages', 'code')->withoutTrashed()
                    ->ignore($message->id)
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id ?: null)),
            ],
            'allow_free_text' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        return DB::transaction(function () use ($request, $message, $branchId) {
            $message->update([
                'brand_id' => $this->brandIdForBranch((string) $message->company_id, $branchId),
                'branch_id' => $branchId,
                'message' => trim($request->message),
                'code' => $request->code ?: null,
                'allow_free_text' => $request->boolean('allow_free_text', false),
                'is_active' => $request->boolean('is_active', true),
            ]);

            if ($request->has('product_ids')) {
                $this->syncPrepMessageProducts(
                    prepMessage: $message,
                    productIds: $request->input('product_ids', []),
                    branchId: $branchId
                );
            }

            return response()->json([
                'message' => 'Prep message updated successfully.',
                'data' => $message->fresh(),
            ]);
        });
    }

    public function destroyPrepMessage(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $message = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$message) {
            return response()->json([
                'message' => 'Prep message not found.',
            ], 404);
        }

        return DB::transaction(function () use ($message) {
            $message->update([
                'is_active' => false,
            ]);

            ProductPrepMessageAssignment::query()
                ->where('prep_message_id', $message->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Prep message deactivated successfully.',
                'data' => $message->fresh(),
            ]);
        });
    }

    public function assignPrepMessageToProducts(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $message = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$message) {
            return response()->json([
                'message' => 'Prep message not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        foreach ($request->input('product_ids', []) as $index => $productId) {
            $this->upsertPrepAssignment(
                companyId: $company->id,
                branchId: $branchId,
                productId: $productId,
                prepMessageId: $message->id,
                sortOrder: (int) $request->input('sort_order', $index + 1)
            );
        }

        return response()->json([
            'message' => 'Prep message assigned successfully.',
            'data' => $message->fresh(),
        ]);
    }

    public function removePrepMessageFromProduct(Request $request, string $id, string $productId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $messageExists = $this->applyConfigurationBranchVisibility(
            ProductPrepMessage::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->exists();

        if (!$messageExists || !$this->configurableMenuProducts((string) $company->id, $branchId)->where('id', $productId)->exists()) {
            return response()->json(['message' => 'Prep message or menu item was not found in the active Hospitality workspace.'], 404);
        }

        ProductPrepMessageAssignment::query()
            ->where('company_id', $company->id)
            ->where('prep_message_id', $id)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Prep message removed from product successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Modifier CRUD
    |--------------------------------------------------------------------------
    */

    public function modifiers(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $query = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->withCount([
                'assignments as assigned_products_count' => function ($query) use ($branchId) {
                    $query->where('is_active', true)
                        ->where(function ($scope) use ($branchId) {
                            $scope->whereNull('branch_id')->orWhere('branch_id', $branchId);
                        });
                },
            ])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->when($request->q, function ($query) use ($request) {
                $term = trim($request->q);

                $query->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%");
                });
            })
            ->orderBy('name');

        return response()->json([
            'message' => 'Modifiers fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 50),
        ]);
    }

    public function showModifier(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $modifier = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with([
                'assignments' => function ($query) use ($branchId) {
                    $query->where(function ($scope) use ($branchId) {
                        $scope->whereNull('branch_id')->orWhere('branch_id', $branchId);
                    })->with('product')->orderBy('sort_order');
                },
            ])
            ->first();

        if (!$modifier) {
            return response()->json([
                'message' => 'Modifier not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Modifier fetched successfully.',
            'data' => $modifier,
        ]);
    }

    public function storeModifier(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('product_modifiers', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id ?: null)),
            ],
            'modifier_product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'recipe_id' => [
                'nullable',
                'string',
                Rule::exists('food_recipes', 'id')->where('company_id', $company->id),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'deduct_recipe_stock' => ['nullable', 'boolean'],
            'print_on_kitchen' => ['nullable', 'boolean'],
            'print_on_customer_receipt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        return DB::transaction(function () use ($request, $company, $branchId) {
            $modifier = ProductModifier::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'brand_id' => $this->brandIdForBranch((string) $company->id, $branchId),
                'branch_id' => $branchId,
                'name' => trim($request->name),
                'code' => $request->code ?: null,
                'modifier_product_id' => $request->modifier_product_id ?: null,
                'recipe_id' => $request->recipe_id ?: null,
                'price' => (float) $request->input('price', 0),
                'currency_code' => $request->currency_code ?: null,
                'deduct_recipe_stock' => $request->boolean('deduct_recipe_stock', true),
                'print_on_kitchen' => $request->boolean('print_on_kitchen', true),
                'print_on_customer_receipt' => $request->boolean('print_on_customer_receipt', true),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->syncModifierProducts(
                modifier: $modifier,
                productIds: $request->input('product_ids', []),
                branchId: $branchId
            );

            return response()->json([
                'message' => 'Modifier saved successfully.',
                'data' => $modifier->fresh(),
            ], 201);
        });
    }

    public function updateModifier(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $modifier = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$modifier) {
            return response()->json([
                'message' => 'Modifier not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('product_modifiers', 'code')->withoutTrashed()
                    ->ignore($modifier->id)
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id ?: null)),
            ],
            'modifier_product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'recipe_id' => [
                'nullable',
                'string',
                Rule::exists('food_recipes', 'id')->where('company_id', $company->id),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'deduct_recipe_stock' => ['nullable', 'boolean'],
            'print_on_kitchen' => ['nullable', 'boolean'],
            'print_on_customer_receipt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        return DB::transaction(function () use ($request, $modifier, $branchId) {
            $modifier->update([
                'brand_id' => $this->brandIdForBranch((string) $modifier->company_id, $branchId),
                'branch_id' => $branchId,
                'name' => trim($request->name),
                'code' => $request->code ?: null,
                'modifier_product_id' => $request->modifier_product_id ?: null,
                'recipe_id' => $request->recipe_id ?: null,
                'price' => (float) $request->input('price', 0),
                'currency_code' => $request->currency_code ?: null,
                'deduct_recipe_stock' => $request->boolean('deduct_recipe_stock', true),
                'print_on_kitchen' => $request->boolean('print_on_kitchen', true),
                'print_on_customer_receipt' => $request->boolean('print_on_customer_receipt', true),
                'is_active' => $request->boolean('is_active', true),
            ]);

            if ($request->has('product_ids')) {
                $this->syncModifierProducts(
                    modifier: $modifier,
                    productIds: $request->input('product_ids', []),
                    branchId: $branchId
                );
            }

            return response()->json([
                'message' => 'Modifier updated successfully.',
                'data' => $modifier->fresh(),
            ]);
        });
    }

    public function destroyModifier(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $modifier = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$modifier) {
            return response()->json([
                'message' => 'Modifier not found.',
            ], 404);
        }

        return DB::transaction(function () use ($modifier) {
            $modifier->update([
                'is_active' => false,
            ]);

            ProductModifierAssignment::query()
                ->where('modifier_id', $modifier->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Modifier deactivated successfully.',
                'data' => $modifier->fresh(),
            ]);
        });
    }

    public function assignModifierToProducts(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $modifier = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$modifier) {
            return response()->json([
                'message' => 'Modifier not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'string',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($invalidProducts = $this->invalidMenuProductsResponse(
            $request->input('product_ids', []),
            (string) $company->id,
            $branchId
        )) {
            return $invalidProducts;
        }

        foreach ($request->input('product_ids', []) as $index => $productId) {
            $this->upsertModifierAssignment(
                companyId: $company->id,
                branchId: $branchId,
                productId: $productId,
                modifierId: $modifier->id,
                sortOrder: (int) $request->input('sort_order', $index + 1)
            );
        }

        return response()->json([
            'message' => 'Modifier assigned successfully.',
            'data' => $modifier->fresh(),
        ]);
    }

    public function removeModifierFromProduct(Request $request, string $id, string $productId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'product_management', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $branchId = $this->resolveHospitalityBranchId($request, $company);

        $modifierExists = $this->applyConfigurationBranchVisibility(
            ProductModifier::query(),
            $branchId
        )
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->exists();

        if (!$modifierExists || !$this->configurableMenuProducts((string) $company->id, $branchId)->where('id', $productId)->exists()) {
            return response()->json(['message' => 'Modifier or menu item was not found in the active Hospitality workspace.'], 404);
        }

        ProductModifierAssignment::query()
            ->where('company_id', $company->id)
            ->where('modifier_id', $id)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Modifier removed from product successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve and pin every Back Office configuration operation to the active
     * Hospitality branch. A payload branch is a filter, never a mode switch.
     */
    private function resolveHospitalityBranchId(Request $request, mixed $company): string
    {
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchIds
        );

        if ($scope['business_mode'] !== 'hospitality') {
            abort(409, 'Menu item configuration is only available in Hospitality mode.');
        }

        $branchId = (string) ($requestedBranchIds[0] ?? $scope['context_branch_id']);
        $request->merge(['branch_id' => $branchId]);

        return $branchId;
    }

    private function applyConfigurationBranchVisibility($query, string $branchId)
    {
        $query->where(function ($branchScope) use ($branchId) {
            $branchScope->whereNull('branch_id')
                ->orWhere('branch_id', $branchId);
        });

        $table = $query->getModel()->getTable();
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull("{$table}.deleted_at");
        }
        if (Schema::hasColumn($table, 'brand_id')) {
            $companyId = (string) app('company')->id;
            $brandId = $this->brandIdForBranch($companyId, $branchId);
            // Fail closed when the branch has no canonical shop/brand. This
            // prevents a company-global option from crossing brand borders.
            $brandId
                ? $query->where("{$table}.brand_id", $brandId)
                : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Only branch-owned finished goods may be configured. Legacy global menu
     * products remain compatible only when both an active branch price and an
     * active menu BOM identify them as Hospitality menu items.
     */
    private function configurableMenuProducts(string $companyId, string $branchId)
    {
        $query = Product::query()
            ->where('company_id', $companyId)
            ->where('product_type', 'finished_good')
            ->where('is_active', true);

        $brandId = $this->brandIdForBranch($companyId, $branchId);
        if ($brandId && Schema::hasTable('brand_products')) {
            return $query->whereExists(function ($catalogue) use ($brandId): void {
                $catalogue->selectRaw('1')
                    ->from('brand_products')
                    ->whereColumn('brand_products.product_id', 'products.id')
                    ->where('brand_products.brand_id', $brandId)
                    ->where('brand_products.item_kind', 'menu_item')
                    ->where('brand_products.is_active', true)
                    ->whereNull('brand_products.deleted_at');
            });
        }

        if (!Schema::hasColumn('products', 'is_global') || !Schema::hasColumn('products', 'branch_id')) {
            return $query;
        }

        return $query->where(function ($menuScope) use ($branchId) {
            $menuScope->where(function ($owned) use ($branchId) {
                $owned->where('is_global', false)
                    ->where('branch_id', $branchId);
            });

            if (!Schema::hasTable('food_recipes')) {
                return;
            }

            $menuScope->orWhere(function ($legacy) use ($branchId) {
                $legacy->where('is_global', true)
                    ->whereNull('branch_id')
                    ->whereHas('prices', function ($price) use ($branchId) {
                        $price->where('branch_id', $branchId)
                            ->where('is_active', true);
                    })
                    ->whereExists(function ($recipe) {
                        $recipe->selectRaw('1')
                            ->from('food_recipes')
                            ->whereColumn('food_recipes.menu_product_id', 'products.id')
                            ->where('food_recipes.recipe_type', 'menu')
                            ->where('food_recipes.is_active', true)
                            ->whereNull('food_recipes.deleted_at');
                    });
            });
        });
    }

    private function brandIdForBranch(string $companyId, string $branchId): ?string
    {
        if (! Schema::hasTable('shops')) {
            return null;
        }

        $brandId = Shop::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->value('brand_id');

        return is_scalar($brandId) && trim((string) $brandId) !== ''
            ? (string) $brandId
            : null;
    }

    private function invalidMenuProductsResponse(
        array $productIds,
        string $companyId,
        string $branchId
    ) {
        $productIds = collect($productIds)
            ->filter(fn ($id) => is_scalar($id) && trim((string) $id) !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return null;
        }

        $visibleIds = $this->configurableMenuProducts($companyId, $branchId)
            ->whereIn('id', $productIds->all())
            ->pluck('id')
            ->map(fn ($id) => (string) $id);
        $invalidIds = $productIds->diff($visibleIds)->values()->all();

        if ($invalidIds === []) {
            return null;
        }

        return response()->json([
            'message' => 'Only active finished-good menu items from the current Hospitality branch can be configured.',
            'errors' => [
                'product_ids' => ['One or more selected products are not available in this Hospitality menu.'],
            ],
            'invalid_product_ids' => $invalidIds,
        ], 422);
    }

    private function syncPrepMessageProducts(
        ProductPrepMessage $prepMessage,
        array $productIds,
        ?string $branchId = null
    ): void {
        $companyId = $prepMessage->company_id;
        $productIds = array_values(array_unique(array_filter($productIds)));

        ProductPrepMessageAssignment::query()
            ->where('company_id', $companyId)
            ->where('prep_message_id', $prepMessage->id)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        foreach ($productIds as $index => $productId) {
            $this->upsertPrepAssignment(
                companyId: $companyId,
                branchId: $branchId,
                productId: $productId,
                prepMessageId: $prepMessage->id,
                sortOrder: $index + 1
            );
        }
    }

    private function syncModifierProducts(
        ProductModifier $modifier,
        array $productIds,
        ?string $branchId = null
    ): void {
        $companyId = $modifier->company_id;
        $productIds = array_values(array_unique(array_filter($productIds)));

        ProductModifierAssignment::query()
            ->where('company_id', $companyId)
            ->where('modifier_id', $modifier->id)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        foreach ($productIds as $index => $productId) {
            $this->upsertModifierAssignment(
                companyId: $companyId,
                branchId: $branchId,
                productId: $productId,
                modifierId: $modifier->id,
                sortOrder: $index + 1
            );
        }
    }

    private function upsertPrepAssignment(
        string $companyId,
        ?string $branchId,
        string $productId,
        string $prepMessageId,
        int $sortOrder = 0
    ): void {
        $brandId = $branchId ? $this->brandIdForBranch($companyId, $branchId) : null;
        $assignment = ProductPrepMessageAssignment::query()
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('product_prep_message_assignments', 'brand_id'),
                fn ($query) => $query->where('brand_id', $brandId))
            ->where('product_id', $productId)
            ->where('prep_message_id', $prepMessageId)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->first();

        if ($assignment) {
            $assignment->update([
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]);

            return;
        }

        ProductPrepMessageAssignment::create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'prep_message_id' => $prepMessageId,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function upsertModifierAssignment(
        string $companyId,
        ?string $branchId,
        string $productId,
        string $modifierId,
        int $sortOrder = 0
    ): void {
        $brandId = $branchId ? $this->brandIdForBranch($companyId, $branchId) : null;
        $assignment = ProductModifierAssignment::query()
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('product_modifier_assignments', 'brand_id'),
                fn ($query) => $query->where('brand_id', $brandId))
            ->where('product_id', $productId)
            ->where('modifier_id', $modifierId)
            ->where(function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereNull('branch_id');
                }
            })
            ->first();

        if ($assignment) {
            $assignment->update([
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]);

            return;
        }

        ProductModifierAssignment::create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'modifier_id' => $modifierId,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }
}
