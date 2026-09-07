<?php

use App\Auth\AuthController;
use App\CreditNotes\CreditNoteController;
use App\Customers\CustomerController;
use App\Discounts\DiscountController;
use App\Items\ItemController;
use App\Payments\PaymentController;
use App\Receipts\ReceiptController;
use App\Sales\SaleController;
use App\Shifts\ShiftController;
use App\Shifts\TillController;
use App\Terminals\TerminalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/terminals/register', [TerminalController::class, 'register']);
    Route::get('/terminals/{terminal}/status', [TerminalController::class, 'status']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', fn (Request $request) => $request->user()->load('roles'));
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::post('/tills/open', [TillController::class, 'open']);
        Route::post('/terminals/{terminal}/peripheral-check', [TerminalController::class, 'peripheralCheck']);

        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/price-enquiry/{code}', [ItemController::class, 'priceEnquiry']);

        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/lines', [SaleController::class, 'addLine']);
        Route::patch('/sales/{sale}/lines/{line}', [SaleController::class, 'updateLine']);
        Route::delete('/sales/{sale}/lines/{line}', [SaleController::class, 'destroyLine']);

        Route::get('/sales/{sale}/payments', [PaymentController::class, 'index']);
        Route::post('/sales/{sale}/payments', [PaymentController::class, 'store']);
        Route::post('/sales/{sale}/payments/{payment}/query', [PaymentController::class, 'query']);
        Route::post('/sales/{sale}/payments/{payment}/cancel', [PaymentController::class, 'cancel']);

        Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'show']);
        Route::post('/sales/{sale}/receipt/reprint', [ReceiptController::class, 'reprint']);

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/sales/{sale}/customer', [CustomerController::class, 'attach']);
        Route::post('/sales/{sale}/customer/register', [CustomerController::class, 'register']);
        Route::delete('/sales/{sale}/customer', [CustomerController::class, 'remove']);

        Route::post('/sales/{sale}/discounts/manual', [DiscountController::class, 'manual']);
        Route::post('/sales/{sale}/coupon', [DiscountController::class, 'coupon']);
        Route::post('/sales/{sale}/loyalty/redeem', [DiscountController::class, 'redeemLoyalty']);

        Route::post('/returns', [CreditNoteController::class, 'store']);
        Route::post('/returns/no-receipt', [CreditNoteController::class, 'noReceipt']);
        Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show']);
        Route::post('/credit-notes/{creditNote}/reject', [CreditNoteController::class, 'reject']);
        Route::post('/credit-notes/{creditNote}/refund', [CreditNoteController::class, 'refund']);
        Route::post('/credit-notes/{creditNote}/refund/query', [CreditNoteController::class, 'queryRefund']);
        Route::post('/credit-notes/{creditNote}/reprint', [CreditNoteController::class, 'reprint']);
    });
});
