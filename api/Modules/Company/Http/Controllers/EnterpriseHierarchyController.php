<?php

namespace Modules\Company\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Country;
use Modules\Company\Models\Shop;
use Modules\Company\Models\ShopMenuItemOverride;
use Modules\GeneralSettings\Models\PosTill;

class EnterpriseHierarchyController extends Controller
{
    public function __construct(
        private readonly EnterpriseHierarchyService $hierarchy,
        private readonly SyncConfigurationService $sync,
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly EnterpriseScopeResolver $scopes,
    ) {}

    public function tree(Request $request): JsonResponse
    {
        $countries = Country::query()
            ->with(['companies' => fn ($query) => $query
                ->with([
                    'brands' => fn ($brands) => $brands->with(['shops.complex', 'shops.tills']),
                    'complexes' => fn ($complexes) => $complexes->with(['shops.brand', 'shops.tills']),
                ])])
            ->when($request->filled('status'), fn ($query) => $query
                ->where('is_active', $request->string('status')->lower()->value() === 'active'))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json(['data' => $countries]);
    }

    /**
     * Tenant-safe hierarchy projection for Back Office selectors.
     *
     * Global hierarchy CRUD remains a platform-owner operation. This endpoint
     * never accepts a company identifier from the client: it starts from the
     * domain-resolved company and intersects shops/tills with the caller's
     * active canonical assignments. A till-only grant cannot enumerate sibling
     * tills, and an assignment in another tenant can never enter this tree.
     */
    public function tenantTree(Request $request): JsonResponse
    {
        $company = app()->bound('company') ? app('company') : null;
        $user = $request->user();
        if (! $company instanceof Company || ! $user instanceof PosUser) {
            return response()->json([
                'message' => 'A resolved company and authenticated Back Office user are required.',
                'code' => 'ENTERPRISE_SCOPE_REQUIRED',
            ], 403);
        }

        $companyScopeAccess = $this->authorization->hasScopeAccess(
            $user,
            $this->scopes->fromCompany($company)
        );
        if ((string) $user->company_id !== (string) $company->id && ! $companyScopeAccess) {
            return response()->json([
                'message' => 'This user cannot access the resolved company hierarchy.',
                'code' => 'ENTERPRISE_SCOPE_DENIED',
            ], 403);
        }

        $usesScopedAssignments = $this->authorization->usesScopedAssignments($user);
        $assignments = collect();
        $fullCompanyAccess = false;
        if ($usesScopedAssignments) {
            $assignments = UserAccessAssignment::query()
                ->where('pos_user_id', $user->getKey())
                ->effectiveAt(now())
                ->whereHas('role', function ($role) use ($company): void {
                    $role->where(function ($tenant) use ($company): void {
                        $tenant->whereNull('company_id')->orWhere('company_id', $company->id);
                    });
                })
                ->get()
                ->filter(fn (UserAccessAssignment $assignment): bool => $assignment->scope_type === 'global'
                    || ($assignment->scope_type === 'country'
                        && (string) $assignment->country_id === (string) $company->country_id)
                    || (string) $assignment->company_id === (string) $company->id
                )->values();

            if ($assignments->isEmpty()) {
                return response()->json([
                    'message' => 'No active enterprise assignment grants access to this company.',
                    'code' => 'ENTERPRISE_SCOPE_DENIED',
                ], 403);
            }
            $fullCompanyAccess = $assignments->contains(fn (UserAccessAssignment $assignment): bool => $assignment->scope_type === 'global'
                || $assignment->scope_type === 'country'
                || $assignment->scope_type === 'company'
            );
        } else {
            // Transitional users keep the established tenant manager role
            // until their first canonical assignment is created. Once any
            // assignment exists, even a revoked one, this fallback is closed.
            $fullCompanyAccess = $companyScopeAccess || $this->legacyCanReadHierarchy($user);
            if (! $fullCompanyAccess) {
                return response()->json([
                    'message' => 'This Back Office role cannot view enterprise hierarchy.',
                    'code' => 'ENTERPRISE_SCOPE_DENIED',
                ], 403);
            }
        }

        $allBranchIds = CompanyBranch::query()
            ->where('company_id', $company->id)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $branchIds = $fullCompanyAccess
            ? $allBranchIds
            : $this->authorization->accessibleBranchIds($user, (string) $company->id, $allBranchIds);

        $shops = Shop::query()
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->with(['country', 'brand', 'complex'])
            ->orderBy('name')
            ->paginate($this->perPage($request));
        $shopCollection = $shops->getCollection();

        $brandIds = $assignments->where('scope_type', 'brand')->pluck('brand_id')->filter();
        $complexIds = $assignments->where('scope_type', 'complex')->pluck('complex_id')->filter();
        $assignedShopIds = $assignments->where('scope_type', 'shop')->pluck('shop_id')->filter();
        $assignedTillIds = $assignments->where('scope_type', 'till')->pluck('till_id')->filter();
        $fullTillShopIds = $fullCompanyAccess
            ? $shopCollection->pluck('id')
            : $shopCollection->filter(fn (Shop $shop): bool => $brandIds->contains($shop->brand_id)
                || $complexIds->contains($shop->complex_id)
                || $assignedShopIds->contains($shop->id)
            )->pluck('id');

        $pageShopIds = $shopCollection->pluck('id');
        $pageBranchIds = $shopCollection->pluck('branch_id');
        $tills = PosTill::query()
            ->where('company_id', $company->id)
            ->where(function ($scope) use (
                $fullTillShopIds,
                $assignedTillIds,
                $pageShopIds,
                $pageBranchIds
            ): void {
                if ($fullTillShopIds->isNotEmpty()) {
                    $scope->whereIn('shop_id', $fullTillShopIds->all());
                } else {
                    $scope->whereRaw('1 = 0');
                }
                if ($assignedTillIds->isNotEmpty()) {
                    $scope->orWhere(function ($direct) use (
                        $assignedTillIds,
                        $pageShopIds,
                        $pageBranchIds
                    ): void {
                        $direct->whereIn('id', $assignedTillIds->all())
                            ->where(function ($page) use ($pageShopIds, $pageBranchIds): void {
                                $page->whereIn('shop_id', $pageShopIds->all())
                                    ->orWhere(function ($legacy) use ($pageBranchIds): void {
                                        $legacy->whereNull('shop_id')
                                            ->whereIn('branch_id', $pageBranchIds->all());
                                    });
                            });
                    });
                }
            })
            ->orderBy('till_name')
            ->get()
            ->groupBy(fn (PosTill $till): string => (string) ($till->shop_id ?: $till->branch_id));

        $brands = $shopCollection
            ->groupBy(fn (Shop $shop): string => (string) $shop->brand_id)
            ->map(function ($brandShops) use ($tills): array {
                /** @var Shop $first */
                $first = $brandShops->first();

                return [
                    'id' => (string) $first->brand_id,
                    'code' => $first->brand?->code,
                    'name' => $first->brand?->name,
                    'logo' => $first->brand?->logo_url ?: $first->brand?->logo_path,
                    'status' => $first->brand?->status,
                    'complexes' => $brandShops
                        ->groupBy(fn (Shop $shop): string => (string) $shop->complex_id)
                        ->map(function ($complexShops) use ($tills): array {
                            /** @var Shop $firstShop */
                            $firstShop = $complexShops->first();

                            return [
                                'id' => (string) $firstShop->complex_id,
                                'code' => $firstShop->complex?->code,
                                'name' => $firstShop->complex?->name,
                                'status' => $firstShop->complex?->status,
                                'shops' => $complexShops->map(fn (Shop $shop): array => [
                                    'id' => (string) $shop->id,
                                    'branch_id' => (string) $shop->branch_id,
                                    'code' => $shop->code,
                                    'name' => $shop->name,
                                    'timezone' => $shop->timezone,
                                    'pos_login_mode' => $shop->pos_login_mode,
                                    'configuration_version' => (int) $shop->configuration_version,
                                    'status' => $shop->status,
                                    'tills' => $tills->get((string) $shop->id, collect())
                                        ->map(fn (PosTill $till): array => [
                                            'id' => (string) $till->id,
                                            'code' => $till->till_code,
                                            'name' => $till->till_name,
                                            'device_label' => $till->device_label,
                                            'configuration_version' => (int) $till->configuration_version,
                                            'is_registered' => (bool) $till->is_registered,
                                            'is_active' => (bool) $till->is_active,
                                        ])->values()->all(),
                                ])->values()->all(),
                            ];
                        })->values()->all(),
                ];
            })->values()->all();

        $country = $shopCollection->first()?->country ?: $company->country()->first();

        return response()->json([
            'data' => [
                'scope_filtered' => true,
                'country' => $country ? [
                    'id' => (string) $country->id,
                    'code' => $country->code,
                    'name' => $country->name,
                    'currency_code' => $country->currency_code,
                    'timezone' => $country->timezone,
                    'image' => $country->image_url ?: $country->image_path,
                ] : null,
                'company' => [
                    'id' => (string) $company->id,
                    'code' => $company->company_code,
                    'name' => $company->company_name,
                    'logo' => $company->logo,
                    'status' => $company->status,
                    'brands' => $brands,
                ],
            ],
            'meta' => [
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'per_page' => $shops->perPage(),
                'total_shops' => $shops->total(),
            ],
        ]);
    }

    public function countries(Request $request): JsonResponse
    {
        $query = Country::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where(fn ($scope) => $scope
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('iso_alpha_2', 'like', "%{$term}%"));
            })
            ->orderBy('name');

        return response()->json(['data' => $query->paginate($this->perPage($request))]);
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $data = $request->validate($this->countryRules());
        $data['is_active'] ??= true;
        $country = Country::query()->create($this->normaliseCountry($data));

        return response()->json(['message' => 'Country created.', 'data' => $country], 201);
    }

    public function updateCountry(Request $request, Country $country): JsonResponse
    {
        $data = $request->validate($this->countryRules($country));
        $country->update($this->normaliseCountry($data));

        return response()->json(['message' => 'Country updated.', 'data' => $country->fresh()]);
    }

    public function destroyCountry(Country $country): JsonResponse
    {
        if ($country->companies()->exists()) {
            return $this->conflict('Country has companies and cannot be deleted.');
        }
        $country->delete();

        return response()->json(['message' => 'Country deleted.']);
    }

    public function companies(Request $request, Country $country): JsonResponse
    {
        $companies = $country->companies()
            ->withCount(['brands', 'shops'])
            ->orderBy('company_name')
            ->paginate($this->perPage($request));

        return response()->json(['data' => $companies]);
    }

    public function assignCompanyCountry(Country $country, Company $company): JsonResponse
    {
        if ($company->brands()->where('country_id', '!=', $country->id)->exists()) {
            return $this->conflict('Move or archive the company brands before changing its country.');
        }
        $company->update(['country_id' => $country->id, 'country' => $country->name]);

        return response()->json(['message' => 'Company country assigned.', 'data' => $company->fresh('country')]);
    }

    public function brands(Request $request, Company $company): JsonResponse
    {
        $brands = $company->brands()
            ->with('country')
            ->withCount('shops')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json(['data' => $brands]);
    }

    public function shops(Request $request, ComplexLocation $complex): JsonResponse
    {
        $shops = $complex->shops()
            ->with(['brand', 'country', 'branch'])
            ->withCount('tills')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json(['data' => $shops]);
    }

    public function tills(Request $request, Shop $shop): JsonResponse
    {
        $tills = $shop->tills()->with('branch')->orderBy('till_name')->paginate($this->perPage($request));

        return response()->json(['data' => $tills]);
    }

    public function menuItems(Request $request, Shop $shop): JsonResponse
    {
        $channel = $request->filled('channel') ? trim((string) $request->input('channel')) : null;
        $query = $this->hierarchy->visibleMenuQuery($shop, $channel);
        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $query->where(fn ($scope) => $scope
                ->where('products.name', 'like', "%{$term}%")
                ->orWhere('brand_catalogue.external_code', 'like', "%{$term}%"));
        }

        return response()->json([
            'data' => $query->paginate($this->perPage($request)),
            'configuration_version' => (int) $shop->configuration_version,
        ]);
    }

    public function setMenuItemAvailability(Request $request, Shop $shop, string $product): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['required', 'boolean'],
            'channel_code' => ['nullable', 'string', 'max:80'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $catalogueItem = DB::table('brand_products')
            ->where('company_id', $shop->company_id)
            ->where('brand_id', $shop->brand_id)
            ->where('product_id', $product)
            ->where('item_kind', 'menu_item')
            ->where('is_active', true)
            ->exists();
        if (! $catalogueItem) {
            return response()->json(['message' => 'Menu item does not belong to this shop brand.'], 404);
        }

        $override = DB::transaction(function () use ($data, $product, $shop): ShopMenuItemOverride {
            $channel = isset($data['channel_code']) && trim($data['channel_code']) !== ''
                ? strtoupper(trim($data['channel_code']))
                : null;
            $query = ShopMenuItemOverride::query()
                ->where('shop_id', $shop->id)
                ->where('product_id', $product);
            $channel === null ? $query->whereNull('channel_code') : $query->where('channel_code', $channel);
            $override = $query->first() ?: new ShopMenuItemOverride([
                'company_id' => $shop->company_id,
                'brand_id' => $shop->brand_id,
                'shop_id' => $shop->id,
                'product_id' => $product,
                'channel_code' => $channel,
                'channel_key' => $channel ?: '*',
            ]);
            $version = ((int) $shop->configuration_version) + 1;
            $override->fill([
                ...$data,
                'channel_code' => $channel,
                'channel_key' => $channel ?: '*',
                'configuration_version' => $version,
            ])->save();
            $shop->forceFill(['configuration_version' => $version])->save();
            PosTill::query()->where('shop_id', $shop->id)->update([
                'configuration_version' => $version,
                'updated_at' => now(),
            ]);

            return $override;
        }, 3);
        $this->sync->markBranchDirty((string) $shop->company_id, (string) $shop->branch_id, 'shop_menu_item_overrides');

        return response()->json(['message' => 'Shop menu visibility updated.', 'data' => $override]);
    }

    private function countryRules(?Country $country = null): array
    {
        return [
            'code' => [$country ? 'sometimes' : 'required', 'string', 'max:16', Rule::unique('countries', 'code')->withoutTrashed()->ignore($country?->id)],
            'iso_alpha_2' => ['nullable', 'string', 'size:2', Rule::unique('countries', 'iso_alpha_2')->withoutTrashed()->ignore($country?->id)],
            'iso_alpha_3' => ['nullable', 'string', 'size:3', Rule::unique('countries', 'iso_alpha_3')->withoutTrashed()->ignore($country?->id)],
            'name' => [$country ? 'sometimes' : 'required', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:20'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function normaliseCountry(array $data): array
    {
        foreach (['code', 'iso_alpha_2', 'iso_alpha_3', 'currency_code'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strtoupper(trim($data[$field]));
            }
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 50), 1), 100);
    }

    private function legacyCanReadHierarchy(PosUser $user): bool
    {
        $user->loadMissing('role.permissions');
        $normalise = static fn ($value): string => strtolower(str_replace(
            [' ', '-'],
            '_',
            trim((string) $value)
        ));
        if (in_array($normalise($user->user_type), ['super_admin', 'super_administrator'], true)
            || in_array($normalise($user->role?->name), ['super_admin', 'super_administrator'], true)) {
            return true;
        }

        return $user->role?->permissions
            ?->pluck('name')
            ->intersect([
                'manage_branch_settings',
                'till_management',
                'manage_access_assignments',
                'manage_authentication_policies',
            ])
            ->isNotEmpty() ?? false;
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'ENTERPRISE_HIERARCHY_CONFLICT'], 409);
    }
}
