<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanySettingService;
use App\Services\Enterprise\EnterpriseHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Services\AuthenticationPolicyService;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\Printer;
use Modules\GeneralSettings\Models\QzCertificate;

class WorkspaceBootstrapController extends Controller
{
    public function __construct(
        private readonly CompanySettingService $settings,
        private readonly BusinessModeBranchScopeService $branchScope,
        private readonly EnterpriseHierarchyService $hierarchy
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = app('company');
        $user = $request->user()->loadMissing(['role.permissions', 'branch', 'branches']);
        $scope = $this->branchScope->resolve($request, (string) $company->id);
        $branchId = $scope['context_branch_id'];
        $branch = $company->branches()->whereKey($branchId)->first();
        $shopsByBranch = Schema::hasTable('shops')
            ? Shop::query()
                ->with(['country', 'brand', 'complex'])
                ->where('company_id', $company->id)
                ->whereIn('branch_id', $scope['branch_ids'])
                ->get()
                ->keyBy(fn (Shop $shop): string => (string) $shop->branch_id)
            : collect();
        /** @var Shop|null $shop */
        $shop = $branchId ? $shopsByBranch->get((string) $branchId) : null;
        /** @var PosTill|null $resolvedTill */
        $resolvedTill = app()->bound('till') ? app('till') : null;
        if ($resolvedTill && (string) $resolvedTill->branch_id !== (string) $branchId) {
            abort(403, 'The requested branch is not assigned to this till.');
        }
        if ($resolvedTill && $shop) {
            $resolvedTill->setRelation('shop', $shop);
            $authenticationScope = app(EnterpriseScopeResolver::class)->fromTill($resolvedTill);
        } elseif ($shop) {
            $authenticationScope = app(EnterpriseScopeResolver::class)->fromShop($shop);
        } else {
            $authenticationScope = new AuthorizationScope(
                companyId: (string) $company->id,
                shopId: $branchId ? (string) $branchId : null,
                branchId: $branchId ? (string) $branchId : null,
            );
        }
        $authenticationPolicies = app(AuthenticationPolicyService::class);
        $allowedAuthenticationMethods = $authenticationPolicies->allowedMethods(
            'pos',
            $authenticationScope,
            $shop?->pos_login_mode ?: 'code'
        );
        $effectiveAuthenticationPolicy = $authenticationPolicies->effectivePolicy(
            $authenticationScope,
            $shop?->pos_login_mode ?: 'code'
        );
        $effectivePosLoginMode = match ($allowedAuthenticationMethods) {
            [AuthenticationPolicyService::METHOD_LOGIN_CODE] => 'code',
            [AuthenticationPolicyService::METHOD_USERNAME_PASSWORD] => 'username_password',
            default => 'both',
        };
        $loginCodeRequirements = app(CredentialPolicyService::class)
            ->loginCodeRequirements($authenticationScope);
        $authorization = app(EnterpriseAuthorizationService::class);
        $effectivePermissions = $authorization->effectivePermissionNames($user, $authenticationScope);
        $effectiveRoleNames = $authorization->effectiveRoleNames($user, $authenticationScope);
        $effectiveSuperAdmin = $authorization->isEffectiveSuperAdmin($user, $authenticationScope);
        $enterpriseHierarchy = $shop
            ? $this->hierarchy->hierarchyForShop($shop, $resolvedTill)
            : null;
        $availableBranches = $company->branches()
            ->whereIn('id', $scope['branch_ids'])
            ->orderByDesc('is_main_branch')
            ->orderBy('branch_name')
            ->get()
            ->map(function ($availableBranch) use ($scope, $shopsByBranch): array {
                /** @var Shop|null $availableShop */
                $availableShop = $shopsByBranch->get((string) $availableBranch->id);

                return [
                    'id' => $availableBranch->id,
                    'name' => $availableBranch->branch_name,
                    'code' => $availableBranch->branch_code,
                    'shop_id' => $availableShop?->id,
                    'brand_id' => $availableShop?->brand_id,
                    'complex_id' => $availableShop?->complex_id,
                    'is_main_branch' => (bool) $availableBranch->is_main_branch,
                    'status' => $availableBranch->status,
                    'business_mode' => $scope['business_mode'],
                ];
            })
            ->values();
        $salesScreenLayout = strtolower(trim((string) $this->settings->get(
            $company->id,
            'sales_screen_layout',
            'standard',
            $branchId
        )));
        if (! in_array($salesScreenLayout, ['standard', 'visual'], true)) {
            $salesScreenLayout = 'standard';
        }

        $tills = PosTill::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $printers = Printer::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where(function ($scope) use ($branchId) {
                $scope->where('branch_id', $branchId)->orWhereNull('branch_id');
            }));
        $certificates = QzCertificate::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($query) => $query->where(function ($scope) use ($branchId) {
                $scope->where('branch_id', $branchId)->orWhereNull('branch_id');
            }));

        $deviceSummary = [
            'tills_total' => (clone $tills)->count(),
            'tills_active' => (clone $tills)->where('is_active', true)->count(),
            'tills_registered' => (clone $tills)->where('is_registered', true)->count(),
            'printers_total' => (clone $printers)->count(),
            'printers_active' => (clone $printers)->where('is_active', true)->count(),
            'printer_routes' => (clone $printers)->where('is_active', true)
                ->distinct()->orderBy('target_printing')->pluck('target_printing')->values(),
            'qz_certificates_active' => (clone $certificates)->where('is_active', true)->count(),
        ];

        $deviceSummary['ready'] = $deviceSummary['tills_active'] > 0
            && $deviceSummary['tills_registered'] > 0
            && $deviceSummary['printers_active'] > 0;

        return response()->json([
            'message' => 'Workspace configuration loaded successfully.',
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->company_name,
                    'logo' => $company->logo,
                    'status' => $company->status,
                ],
                'hierarchy' => $enterpriseHierarchy,
                'identity' => $enterpriseHierarchy,
                'branding' => [
                    'name' => data_get($enterpriseHierarchy, 'brand.name', $company->company_name),
                    'logo' => data_get($enterpriseHierarchy, 'brand.logo', $company->logo),
                    'logo_url' => data_get($enterpriseHierarchy, 'brand.logo_url'),
                    'logo_path' => data_get($enterpriseHierarchy, 'brand.logo_path'),
                    'company_logo' => $company->logo,
                ],
                'deployment_mode' => (string) config('servnest.deployment_mode'),
                'configuration' => [
                    'configuration_version' => (int) ($shop?->configuration_version ?: 1),
                    'configuration_etag' => hash('sha256', implode('|', [
                        (string) config('servnest.configuration_schema_version', 2),
                        (string) ($shop?->id ?: $branchId),
                        (string) ($shop?->configuration_version ?: 1),
                        (string) ($shop?->updated_at?->getTimestamp() ?: 0),
                    ])),
                    'schema_version' => (int) config('servnest.configuration_schema_version', 2),
                ],
                'authentication' => [
                    'back_office_login_mode' => 'username_password',
                    'pos_login_mode' => $effectivePosLoginMode,
                    'allowed_pos_login_modes' => ['code', 'username_password', 'both'],
                    'allowed_authentication_methods' => $allowedAuthenticationMethods,
                    'credential_policy' => ['login_code' => $loginCodeRequirements],
                    'policy_fingerprint' => $effectiveAuthenticationPolicy->fingerprint,
                ],
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->branch_name,
                    'code' => $branch->branch_code,
                    'is_main_branch' => (bool) $branch->is_main_branch,
                ] : null,
                'available_branches' => $availableBranches,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $effectiveSuperAdmin
                        ? 'Super Admin'
                        : (count($effectiveRoleNames) === 1 ? $effectiveRoleNames[0] : 'Scoped Access'),
                    'role_names' => $effectiveRoleNames,
                    'permissions' => $effectivePermissions,
                    'permission_names' => $effectivePermissions,
                    'role_permissions' => $effectivePermissions,
                    'is_company_super_admin' => $effectiveSuperAdmin,
                    'workspace_access' => [
                        'front_office' => (bool) $user->can_access_front_office,
                        'back_office' => (bool) $user->can_access_back_office,
                    ],
                ],
                'operation' => [
                    'business_mode' => 'hospitality',
                    'hospitality_only' => true,
                    'sales_screen_layout' => $salesScreenLayout,
                    'cost_source' => 'recipe_ingredient_consumption',
                    'boh_enabled' => $this->settings->isBohEnabled($company->id, $branchId),
                    'fast_food_mode_enabled' => $this->settings->isFastFoodMode($company->id, $branchId),
                    'receive_stock_to_boh_first' => $this->settings->enabled($company->id, 'receive_stock_to_boh_first', $branchId),
                    'recipe_consumes_front_stock' => $this->settings->enabled($company->id, 'recipe_consumes_front_stock', $branchId),
                ],
                'capabilities' => [
                    'visual_sale' => [
                        'increment_selected_item' => true,
                        'decrement_selected_item' => true,
                        'editable_quantity' => true,
                        'merge_repeated_product_selection' => true,
                    ],
                    'front_office' => ['sales', 'payments', 'receipts', 'cash_management', 'customer_display'],
                    'back_office' => [
                        'sales_control', 'catalog', 'inventory', 'purchasing', 'reports',
                        'people', 'store_configuration', 'devices', 'integrations',
                    ],
                    'mode' => $this->modeCapabilities(),
                ],
                'devices' => $deviceSummary,
            ],
        ]);
    }

    /**
     * Features the client should expose for the active business mode.
     *
     * Category management uses the shared product-category and
     * product-sub-category CRUD endpoints. Advertising it explicitly here
     * keeps the Hospitality catalogue navigation from hiding those screens.
     *
     * @return array<int, string>
     */
    private function modeCapabilities(): array
    {
        return [
            'category_management',
            'menu_items',
            'recipes',
            'modifiers',
            'prep_messages',
            'boh_inventory',
            'production',
            'wastage',
            'kitchen_routing',
        ];
    }
}
