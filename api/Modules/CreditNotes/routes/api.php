<?php

use Illuminate\Support\Facades\Route;
use Modules\CreditNotes\CreditNoteController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/returns', [CreditNoteController::class, 'store']);
        Route::post('/returns/no-receipt', [CreditNoteController::class, 'noReceipt']);
        Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show']);
        Route::post('/credit-notes/{creditNote}/reject', [CreditNoteController::class, 'reject']);
        Route::post('/credit-notes/{creditNote}/refund', [CreditNoteController::class, 'refund']);
        Route::post('/credit-notes/{creditNote}/refund/query', [CreditNoteController::class, 'queryRefund']);
        Route::post('/credit-notes/{creditNote}/reprint', [CreditNoteController::class, 'reprint']);
    });
});
