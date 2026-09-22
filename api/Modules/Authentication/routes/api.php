<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\AuthController;
use Modules\Authentication\Http\Controllers\AdminUserController;
use Modules\Authentication\Http\Controllers\AssignmentController;
use Modules\Authentication\Http\Controllers\BackOfficeAuthController;
use Modules\Authentication\Http\Controllers\CredentialController;
use Modules\Authentication\Http\Controllers\MfaController;
use Modules\Authentication\Http\Controllers\OrganisationController;
use Modules\Authentication\Http\Controllers\PasswordPolicyController;
use Modules\Authentication\Http\Controllers\PosAuthController;
use Modules\Authentication\Http\Controllers\RolePermissionController;
use Modules\Authentication\Http\Controllers\SessionController;
use Modules\Authentication\Http\Middleware\EnsurePermission;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::post('/auth/back-office/login', [BackOfficeAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/back-office/mfa', [MfaController::class, 'challenge'])->middleware('throttle:login');
    Route::post('/auth/pos/login', [PosAuthController::class, 'login'])->middleware('throttle:login');

    // Backwards-compatible POS login. New clients should use /auth/pos/login.
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [BackOfficeAuthController::class, 'logout']);
        Route::get('/auth/me', [BackOfficeAuthController::class, 'me']);
        Route::put('/auth/password', [CredentialController::class, 'password']);
        Route::put('/auth/pin', [CredentialController::class, 'pin']);
        Route::post('/auth/mfa/setup', [MfaController::class, 'setup']);
        Route::post('/auth/mfa/confirm', [MfaController::class, 'confirm']);
        Route::get('/auth/sessions', [SessionController::class, 'index']);
        Route::delete('/auth/sessions/others', [SessionController::class, 'destroyOthers']);
        Route::delete('/auth/sessions/{session}', [SessionController::class, 'destroy']);

        Route::prefix('foundation')->group(function (): void {
            Route::get('/countries', [OrganisationController::class, 'countries'])
                ->middleware(EnsurePermission::class.':countries.view');
            Route::post('/countries', [OrganisationController::class, 'storeCountry']);
            Route::patch('/countries/{country}', [OrganisationController::class, 'updateCountry']);

            Route::get('/brands', [OrganisationController::class, 'brands'])
                ->middleware(EnsurePermission::class.':brands.view');
            Route::post('/brands', [OrganisationController::class, 'storeBrand']);
            Route::patch('/brands/{brand}', [OrganisationController::class, 'updateBrand']);

            Route::get('/complexes', [OrganisationController::class, 'complexes'])
                ->middleware(EnsurePermission::class.':complexes.view');
            Route::post('/complexes', [OrganisationController::class, 'storeComplex']);
            Route::patch('/complexes/{complex}', [OrganisationController::class, 'updateComplex']);

            Route::get('/stores', [OrganisationController::class, 'stores'])
                ->middleware(EnsurePermission::class.':stores.view');
            Route::post('/stores', [OrganisationController::class, 'storeStore']);
            Route::patch('/stores/{store}', [OrganisationController::class, 'updateStore']);

            Route::get('/terminals', [OrganisationController::class, 'terminals'])
                ->middleware(EnsurePermission::class.':terminals.view');
            Route::post('/terminals', [OrganisationController::class, 'storeTerminal']);
            Route::patch('/terminals/{terminal}', [OrganisationController::class, 'updateTerminal']);

            Route::get('/users', [AdminUserController::class, 'index'])
                ->middleware(EnsurePermission::class.':users.view');
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/assignments', [AssignmentController::class, 'store']);
            Route::delete('/users/{user}/assignments/{assignment}', [AssignmentController::class, 'destroy']);

            Route::get('/roles', [RolePermissionController::class, 'roles'])
                ->middleware(EnsurePermission::class.':roles.view');
            Route::post('/roles', [RolePermissionController::class, 'storeRole']);
            Route::patch('/roles/{role}', [RolePermissionController::class, 'updateRole']);
            Route::get('/permissions', [RolePermissionController::class, 'permissions'])
                ->middleware(EnsurePermission::class.':permissions.view');
            Route::post('/permissions', [RolePermissionController::class, 'storePermission']);

            Route::get('/password-policies', [PasswordPolicyController::class, 'index'])
                ->middleware(EnsurePermission::class.':password-policies.view');
            Route::post('/password-policies', [PasswordPolicyController::class, 'store']);
            Route::patch('/password-policies/{passwordPolicy}', [PasswordPolicyController::class, 'update']);
        });
    });
});
