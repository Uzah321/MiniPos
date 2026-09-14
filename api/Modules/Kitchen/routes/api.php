<?php

use Illuminate\Support\Facades\Route;
use Modules\Kitchen\KitchenOrderController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/sales/{sale}/kitchen-orders', [KitchenOrderController::class, 'send']);
        Route::get('/kitchen-orders/{kitchenOrder}', [KitchenOrderController::class, 'show']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/advance', [KitchenOrderController::class, 'advanceLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/void', [KitchenOrderController::class, 'voidLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/resend', [KitchenOrderController::class, 'resendLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/handover', [KitchenOrderController::class, 'handover']);
        Route::post('/kitchen-orders/{kitchenOrder}/close', [KitchenOrderController::class, 'close']);
    });
});
