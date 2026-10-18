<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use App\Services\Auth\ManagerApprovalRateLimiter;
use App\Services\Auth\PosLoginRateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AccessAssignmentController;
use Modules\Authentication\Http\Controllers\AuthPolicyController;
use Modules\Authentication\Http\Controllers\ManagerApprovalController;
use Modules\Authentication\Http\Controllers\PosGeneralSettingController;
use Modules\Authentication\Http\Controllers\PosUserController;

Route::middleware([
    ResolveCompanyFromDomain::class,
])->prefix('pos-auth')->group(function () {
    Route::post('/back-office/login', [PosUserController::class, 'loginBackOffice'])
        ->middleware(ThrottleRequests::using(PosLoginRateLimiter::LOGIN));
    Route::post('/front-office/login', [PosUserController::class, 'loginFrontOffice'])
        ->middleware([
            ResolveTillFromToken::class,
            ThrottleRequests::using(PosLoginRateLimiter::LOGIN),
        ]);
    Route::get('/front-office/login-mode', [PosUserController::class, 'frontOfficeLoginMode'])
        ->middleware([
            ResolveTillFromToken::class,
            ThrottleRequests::using(PosLoginRateLimiter::LOGIN_MODE),
        ]);

    // Compatibility path for deployed clients. PosUserController still
    // enforces the declared workspace, including mandatory till binding for
    // every Front Office request and password-only Back Office login.
    Route::post('/login', [PosUserController::class, 'login'])
        ->middleware(ThrottleRequests::using(PosLoginRateLimiter::LOGIN));
    Route::get('/login-mode', [PosUserController::class, 'loginMode'])
        ->middleware([
            ResolveTillFromToken::class,
            ThrottleRequests::using(PosLoginRateLimiter::LOGIN_MODE),
        ]);
});

Route::post('/pos-auth/back-office/select-company', [PosUserController::class, 'selectBackOfficeCompany'])
    ->middleware([
        'auth:sanctum',
        'workspace:back-office',
        'platform.admin',
        'throttle:20,1',
    ]);

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos-auth')->group(function () {
    Route::get('/me', [PosUserController::class, 'me']);
    Route::post('/logout', [PosUserController::class, 'logout']);
    Route::post('/logout-all', [PosUserController::class, 'logoutAll']);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
])->prefix('auth-admin')->group(function () {
    Route::middleware('company.permission:manage_access_assignments')->group(function () {
        Route::get('/access-assignments', [AccessAssignmentController::class, 'index']);
        Route::post('/access-assignments', [AccessAssignmentController::class, 'store']);
        Route::post('/access-assignments/{assignmentId}/revoke', [AccessAssignmentController::class, 'revoke']);
    });

    Route::middleware('company.permission:manage_authentication_policies')->group(function () {
        Route::get('/policies', [AuthPolicyController::class, 'index']);
        Route::get('/policies/effective', [AuthPolicyController::class, 'effective']);
        Route::post('/policies', [AuthPolicyController::class, 'store']);
        Route::post('/policies/{policyId}/disable', [AuthPolicyController::class, 'disable']);
    });
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->group(function () {
    Route::get('/pos-users', [PosUserController::class, 'index'])
        ->middleware(['workspace:back-office', 'company.permission:manage_user']);
    Route::post('/pos-users', [PosUserController::class, 'store'])
        ->middleware(['workspace:back-office', 'company.permission:create_user']);
    Route::get('/pos-users/{posUserId}', [PosUserController::class, 'show'])
        ->middleware(['workspace:back-office', 'company.permission:manage_user']);
    Route::put('/pos-users/{posUserId}', [PosUserController::class, 'update'])
        ->middleware(['workspace:back-office', 'company.permission:manage_user']);
    Route::delete('/pos-users/{posUserId}', [PosUserController::class, 'destroy'])
        ->middleware(['workspace:back-office', 'company.permission:manage_user']);
    Route::post('/pos-users/close-active-shift', [PosUserController::class, 'closeActiveShift'])
        ->middleware(['workspace:front-office', 'resolve.till']);
    Route::get('/pos/general-settings/barcode-scan', [PosGeneralSettingController::class, 'barcodeScan']);
    Route::get('/pos/general-settings/show', [PosGeneralSettingController::class, 'show']);
    Route::get('/pos/general-settings/touch-keyboard', [PosGeneralSettingController::class, 'touchKeyboard']);

    Route::get('/pos/general-settings/sales-screen-layout', [PosGeneralSettingController::class, 'salesScreenLayout']);
    Route::post('/pos/general-settings/sales-screen-layout', [PosGeneralSettingController::class, 'updateSalesScreenLayout'])
        ->middleware('workspace:back-office');

    Route::post('/pos/manager-approvals/verify', [ManagerApprovalController::class, 'verify'])
        ->middleware(ThrottleRequests::using(ManagerApprovalRateLimiter::VERIFY));
});
