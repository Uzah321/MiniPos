<?php

use App\Http\Controllers\EdgeSyncController;
use App\Http\Controllers\SyncNodeManagementController;
use App\Http\Controllers\SyncPairController;
use App\Http\Controllers\SyncProtocolController;
use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Services\Security\ClockGuardService;
use Illuminate\Support\Facades\Route;
use Modules\Company\Http\Controllers\CompanyController;
use Modules\Company\Http\Controllers\EnterpriseHierarchyController;
use Modules\Company\Http\Controllers\EnterpriseResourceController;
use Modules\Company\Http\Controllers\StandaloneProvisioningController;

/*
|--------------------------------------------------------------------------
| DISTRIBUTED NODE SYNC
|--------------------------------------------------------------------------
| Pairing is public but rate limited and can only consume a code created by
| an authenticated company administrator. Push/pull use node HMAC auth and
| intentionally do not depend on an interactive user session or license
| middleware, allowing an edge to receive a renewed license after expiry.
*/
Route::post('/pos/sync/pair', SyncPairController::class)->middleware('throttle:10,1,sync-pair:');
Route::post('/pos/sync/terminal-activations/redeem', [EdgeSyncController::class, 'redeemTerminalActivation'])
    ->middleware('throttle:10,1,sync-terminal-redeem:');
Route::get('/pos/sync/status', [EdgeSyncController::class, 'status'])
    ->middleware('throttle:60,1,sync-status:');
// Multiple LAN tills can share one edge API. The orchestrator's node lock is
// the concurrency authority; this wider abuse ceiling must not reject normal
// focus/online/manual triggers from those terminals.
Route::post('/pos/sync/run', [EdgeSyncController::class, 'run'])
    ->middleware('throttle:120,1,sync-run:');

Route::middleware('sync.node')->prefix('pos/sync')->group(function () {
    Route::post('/push', [SyncProtocolController::class, 'push']);
    Route::get('/pull', [SyncProtocolController::class, 'pull']);
    Route::get('/license', [SyncProtocolController::class, 'mobileLicense']);
    Route::get('/node-status', [SyncProtocolController::class, 'status']);
    // Edge operators may initiate Day End here, but Central remains the only
    // calendar authority. The edge drains its outbox before this signed,
    // idempotent command and pulls the completed branch snapshot afterward.
    Route::post('/day-end', [SyncProtocolController::class, 'dayEnd']);
    Route::post('/heartbeat', [SyncProtocolController::class, 'heartbeat'])
        ->middleware('throttle:120,1,sync-heartbeat:');
    Route::post('/terminal-activations/consume', [SyncProtocolController::class, 'consumeTerminalActivation']);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'company.permission:till_management,manage_license',
])->prefix('pos/sync')->group(function () {
    Route::get('/nodes', [SyncNodeManagementController::class, 'index']);
    Route::post('/pairing-codes', [SyncNodeManagementController::class, 'createPairingCode']);
    Route::post('/nodes/{node}/terminal-activation-codes', [SyncNodeManagementController::class, 'createTerminalActivationCode']);
    Route::post('/nodes/{node}/suspend', [SyncNodeManagementController::class, 'suspend']);
    Route::post('/nodes/{node}/resume', [SyncNodeManagementController::class, 'resume']);
    Route::post('/nodes/{node}/rotate', [SyncNodeManagementController::class, 'rotate']);
    Route::delete('/nodes/{node}', [SyncNodeManagementController::class, 'revoke']);

});

/*
|--------------------------------------------------------------------------
| PUBLIC / AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // example
    // Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| ADMIN / BACKOFFICE ROUTES (NO DOMAIN RESOLUTION REQUIRED)
|--------------------------------------------------------------------------
| Used by your admin panel or super admin
*/
Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::prefix('enterprise')->group(function () {
        Route::get('/hierarchy', [EnterpriseHierarchyController::class, 'tree']);

        // Canonical independently managed enterprise resources. Filters express
        // relationships without turning the UI into one coupled hierarchy form.
        Route::get('/countries', [EnterpriseResourceController::class, 'countries']);
        Route::post('/countries', [EnterpriseResourceController::class, 'storeCountry']);
        Route::get('/countries/{country}', [EnterpriseResourceController::class, 'showCountry']);
        Route::match(['put', 'patch'], '/countries/{country}', [EnterpriseResourceController::class, 'updateCountry']);
        Route::delete('/countries/{country}', [EnterpriseResourceController::class, 'destroyCountry']);

        Route::get('/companies', [EnterpriseResourceController::class, 'companies']);
        Route::post('/companies', [EnterpriseResourceController::class, 'storeCompany']);
        Route::get('/companies/{company}', [EnterpriseResourceController::class, 'showCompany']);
        Route::match(['put', 'patch'], '/companies/{company}', [EnterpriseResourceController::class, 'updateCompany']);
        Route::delete('/companies/{company}', [EnterpriseResourceController::class, 'destroyCompany']);

        Route::get('/brands', [EnterpriseResourceController::class, 'brands']);
        Route::post('/brands', [EnterpriseResourceController::class, 'storeBrand']);
        Route::get('/brands/{brand}', [EnterpriseResourceController::class, 'showBrand']);
        Route::match(['put', 'patch'], '/brands/{brand}', [EnterpriseResourceController::class, 'updateBrand']);
        Route::delete('/brands/{brand}', [EnterpriseResourceController::class, 'destroyBrand']);

        Route::get('/complexes', [EnterpriseResourceController::class, 'complexes']);
        Route::post('/complexes', [EnterpriseResourceController::class, 'storeComplex']);
        Route::get('/complexes/{complex}', [EnterpriseResourceController::class, 'showComplex']);
        Route::match(['put', 'patch'], '/complexes/{complex}', [EnterpriseResourceController::class, 'updateComplex']);
        Route::delete('/complexes/{complex}', [EnterpriseResourceController::class, 'destroyComplex']);

        Route::get('/shops', [EnterpriseResourceController::class, 'shops']);
        Route::post('/shops', [EnterpriseResourceController::class, 'storeShop']);
        Route::get('/shops/{shop}', [EnterpriseResourceController::class, 'showShop']);
        Route::match(['put', 'patch'], '/shops/{shop}', [EnterpriseResourceController::class, 'updateShop']);
        Route::delete('/shops/{shop}', [EnterpriseResourceController::class, 'destroyShop']);

        Route::get('/tills', [EnterpriseResourceController::class, 'tills']);
        Route::post('/tills', [EnterpriseResourceController::class, 'storeTill']);
        Route::get('/tills/{till}', [EnterpriseResourceController::class, 'showTill']);
        Route::match(['put', 'patch'], '/tills/{till}', [EnterpriseResourceController::class, 'updateTill']);
        Route::delete('/tills/{till}', [EnterpriseResourceController::class, 'destroyTill']);

        // Compatibility aliases for clients that still traverse parent routes.
        Route::get('/countries/{country}/companies', [EnterpriseHierarchyController::class, 'companies']);
        Route::put('/countries/{country}/companies/{company}', [EnterpriseHierarchyController::class, 'assignCompanyCountry']);

        Route::get('/companies/{company}/brands', [EnterpriseHierarchyController::class, 'brands']);
        Route::post('/companies/{company}/brands', [EnterpriseResourceController::class, 'storeBrandForCompany']);

        Route::get('/complexes/{complex}/shops', [EnterpriseHierarchyController::class, 'shops']);
        Route::post('/complexes/{complex}/shops', [EnterpriseResourceController::class, 'storeShopForComplex']);
        Route::get('/shops/{shop}/tills', [EnterpriseHierarchyController::class, 'tills']);
        Route::post('/shops/{shop}/tills', [EnterpriseResourceController::class, 'storeTillForShop']);
        Route::match(['put', 'patch'], '/shops/{shop}/tills/{till}', [EnterpriseResourceController::class, 'updateTillForShop']);
        Route::delete('/shops/{shop}/tills/{till}', [EnterpriseResourceController::class, 'destroyTillForShop']);
        Route::get('/shops/{shop}/menu-items', [EnterpriseHierarchyController::class, 'menuItems']);
        Route::put('/shops/{shop}/menu-items/{product}', [EnterpriseHierarchyController::class, 'setMenuItemAvailability']);
    });

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/companies/{company}', [CompanyController::class, 'update']);
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);

    Route::get('/companies/{company}/branches', [CompanyController::class, 'getCompanyBranches']);
    Route::post('/companies/{company}/branches', [CompanyController::class, 'storePlatformBranch']);
    Route::match(['put', 'patch'], '/companies/{company}/branches/{branch}', [CompanyController::class, 'updatePlatformBranch']);
    Route::delete('/companies/{company}/branches/{branch}', [CompanyController::class, 'destroyPlatformBranch']);
    Route::post('/companies/{company}/branches/{branch}/contacts', [CompanyController::class, 'storePlatformBranchContact']);
    Route::match(['put', 'patch'], '/companies/{company}/branches/{branch}/contacts/{contact}', [CompanyController::class, 'updatePlatformBranchContact']);
    Route::delete('/companies/{company}/branches/{branch}/contacts/{contact}', [CompanyController::class, 'destroyPlatformBranchContact']);
    Route::match(['put', 'patch'], '/company-branches/{branch}', [CompanyController::class, 'updateBranch']);
    Route::delete('/company-branches/{branch}', [CompanyController::class, 'destroyBranch']);
    Route::post('/company-branches/{branch}/contacts', [CompanyController::class, 'storeBranchContact']);
    Route::match(['put', 'patch'], '/company-branch-contacts/{contact}', [CompanyController::class, 'updateBranchContact']);
    Route::delete('/company-branch-contacts/{contact}', [CompanyController::class, 'destroyBranchContact']);

    Route::get('/licenses', [CompanyController::class, 'getLicenses']);
    Route::get('/companies/{company}/licenses', [CompanyController::class, 'getCompanyLicenses']);
    Route::get('/companies/{company}/license-status', [CompanyController::class, 'platformLicenseStatus']);
    Route::post('/companies/{company}/licenses/assign', [CompanyController::class, 'assignLicense']);
    Route::post('/companies/{company}/licenses/renew', [CompanyController::class, 'renewLicense']);
    Route::match(['put', 'patch'], '/companies/{company}/licenses/{license}', [CompanyController::class, 'updatePlatformLicense']);
    Route::post('/companies/{company}/licenses/{license}/revoke', [CompanyController::class, 'revokePlatformLicense']);
    Route::post('/companies/{company}/licenses/generate-offline', [CompanyController::class, 'generateOfflineLicense']);
    Route::post('/companies/{company}/standalone-provisioning-package', [StandaloneProvisioningController::class, 'store'])
        ->middleware('throttle:10,1,standalone-provisioning:');

    Route::get('/companies/{company}/sync/nodes', [SyncNodeManagementController::class, 'platformIndex']);
    Route::post('/companies/{company}/sync/pairing-codes', [SyncNodeManagementController::class, 'platformCreatePairingCode']);
    Route::post('/companies/{company}/sync/nodes/{node}/terminal-activation-codes', [SyncNodeManagementController::class, 'platformCreateTerminalActivationCode']);
    Route::post('/companies/{company}/sync/nodes/{node}/suspend', [SyncNodeManagementController::class, 'platformSuspend']);
    Route::post('/companies/{company}/sync/nodes/{node}/resume', [SyncNodeManagementController::class, 'platformResume']);
    Route::post('/companies/{company}/sync/nodes/{node}/rotate', [SyncNodeManagementController::class, 'platformRotate']);
    Route::delete('/companies/{company}/sync/nodes/{node}', [SyncNodeManagementController::class, 'platformRevoke']);
});

// OFFLINE RENEW (NO COMPANY PARAM — TOKEN HAS COMPANY)

Route::middleware(['auth:sanctum'])->group(function () {

    // =============================
    // COMPANIES CRUD
    // =============================
    //    Route::get('/companies', [CompanyController::class, 'index']);
    //    Route::post('/companies', [CompanyController::class, 'store']);
    //    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    //    Route::put('/companies/{company}', [CompanyController::class, 'update']);
    //    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);

    // =============================
    // LICENSE MANAGEMENT
    // =============================

});

/*
|--------------------------------------------------------------------------
| POS / TENANT ROUTES (REQUIRES DOMAIN + LICENSE)
|--------------------------------------------------------------------------
| These are used by the POS frontend (React app)
| MUST resolve company + check license
*/

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {

    // Tenant managers receive only the resolved company's hierarchy and only
    // the brand/complex/shop/till descendants granted by their active scope.
    Route::get('/enterprise/hierarchy', [EnterpriseHierarchyController::class, 'tenantTree'])
        ->middleware('workspace:back-office');

    // =============================
    // BRANCHES (TENANT SIDE)
    // =============================
    Route::get('/branches', [CompanyController::class, 'getResolvedCompanyBranches']);
    Route::post('/branches', [CompanyController::class, 'storeBranchForResolvedCompany'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);
    // =============================
    // BRANCHES (ADMIN SIDE)
    // =============================
    Route::post('/companies/{company}/branches', [CompanyController::class, 'storeBranch'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);
    Route::put('/branches/{branch}', [CompanyController::class, 'updateBranch'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);
    Route::delete('/branches/{branch}', [CompanyController::class, 'destroyBranch'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);

    // =============================
    // BRANCH CONTACTS
    // =============================
    Route::post('/branches/{branch}/contacts', [CompanyController::class, 'storeBranchContact'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);
    Route::put('/branch-contacts/{contact}', [CompanyController::class, 'updateBranchContact'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);
    Route::delete('/branch-contacts/{contact}', [CompanyController::class, 'destroyBranchContact'])
        ->middleware(['workspace:back-office', 'company.permission:manage_branch_settings']);

});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'company.permission:manage_license',
])->prefix('pos')->group(function () {
    Route::get('/companies/{company}/licenses', [CompanyController::class, 'getCompanyLicenses']);
});
Route::middleware([
    ResolveCompanyFromDomain::class,
])->prefix('pos')->group(function () {
    // Bootstrap and activation must remain reachable after expiry so a Super
    // Admin can sign in and a locally issued short key can unlock the tenant.
    Route::get('/company', [CompanyController::class, 'currentCompany']);
    Route::post('/licenses/renew-offline', [CompanyController::class, 'renewLicenseOffline']);
    Route::get('/system/clock-check', function (ClockGuardService $clockGuardService) {
        $company = app('company');

        if (! $company) {
            return response()->json([
                'message' => 'Company context not found.',
            ], 404);
        }

        $result = $clockGuardService->check($company->id);

        return response()->json([
            'message' => $result['message'],
            'data' => [
                ...$result,
                'server_timestamp_ms' => now()->getTimestampMs(),
                'timezone' => config('app.timezone'),
            ],
        ], $result['ok'] ? 200 : 403);
    });

});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {
    Route::get('/license/status', [CompanyController::class, 'runtimeLicenseStatus']);
});
