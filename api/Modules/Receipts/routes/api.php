<?php

use Illuminate\Support\Facades\Route;
use Modules\Receipts\ReceiptController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'show']);
        Route::post('/sales/{sale}/receipt/reprint', [ReceiptController::class, 'reprint']);
    });
});
