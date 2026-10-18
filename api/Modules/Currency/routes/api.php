<?php

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Modules\Currency\Http\Controllers\CurrencyController;
use Modules\Currency\Http\Controllers\PaymentTypeController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office'])->prefix('v1')->group(function () {
    Route::apiResource('currencies', CurrencyController::class)->names('currency');
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {
    Route::get('/currencies', [CurrencyController::class, 'index']);
    Route::post('/currencies', [CurrencyController::class, 'store'])->middleware('workspace:back-office');
    Route::get('/currencies/{id}', [CurrencyController::class, 'show']);
    Route::put('/currencies/{id}', [CurrencyController::class, 'update'])->middleware('workspace:back-office');
    Route::delete('/currencies/{id}', [CurrencyController::class, 'destroy'])->middleware('workspace:back-office');

    Route::post('/currencies/{id}/rates', [CurrencyController::class, 'addRate'])->middleware('workspace:back-office');

    Route::get('/payment-types', [PaymentTypeController::class, 'index']);
    Route::post('/payment-types', [PaymentTypeController::class, 'store'])->middleware('workspace:back-office');
    Route::get('/payment-types/{id}', [PaymentTypeController::class, 'show']);
    Route::put('/payment-types/{id}', [PaymentTypeController::class, 'update'])->middleware('workspace:back-office');
    Route::delete('/payment-types/{id}', [PaymentTypeController::class, 'destroy'])->middleware('workspace:back-office');
});
