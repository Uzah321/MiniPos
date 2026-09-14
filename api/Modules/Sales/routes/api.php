<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\SaleController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/void', [SaleController::class, 'void']);
        Route::post('/sales/{sale}/recover', [SaleController::class, 'recover']);
        Route::post('/sales/{sale}/exception-hold', [SaleController::class, 'exceptionHold']);
        Route::post('/sales/{sale}/lines', [SaleController::class, 'addLine']);
        Route::patch('/sales/{sale}/lines/{line}', [SaleController::class, 'updateLine']);
        Route::delete('/sales/{sale}/lines/{line}', [SaleController::class, 'destroyLine']);
    });
});
