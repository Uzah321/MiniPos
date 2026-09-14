<?php

use Illuminate\Support\Facades\Route;
use Modules\Discounts\DiscountController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/sales/{sale}/discounts/manual', [DiscountController::class, 'manual']);
        Route::post('/sales/{sale}/coupon', [DiscountController::class, 'coupon']);
        Route::post('/sales/{sale}/loyalty/redeem', [DiscountController::class, 'redeemLoyalty']);
    });
});
