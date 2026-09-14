<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\CustomerController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/sales/{sale}/customer', [CustomerController::class, 'attach']);
        Route::post('/sales/{sale}/customer/register', [CustomerController::class, 'register']);
        Route::delete('/sales/{sale}/customer', [CustomerController::class, 'remove']);
    });
});
