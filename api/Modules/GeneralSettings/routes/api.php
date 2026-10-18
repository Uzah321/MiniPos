<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use App\Services\CompanyBranchContextService as BranchContext;
use App\Services\Auth\PosLoginRateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Modules\GeneralSettings\Http\Controllers\BranchGeneralSettingController;
use Modules\GeneralSettings\Http\Controllers\CurrentTerminalController;
use Modules\GeneralSettings\Http\Controllers\CurrentTerminalKitchenOutputController;
use Modules\GeneralSettings\Http\Controllers\CurrentTerminalQzCertificateController;
use Modules\GeneralSettings\Http\Controllers\GeneralSettingsController;
use Modules\GeneralSettings\Http\Controllers\PrinterController;
use Modules\GeneralSettings\Http\Controllers\QzCertificateController;
use Modules\GeneralSettings\Http\Controllers\SalesIndustryModeController;
use Modules\GeneralSettings\Http\Controllers\ShiftConfigurationController;
use Modules\GeneralSettings\Http\Controllers\TerminalConfigurationBootstrapController;
use Modules\GeneralSettings\Http\Controllers\TillController;
use Modules\GeneralSettings\Http\Controllers\WorkspaceBootstrapController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office'])->prefix('v1')->group(function () {
    Route::apiResource('generalsettings', GeneralSettingsController::class)->names('generalsettings');
});

Route::middleware([
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {

    Route::get('/settings/industry-mode', [SalesIndustryModeController::class, 'show']);
    Route::get('/configuration/bootstrap', [TerminalConfigurationBootstrapController::class, 'show'])
        ->middleware([
            ResolveTillFromToken::class,
            ThrottleRequests::using(PosLoginRateLimiter::LOGIN_MODE),
        ]);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {

    Route::get('/workspace/bootstrap', [WorkspaceBootstrapController::class, 'show'])
        ->middleware(ResolveTillFromToken::class.':front-office-only');

    Route::middleware('workspace:back-office')->group(function () {
        Route::get('/branches/{branch}/settings', [BranchGeneralSettingController::class, 'index']);
        Route::put('/branches/{branch}/settings/{settingKey}', [BranchGeneralSettingController::class, 'update']);
        Route::delete('/branches/{branch}/settings/{settingKey}', [BranchGeneralSettingController::class, 'reset']);
        Route::put('/branches/{branch}/settings-sync', [BranchGeneralSettingController::class, 'sync']);
    });

});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {
    Route::get('/shift-configurations/current', [ShiftConfigurationController::class, 'current'])
        ->middleware('workspace:front-office');
    Route::post('/shift-configurations/detect-current-shift', [ShiftConfigurationController::class, 'detectCurrentShift'])
        ->middleware('workspace:front-office');

    Route::middleware(['workspace:front-office', ResolveTillFromToken::class])->group(function () {
        Route::get('/printers/resolve', [PrinterController::class, 'resolveForPrinting']);

        Route::get('/tills/current/settings', [CurrentTerminalController::class, 'show']);
        Route::patch('/tills/current/settings', [CurrentTerminalController::class, 'update']);

        Route::get('/tills/current/printers', [CurrentTerminalController::class, 'printers']);
        Route::post('/tills/current/printers', [CurrentTerminalController::class, 'storePrinter'])
            ->middleware('company.permission:printer_management');
        Route::put('/tills/current/printers/{id}', [CurrentTerminalController::class, 'updatePrinter'])
            ->middleware('company.permission:printer_management');
        Route::delete('/tills/current/printers/{id}', [CurrentTerminalController::class, 'destroyPrinter'])
            ->middleware('company.permission:printer_management');

        Route::get('/tills/current/kitchen-output', [CurrentTerminalKitchenOutputController::class, 'show']);
        Route::put('/tills/current/kitchen-output', [CurrentTerminalKitchenOutputController::class, 'update'])
            ->middleware('company.permission:printer_management');

        Route::get('/tills/current/qz-certificate', [CurrentTerminalQzCertificateController::class, 'show']);
        Route::post('/tills/current/qz-certificate', [CurrentTerminalQzCertificateController::class, 'store'])
            ->middleware('company.permission:printer_management');
        Route::delete('/tills/current/qz-certificate', [CurrentTerminalQzCertificateController::class, 'destroy'])
            ->middleware('company.permission:printer_management');
    });

    // Back Office manages shifts, tills and printers for any branch the user can
    // access, so branch_id on these routes names the record's branch (or filters
    // the list), not the branch this session is signed into.
    Route::middleware('workspace:back-office')->group(function () {
        Route::get('/shift-configurations', [ShiftConfigurationController::class, 'index'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::post('/shift-configurations', [ShiftConfigurationController::class, 'store'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::get('/shift-configurations/{shiftConfiguration}', [ShiftConfigurationController::class, 'show']);
        Route::put('/shift-configurations/{shiftConfiguration}', [ShiftConfigurationController::class, 'update'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::delete('/shift-configurations/{shiftConfiguration}', [ShiftConfigurationController::class, 'destroy']);

        Route::get('/tills', [TillController::class, 'index'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::post('/tills', [TillController::class, 'store'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::get('/tills/generate-code', [TillController::class, 'generateTillCode']);
        Route::get('/tills/{id}', [TillController::class, 'show']);
        Route::put('/tills/{id}', [TillController::class, 'update'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::delete('/tills/{id}', [TillController::class, 'destroy']);
        Route::post('/tills/{id}/activation-code', [TillController::class, 'issueStandaloneActivationCode']);
        Route::post('/tills/{id}/activate', [TillController::class, 'activate']);
        Route::post('/tills/{id}/unregister', [TillController::class, 'unregister']);

        Route::get('/printers', [PrinterController::class, 'index'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::post('/printers', [PrinterController::class, 'store'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::get('/printers/{id}', [PrinterController::class, 'show']);
        Route::put('/printers/{id}', [PrinterController::class, 'update'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::delete('/printers/{id}', [PrinterController::class, 'destroy']);

        Route::get('/qz-certificates', [QzCertificateController::class, 'index'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::post('/qz-certificates', [QzCertificateController::class, 'store'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::get('/qz-certificates/{id}', [QzCertificateController::class, 'show']);
        Route::post('/qz-certificates/{id}', [QzCertificateController::class, 'update'])
            ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
        Route::delete('/qz-certificates/{id}', [QzCertificateController::class, 'destroy']);
    });

    Route::get('/tills/current/resolve', [TillController::class, 'resolveCurrentTill'])
        ->middleware('workspace:front-office');
});
