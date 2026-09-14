<?php

use Illuminate\Support\Facades\Route;
use Modules\Shifts\ShiftController;
use Modules\Shifts\TillController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);
        Route::post('/shifts/{shift}/reassign-terminal', [ShiftController::class, 'reassignTerminal']);
        Route::get('/shifts/{shift}/z-report', [ShiftController::class, 'zReport']);

        Route::post('/tills/open', [TillController::class, 'open']);
        Route::post('/tills/{till}/no-sale', [TillController::class, 'noSale']);
        Route::post('/tills/{till}/paid-in', [TillController::class, 'paidIn']);
        Route::post('/tills/{till}/paid-out', [TillController::class, 'paidOut']);
        Route::post('/tills/{till}/cash-drop', [TillController::class, 'cashDrop']);
        Route::post('/tills/{till}/count', [TillController::class, 'count']);
        Route::post('/tills/{till}/resolve-variance', [TillController::class, 'resolveVariance']);
        Route::post('/tills/{till}/close', [TillController::class, 'close']);
        Route::get('/tills/{till}/x-report', [TillController::class, 'xReport']);
    });
});
