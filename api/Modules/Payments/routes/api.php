<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\PaymentController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/sales/{sale}/payments', [PaymentController::class, 'index']);
        Route::post('/sales/{sale}/payments', [PaymentController::class, 'store']);
        Route::post('/sales/{sale}/payments/{payment}/query', [PaymentController::class, 'query']);
        Route::post('/sales/{sale}/payments/{payment}/cancel', [PaymentController::class, 'cancel']);
    });
});
