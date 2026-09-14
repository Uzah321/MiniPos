<?php

use Illuminate\Support\Facades\Route;
use Modules\Offline\OfflineSyncController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/sync/offline-sales', [OfflineSyncController::class, 'sync']);
    });
});
