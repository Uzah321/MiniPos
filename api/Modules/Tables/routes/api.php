<?php

use Illuminate\Support\Facades\Route;
use Modules\Tables\TableController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/tables', [TableController::class, 'index']);
        Route::post('/tables/{table}/occupy', [TableController::class, 'occupy']);
        Route::post('/tables/{table}/transfer', [TableController::class, 'transfer']);
        Route::post('/tables/{table}/merge', [TableController::class, 'merge']);
        Route::post('/tables/{table}/split', [TableController::class, 'split']);
        Route::post('/tables/{table}/move-item', [TableController::class, 'moveItem']);
        Route::post('/tables/{table}/service-charge', [TableController::class, 'serviceCharge']);
        Route::post('/tables/{table}/tip', [TableController::class, 'tip']);
        Route::post('/tables/{table}/bill-request', [TableController::class, 'requestBill']);
        Route::post('/tables/{table}/close', [TableController::class, 'close']);
    });
});
