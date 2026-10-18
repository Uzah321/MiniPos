<?php

use App\Http\Middleware\BlockHospitalityInventoryWhenDayEndRunning;
use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\FoodManufacturing\Http\Controllers\FoodManufacturingController;
use Modules\FoodManufacturing\Http\Controllers\FoodRecipeController;

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'business.mode:hospitality',
])->prefix('pos')->group(function () {
    Route::prefix('food-manufacturing')->group(function () {
        Route::get('recipes/cost-report/{format}', [FoodRecipeController::class, 'costReportDownload'])
            ->whereIn('format', ['pdf', 'xlsx']);
        Route::get('recipes', [FoodRecipeController::class, 'index']);
        Route::post('recipes', [FoodRecipeController::class, 'store']);
        Route::get('recipes/{id}', [FoodRecipeController::class, 'show']);
        Route::put('recipes/{id}', [FoodRecipeController::class, 'update']);
        Route::delete('recipes/{id}', [FoodRecipeController::class, 'destroy']);
        Route::get('recipes/{id}/cost', [FoodRecipeController::class, 'cost']);
        Route::post('menu-items/{menuProductId}/link-recipe', [FoodRecipeController::class, 'linkMenuRecipe']);

        Route::get('batches', [FoodManufacturingController::class, 'index']);
        Route::post('batches', [FoodManufacturingController::class, 'store'])
            ->middleware(BlockHospitalityInventoryWhenDayEndRunning::class);
        Route::get('batches/{id}', [FoodManufacturingController::class, 'show']);

        Route::get('reports/production', [FoodManufacturingController::class, 'report']);
        Route::get('reports/production/excel', [FoodManufacturingController::class, 'downloadExcel']);
        Route::get('reports/production/pdf', [FoodManufacturingController::class, 'downloadPdf']);

        Route::get('planning/materials', [FoodManufacturingController::class, 'materialPlan']);
        Route::post('planning/materials', [FoodManufacturingController::class, 'saveMaterialPlan']);
        Route::get('planning/materials/excel', [FoodManufacturingController::class, 'materialPlanExcel']);
        Route::get('planning/materials/pdf', [FoodManufacturingController::class, 'materialPlanPdf']);

        Route::get('planning/plans', [FoodManufacturingController::class, 'plans']);
        Route::post('planning/plans', [FoodManufacturingController::class, 'saveMaterialPlan']);

        Route::get('planning/plans/excel', [FoodManufacturingController::class, 'plansExcel']);
        Route::get('planning/plans/pdf', [FoodManufacturingController::class, 'plansPdf']);

        Route::get('planning/plans/{id}', [FoodManufacturingController::class, 'showPlan']);
        Route::delete('planning/plans/{id}', [FoodManufacturingController::class, 'deletePlan']);
    });
});
