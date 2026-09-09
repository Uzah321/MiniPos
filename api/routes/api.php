<?php

use App\Auth\AuthController;
use App\CreditNotes\CreditNoteController;
use App\Customers\CustomerController;
use App\Discounts\DiscountController;
use App\Items\ItemController;
use App\Kitchen\KitchenOrderController;
use App\Offline\OfflineSyncController;
use App\Payments\PaymentController;
use App\Receipts\ReceiptController;
use App\Sales\SaleController;
use App\Shifts\ShiftController;
use App\Shifts\TillController;
use App\Tables\TableController;
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
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);
        Route::post('/shifts/{shift}/reassign-terminal', [ShiftController::class, 'reassignTerminal']);
        Route::post('/sync/offline-sales', [OfflineSyncController::class, 'sync']);
        Route::post('/tills/open', [TillController::class, 'open']);
        Route::post('/tills/{till}/no-sale', [TillController::class, 'noSale']);
        Route::post('/tills/{till}/paid-in', [TillController::class, 'paidIn']);
        Route::post('/tills/{till}/paid-out', [TillController::class, 'paidOut']);
        Route::post('/tills/{till}/cash-drop', [TillController::class, 'cashDrop']);
        Route::post('/tills/{till}/count', [TillController::class, 'count']);
        Route::post('/tills/{till}/resolve-variance', [TillController::class, 'resolveVariance']);
        Route::post('/tills/{till}/close', [TillController::class, 'close']);
        Route::get('/tills/{till}/x-report', [TillController::class, 'xReport']);
        Route::post('/terminals/{terminal}/peripheral-check', [TerminalController::class, 'peripheralCheck']);

        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/price-enquiry/{code}', [ItemController::class, 'priceEnquiry']);

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

        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/void', [SaleController::class, 'void']);
        Route::post('/sales/{sale}/recover', [SaleController::class, 'recover']);
        Route::post('/sales/{sale}/exception-hold', [SaleController::class, 'exceptionHold']);
        Route::post('/sales/{sale}/lines', [SaleController::class, 'addLine']);
        Route::patch('/sales/{sale}/lines/{line}', [SaleController::class, 'updateLine']);
        Route::delete('/sales/{sale}/lines/{line}', [SaleController::class, 'destroyLine']);

        Route::get('/sales/{sale}/payments', [PaymentController::class, 'index']);
        Route::post('/sales/{sale}/payments', [PaymentController::class, 'store']);
        Route::post('/sales/{sale}/payments/{payment}/query', [PaymentController::class, 'query']);
        Route::post('/sales/{sale}/payments/{payment}/cancel', [PaymentController::class, 'cancel']);

        Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'show']);
        Route::post('/sales/{sale}/receipt/reprint', [ReceiptController::class, 'reprint']);

        Route::post('/sales/{sale}/kitchen-orders', [KitchenOrderController::class, 'send']);
        Route::get('/kitchen-orders/{kitchenOrder}', [KitchenOrderController::class, 'show']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/advance', [KitchenOrderController::class, 'advanceLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/void', [KitchenOrderController::class, 'voidLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/lines/{line}/resend', [KitchenOrderController::class, 'resendLine']);
        Route::post('/kitchen-orders/{kitchenOrder}/handover', [KitchenOrderController::class, 'handover']);
        Route::post('/kitchen-orders/{kitchenOrder}/close', [KitchenOrderController::class, 'close']);

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
