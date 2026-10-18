<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\ZimraIntegration\Http\Controllers\ZimraIntegrationController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office'])->prefix('v1')->group(function () {
    Route::apiResource('zimraintegrations', ZimraIntegrationController::class)->names('zimraintegration');
});
