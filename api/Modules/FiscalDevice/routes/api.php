<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\FiscalDevice\Http\Controllers\FiscalDeviceController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office'])->prefix('v1')->group(function () {
    Route::apiResource('fiscaldevices', FiscalDeviceController::class)->names('fiscaldevice');
});
