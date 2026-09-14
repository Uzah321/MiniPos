<?php

use Illuminate\Support\Facades\Route;
use Modules\Terminals\TerminalController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::post('/terminals/register', [TerminalController::class, 'register']);
    Route::get('/terminals/{terminal}/status', [TerminalController::class, 'status']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/terminals/{terminal}/peripheral-check', [TerminalController::class, 'peripheralCheck']);
    });
});
