<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\InventoryReportDownloadController;
use Modules\Product\Http\Controllers\StockReportController;
use Modules\RolesAndPermission\Http\Controllers\RolesAndPermissionController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office'])->prefix('v1')->group(function () {
    Route::apiResource('rolesandpermissions', RolesAndPermissionController::class)->names('rolesandpermission');
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
])->prefix('pos')->group(function () {

    Route::get('/roles', [RolesAndPermissionController::class, 'index']);
    Route::post('/roles', [RolesAndPermissionController::class, 'store']);
    Route::get('/roles/{id}', [RolesAndPermissionController::class, 'show']);
    Route::put('/roles/{id}', [RolesAndPermissionController::class, 'update']);
    Route::delete('/roles/{id}', [RolesAndPermissionController::class, 'destroy']);

    Route::get('/permissions', [RolesAndPermissionController::class, 'getPermission']);

    Route::get('/roles/{roleId}/permissions', [RolesAndPermissionController::class, 'rolePermissions']);
    Route::post('/roles/{roleId}/permissions', [RolesAndPermissionController::class, 'assignRolePermission']);
    Route::put('/roles/{roleId}/permissions/sync', [RolesAndPermissionController::class, 'syncRolePermissions']);
    Route::delete('/roles/{roleId}/permissions/{permissionId}', [RolesAndPermissionController::class, 'removeRolePermission']);

    Route::prefix('reports/stock')->group(function () {
        Route::get('/download/{report}/{format}', [InventoryReportDownloadController::class, 'stockReport'])
            ->where('report', 'dashboard|company-balance|branch-balance|current-balance|as-of|movements|received|adjustments|valuation|low-stock|out-of-stock|negative-stock|category-summary|supplier-summary')
            ->where('format', 'xlsx|excel|pdf');

        Route::get('/dashboard', [StockReportController::class, 'dashboard']);

        Route::get('/company-balance', [StockReportController::class, 'companyBalance']);
        Route::get('/branch-balance', [StockReportController::class, 'branchBalance']);
        Route::get('/balance', [StockReportController::class, 'balance']);
        Route::get('/as-of', [StockReportController::class, 'asOfBalance']);

        Route::get('/movements', [StockReportController::class, 'movements']);
        Route::get('/product-ledger/{productId}', [StockReportController::class, 'productLedger']);

        Route::get('/received', [StockReportController::class, 'received']);
        Route::get('/adjustments', [StockReportController::class, 'adjustments']);

        Route::get('/valuation', [StockReportController::class, 'valuation']);
        Route::get('/low-stock', [StockReportController::class, 'lowStock']);
        Route::get('/out-of-stock', [StockReportController::class, 'outOfStock']);
        Route::get('/negative-stock', [StockReportController::class, 'negativeStock']);

        Route::get('/category-summary', [StockReportController::class, 'categorySummary']);
        Route::get('/supplier-summary', [StockReportController::class, 'supplierSummary']);
    });
});
