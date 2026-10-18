<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\ManufacturingController;
use Modules\Manufacturing\Http\Controllers\ManufacturingEnterpriseReportController;
use Modules\Manufacturing\Http\Controllers\ManufacturingExportController;
use Modules\Manufacturing\Http\Controllers\ManufacturingReportController;
use Modules\Manufacturing\Http\Controllers\ProductionOrderController;
use Modules\Manufacturing\Http\Controllers\ProductionPlanController;
use Modules\Manufacturing\Http\Controllers\ProductionRecipeController;

// Legacy Retail manufacturing is intentionally not exposed by ServeNest.
// Hospitality production/recipes are provided by FoodManufacturing instead.
if ((bool) config('servnest.hospitality_only', true)) {
    return;
}

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'business.mode:retail',
])
    ->prefix('v1')
    ->group(function () {
        Route::apiResource('manufacturings', ManufacturingController::class)
            ->names('manufacturing');
    });

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'business.mode:retail',
])
    ->prefix('pos/manufacturing')
    ->group(function () {
        Route::get('recipes', [ProductionRecipeController::class, 'index']);
        Route::post('recipes', [ProductionRecipeController::class, 'store']);
        Route::get('recipes/{id}', [ProductionRecipeController::class, 'show']);
        Route::put('recipes/{id}', [ProductionRecipeController::class, 'update']);
        Route::delete('recipes/{id}', [ProductionRecipeController::class, 'destroy']);

        Route::get('plans', [ProductionPlanController::class, 'index']);
        Route::post('plans', [ProductionPlanController::class, 'store']);
        Route::get('plans/{id}', [ProductionPlanController::class, 'show']);
        Route::post('plans/{id}/approve', [ProductionPlanController::class, 'approve']);
        Route::post('plans/{id}/calculate-materials', [ProductionPlanController::class, 'calculateMaterials']);
        Route::post('plans/{id}/convert-to-orders', [ProductionPlanController::class, 'convertToOrders']);
        Route::post('plans/{id}/cancel', [ProductionPlanController::class, 'cancel']);

        Route::get('orders', [ProductionOrderController::class, 'index']);
        Route::post('orders', [ProductionOrderController::class, 'store']);
        Route::get('orders/{id}', [ProductionOrderController::class, 'show']);
        Route::post('orders/{id}/approve', [ProductionOrderController::class, 'approve']);
        Route::post('orders/{id}/reserve-materials', [ProductionOrderController::class, 'reserveMaterials']);
        Route::post('orders/{id}/issue-materials', [ProductionOrderController::class, 'issueMaterials']);
        Route::post('orders/{id}/start', [ProductionOrderController::class, 'start']);
        Route::post('orders/{id}/add-overhead', [ProductionOrderController::class, 'addOverhead']);
        Route::post('orders/{id}/return-material', [ProductionOrderController::class, 'returnMaterial']);
        Route::post('orders/{id}/record-wastage', [ProductionOrderController::class, 'recordWastage']);
        Route::post('orders/{id}/complete', [ProductionOrderController::class, 'complete']);
        Route::post('orders/{id}/cancel', [ProductionOrderController::class, 'cancel']);

        Route::get('reports', [ManufacturingReportController::class, 'index']);
        Route::get('reports/{report}/export/{format}', [ManufacturingExportController::class, 'download'])
            ->where('report', '[a-z-]+')
            ->whereIn('format', ['pdf', 'xlsx']);
        Route::get('reports/dashboard', [ManufacturingReportController::class, 'dashboard']);
        Route::get('reports/production-summary', [ManufacturingReportController::class, 'productionSummary']);
        Route::get('reports/production-costs', [ManufacturingReportController::class, 'productionCosts']);
        Route::get('reports/material-usage', [ManufacturingReportController::class, 'materialUsage']);
        Route::get('reports/finished-goods-received', [ManufacturingReportController::class, 'finishedGoodsReceived']);
        Route::get('reports/work-in-progress', [ManufacturingReportController::class, 'workInProgress']);
        Route::get('reports/wastage', [ManufacturingReportController::class, 'wastage']);
        Route::get('reports/variance', [ManufacturingReportController::class, 'variance']);
        Route::get('reports/material-requirements', [ManufacturingReportController::class, 'materialRequirements']);
        Route::get('reports/material-shortage-alerts', [ManufacturingReportController::class, 'materialShortageAlerts']);
        Route::get('reports/accounting-ledger', [ManufacturingEnterpriseReportController::class, 'accountingLedger']);
        Route::get('reports/plans/{planId}/history', [ManufacturingReportController::class, 'productionPlanHistory']);

        Route::get('reports/plans/consolidated-history', [ManufacturingEnterpriseReportController::class, 'consolidatedPlanHistory']);
        Route::get('reports/plans/comparison', [ManufacturingEnterpriseReportController::class, 'productionPlanComparison']);
        Route::get('reports/orders/{orderId}/activity', [ManufacturingEnterpriseReportController::class, 'productionOrderActivity']);

        Route::get('reports/enterprise-dashboard', [ManufacturingEnterpriseReportController::class, 'enterpriseDashboard']);
        Route::get('reports/consolidated-production', [ManufacturingEnterpriseReportController::class, 'consolidatedProduction']);
        Route::get('reports/direct-indirect-costs', [ManufacturingEnterpriseReportController::class, 'directIndirectCosts']);
        Route::get('reports/staff-costs', [ManufacturingEnterpriseReportController::class, 'staffCosts']);
        Route::get('reports/production-activity', [ManufacturingEnterpriseReportController::class, 'productionActivity']);
        Route::get('reports/orders/{orderId}/activity', [ManufacturingEnterpriseReportController::class, 'productionOrderActivity']);
        Route::get('reports/production-plan-comparison', [ManufacturingEnterpriseReportController::class, 'productionPlanComparison']);
        Route::get('reports/consolidated-plan-history', [ManufacturingEnterpriseReportController::class, 'consolidatedPlanHistory']);
        Route::get('reports/salary-allocation', [ManufacturingEnterpriseReportController::class, 'salaryAllocation']);

        // Safe aliases for older frontend bundles.
        Route::get('reports/bom-costs', [ManufacturingReportController::class, 'productionCosts']);
        Route::get('reports/production-cost-summary', [ManufacturingReportController::class, 'productionCosts']);
    });
