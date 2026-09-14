<?php

use Illuminate\Support\Facades\Route;
use Modules\Items\ItemController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/price-enquiry/{code}', [ItemController::class, 'priceEnquiry']);
    });
});
