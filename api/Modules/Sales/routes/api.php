<?php

use App\Http\Controllers\MobileFiscalDeviceController;
use App\Http\Controllers\MobileFiscalReceiptController;
use App\Http\Controllers\MobileSaleReconciliationController;
use App\Http\Middleware\BlockPosWhenDayEndRunning;
use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveMobileSaleSnapshot;
use App\Http\Middleware\ResolveTillFromToken;
use App\Services\CompanyBranchContextService as BranchContext;
use Illuminate\Support\Facades\Route;
use Modules\FiscalDevice\Http\Controllers\FiscalDayController;
use Modules\FiscalDevice\Http\Controllers\FiscalDeviceController;
use Modules\Sales\Http\Controllers\CashupController;
use Modules\Sales\Http\Controllers\CreditCustomerController;
use Modules\Sales\Http\Controllers\DiscountController;
use Modules\Sales\Http\Controllers\DiscountReportController;
use Modules\Sales\Http\Controllers\FastFoodPrepModifierController;
use Modules\Sales\Http\Controllers\FoodIndustrySalesReportController;
use Modules\Sales\Http\Controllers\HospitalityTableManagementController;
use Modules\Sales\Http\Controllers\HospitalityTableOrderController;
use Modules\Sales\Http\Controllers\KitchenPrintJobController;
use Modules\Sales\Http\Controllers\PriceLevelOverrideController;
use Modules\Sales\Http\Controllers\QuotationController;
use Modules\Sales\Http\Controllers\QzController;
use Modules\Sales\Http\Controllers\SalesController;
use Modules\Sales\Http\Controllers\SalesReportController;
use Modules\ZimraIntegration\Http\Controllers\EnterpriseTaxReportController;
use Modules\ZimraIntegration\Http\Controllers\ZimraCompanySettingController;
use Modules\ZimraIntegration\Http\Controllers\ZimraReceiptController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:front-office', ResolveTillFromToken::class])->prefix('v1')->group(function () {
    Route::post('sales', [SalesController::class, 'store'])
        ->middleware([BlockPosWhenDayEndRunning::class, 'company.permission:create_sale'])
        ->name('sales.store');
});

// Android receipts are ordinary cashier sales with one additional proof: the
// request is HMAC-signed by the paired node and names the exact configuration
// snapshot the device acknowledged before it priced the receipt. Tenant, till,
// license and workspace checks match web FOH; historical Day End/shift checks
// live in the controller so an offline receipt can be staged for reconciliation
// without mutating closed accounting periods.
Route::post('/pos/mobile/sales', [SalesController::class, 'store'])
    ->middleware([
        'auth:sanctum',
        'sync.node',
        ResolveCompanyFromDomain::class,
        CheckCompanyLicense::class,
        'workspace:front-office',
        ResolveTillFromToken::class,
        ResolveMobileSaleSnapshot::class,
        BlockPosWhenDayEndRunning::class,
        'company.permission:create_sale',
    ])
    ->name('pos.mobile.sales.store');

Route::get('/pos/mobile/fiscal-device/ownership', [MobileFiscalDeviceController::class, 'ownership'])
    ->middleware('sync.node')
    ->name('pos.mobile.fiscal-device.ownership');

Route::post('/pos/mobile/fiscal-receipts/{clientSaleId}/ack', [MobileFiscalReceiptController::class, 'acknowledge'])
    ->middleware('sync.node')
    ->name('pos.mobile.fiscal-receipt.acknowledge');

Route::post('/pos/mobile/fiscal-files/{fiscalDayNo}/{fileSequence}/confirm', [MobileFiscalReceiptController::class, 'confirmFile'])
    ->whereNumber('fiscalDayNo')
    ->whereNumber('fileSequence')
    ->middleware('sync.node')
    ->name('pos.mobile.fiscal-file.confirm');

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'company.permission:manage_integrations',
])->prefix('pos/management')->group(function () {
    Route::get('/mobile-fiscal-devices/eligible-nodes', [MobileFiscalDeviceController::class, 'eligibleNodes']);
    Route::get('/mobile-fiscal-devices', [MobileFiscalDeviceController::class, 'index']);
    Route::post('/mobile-fiscal-devices', [MobileFiscalDeviceController::class, 'register']);
    Route::get('/mobile-sale-reconciliations', [MobileSaleReconciliationController::class, 'index']);
    Route::get('/mobile-sale-reconciliations/{id}', [MobileSaleReconciliationController::class, 'show']);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'business.mode:hospitality',
])->prefix('pos/management/hospitality/table-service')->group(function () {
    Route::get('/settings', [HospitalityTableManagementController::class, 'settings'])
        ->middleware('company.permission:view_hospitality_tables,manage_hospitality_tables')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::put('/settings', [HospitalityTableManagementController::class, 'updateSettings'])
        ->middleware('company.permission:manage_hospitality_tables')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::get('/tables', [HospitalityTableManagementController::class, 'index'])
        ->middleware('company.permission:view_hospitality_tables,manage_hospitality_tables')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::post('/tables', [HospitalityTableManagementController::class, 'store'])
        ->middleware('company.permission:manage_hospitality_tables')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::put('/tables/{tableId}', [HospitalityTableManagementController::class, 'update'])
        ->whereUuid('tableId')
        ->middleware('company.permission:manage_hospitality_tables')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:front-office,back-office',
    ResolveTillFromToken::class.':front-office-only',
])->group(function () {
    Route::get('/qz/cert', [QzController::class, 'cert']);
    Route::post('/qz/sign', [QzController::class, 'sign']);
});

// Company-wide Back Office reporting. These endpoints deliberately do not use
// ResolveTillFromToken: management users select branches/tills as report
// filters and are authorised by company role permission instead of a physical
// terminal registration.
Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'company.permission:view_sales_reports,view_profit_loss',
    'workspace:back-office',
])->prefix('pos/management')->group(function () {
    Route::get('/reports/sales-dashboard', [SalesReportController::class, 'dashboard']);
    Route::get('/reports/sales', [SalesReportController::class, 'salesList']);
    Route::get('/sales/{saleId}/drilldown', [SalesReportController::class, 'saleDrilldown']);
    Route::get('/reports/payments', [SalesReportController::class, 'paymentSummary']);
    Route::get('/reports/products', [SalesReportController::class, 'productSales']);
    Route::get('/reports/products/{productId}/drilldown', [SalesReportController::class, 'productDrilldown']);
    Route::get('/reports/cashiers', [SalesReportController::class, 'cashierSales']);
    Route::get('/reports/branch-turnover', [SalesReportController::class, 'branchTurnover']);
    Route::get('/reports/shifts', [SalesReportController::class, 'shiftSales']);
    Route::get('/reports/shifts/{shiftSessionId}/sales', [SalesReportController::class, 'shiftSalesDrilldown']);
    Route::get('/reports/day-ends', [SalesReportController::class, 'dayEnds']);
    Route::get('/reports/shift-sessions', [SalesReportController::class, 'shiftSessions']);
    Route::get('/reports/daily-sales', [SalesReportController::class, 'dailyReport']);
    Route::get('/reports/profit-loss', [SalesReportController::class, 'profitAndLoss']);

    Route::get('/reports/sales-dashboard/export/excel', [SalesReportController::class, 'dashboardExcel']);
    Route::get('/reports/sales-dashboard/export/pdf', [SalesReportController::class, 'dashboardPdf']);
    Route::get('/reports/daily-sales/export/excel', [SalesReportController::class, 'dailySalesExcel']);
    Route::get('/reports/daily-sales/export/pdf', [SalesReportController::class, 'dailySalesPdf']);
    Route::get('/reports/sales/export/excel', [SalesReportController::class, 'salesListExcel']);
    Route::get('/reports/sales/export/pdf', [SalesReportController::class, 'salesListPdf']);
    Route::get('/reports/payments/export/excel', [SalesReportController::class, 'paymentsExcel']);
    Route::get('/reports/payments/export/pdf', [SalesReportController::class, 'paymentsPdf']);
    Route::get('/reports/products/export/excel', [SalesReportController::class, 'productsExcel']);
    Route::get('/reports/products/export/pdf', [SalesReportController::class, 'productsPdf']);
    Route::get('/reports/cashiers/export/excel', [SalesReportController::class, 'cashiersExcel']);
    Route::get('/reports/cashiers/export/pdf', [SalesReportController::class, 'cashiersPdf']);
    Route::get('/reports/shifts/export/excel', [SalesReportController::class, 'shiftsExcel']);
    Route::get('/reports/shifts/export/pdf', [SalesReportController::class, 'shiftsPdf']);
    Route::get('/reports/day-ends/export/excel', [SalesReportController::class, 'dayEndsExcel']);
    Route::get('/reports/day-ends/export/pdf', [SalesReportController::class, 'dayEndsPdf']);
    Route::get('/reports/profit-loss/export/excel', [SalesReportController::class, 'profitAndLossExcel']);
    Route::get('/reports/profit-loss/export/pdf', [SalesReportController::class, 'profitAndLossPdf']);
    Route::get('/reports/branch-turnover/export/excel', [SalesReportController::class, 'branchTurnoverExcel']);
    Route::get('/reports/branch-turnover/export/pdf', [SalesReportController::class, 'branchTurnoverPdf']);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {
    // Currency conversion is a read-only calculation used by both quotation
    // workspaces. It does not require or mutate a registered till.
    Route::post('/currencies/convert', [SalesController::class, 'convertCurrency']);

    // The register is a read-only operational record used by both workspaces.
    // Action lookups, cancelled receipts, reprints and cancellations remain
    // Back Office concerns below.
    Route::middleware([
        'workspace:front-office,back-office',
        ResolveTillFromToken::class.':front-office-only',
    ])->group(function () {
        Route::get('/receipts/register', [SalesController::class, 'receiptRegister']);
        Route::get('/receipts/register/{saleId}', [SalesController::class, 'receiptRegisterShow']);
        Route::get('/receipts/register/{saleId}/pdf', [SalesController::class, 'receiptPdfForSharing']);
        Route::get('/receipts/register/{saleId}/pdf-file', [SalesController::class, 'receiptPdfFileForSharing']);
    });

    Route::middleware('workspace:back-office')->group(function () {
        Route::get('/receipts/cancelled', [SalesController::class, 'cancelledReceipts']);
        Route::get('/receipts/find', [SalesController::class, 'findReceiptForAction']);
        Route::post('/receipts/reprint', [SalesController::class, 'reprintReceipt']);
        Route::post('/receipts/cancel', [SalesController::class, 'cancelReceipt']);

        // Discount setup and discount reporting are Back Office administration.
        // Applying/calculating a discount during a sale remains in the till-bound
        // route group below.
        Route::get('/discount-types', [DiscountController::class, 'types']);
        Route::get('/discounts', [DiscountController::class, 'index']);
        Route::post('/discounts', [DiscountController::class, 'store']);
        Route::put('/discounts/{id}', [DiscountController::class, 'update']);
        Route::delete('/discounts/{id}', [DiscountController::class, 'destroy']);
        Route::get('/discounts/{id}', [DiscountController::class, 'show']);

        Route::get('/discount-reports/dashboard', [DiscountReportController::class, 'dashboard']);
        Route::get('/discount-reports/transactions', [DiscountReportController::class, 'report']);
        Route::get('/discount-reports/by-type', [DiscountReportController::class, 'byTypeReport']);
        Route::get('/discount-reports/by-cashier', [DiscountReportController::class, 'byCashierReport']);
        Route::get('/discount-reports/export', [DiscountReportController::class, 'export']);

        // Back-office cash-control reports are read-only branch/shift views. They do not
        // depend on a registered till and remain available while operational locks run.
        Route::get('/cashups', [CashupController::class, 'cashupHistory']);
        Route::get('/cashups/export/excel', [CashupController::class, 'cashupHistoryExcel']);
        Route::get('/cashups/export/pdf', [CashupController::class, 'cashupHistoryPdf']);
        Route::get('/cashouts/report', [CashupController::class, 'cashoutReport']);
        Route::get('/cashouts/report/export/excel', [CashupController::class, 'cashoutReportExcel']);
        Route::get('/cashouts/report/export/pdf', [CashupController::class, 'cashoutReportPdf']);
        Route::get('/shift-compliance/report', [CashupController::class, 'shiftReport']);
        Route::get('/shift-compliance/report/export/excel', [CashupController::class, 'shiftReportExcel']);
        Route::get('/shift-compliance/report/export/pdf', [CashupController::class, 'shiftReportPdf']);
    });

    // Register this literal route before /credit-customers/{id}; otherwise Laravel
    // can interpret "pos-lookup" as a customer id.
    Route::get('/credit-customers/pos-lookup', [CreditCustomerController::class, 'posLookup'])
        ->middleware(['workspace:front-office', ResolveTillFromToken::class, BlockPosWhenDayEndRunning::class]);

    // Create Sale account actions are explicitly till-bound. Keeping these apart
    // from the Back Office routes below prevents a POS collection from losing its
    // cashier/shift context and therefore disappearing from cashup.
    Route::get('/credit-customers/{id}/pos-account', [CreditCustomerController::class, 'posAccount'])
        ->whereUuid('id')
        ->middleware(['workspace:front-office', ResolveTillFromToken::class, BlockPosWhenDayEndRunning::class]);
    Route::post('/credit-customers/{id}/pos-payments', [CreditCustomerController::class, 'recordPayment'])
        ->whereUuid('id')
        ->middleware(['workspace:front-office', ResolveTillFromToken::class, BlockPosWhenDayEndRunning::class]);
    Route::post('/credit-customers/{id}/pos-payments/{paymentId}/reverse', [CreditCustomerController::class, 'reversePayment'])
        ->whereUuid('id')
        ->whereUuid('paymentId')
        ->middleware(['workspace:front-office', ResolveTillFromToken::class, BlockPosWhenDayEndRunning::class]);

    Route::middleware('workspace:back-office')->group(function () {
        // Credit-account administration belongs to Back Office and must not depend on
        // a browser/device till registration. POS-only lookup remains in the till group.
        Route::prefix('/credit-customers')->group(function () {
            Route::get('/', [CreditCustomerController::class, 'index']);
            Route::post('/', [CreditCustomerController::class, 'store']);
            Route::get('/report', [CreditCustomerController::class, 'report']);
            Route::get('/{id}', [CreditCustomerController::class, 'show'])->whereUuid('id');
            Route::put('/{id}', [CreditCustomerController::class, 'update'])->whereUuid('id');
            Route::post('/{id}/approve', [CreditCustomerController::class, 'approve'])->whereUuid('id');
            Route::post('/{id}/block', [CreditCustomerController::class, 'block'])->whereUuid('id');
            Route::get('/{id}/statement', [CreditCustomerController::class, 'statement'])->whereUuid('id');
            Route::get('/{id}/statement/pdf', [CreditCustomerController::class, 'statementPdf'])->whereUuid('id');
            Route::post('/{id}/payments', [CreditCustomerController::class, 'recordPayment'])->whereUuid('id');
            Route::get('/{id}/payments/{paymentId}/receipt/pdf', [CreditCustomerController::class, 'paymentReceiptPdf'])
                ->whereUuid('id')
                ->whereUuid('paymentId');
            Route::post('/{id}/payments/{paymentId}/reverse', [CreditCustomerController::class, 'reversePayment'])
                ->whereUuid('id')
                ->whereUuid('paymentId');
        });

        // Enterprise tax reporting is a Back Office report. It is company and
        // allocated-branch scoped by the controller and never requires a POS till.
        Route::middleware(['company.permission:view_tax_reports,tax_management'])
            ->prefix('/reports/tax')->group(function () {
                Route::get('/summary', [EnterpriseTaxReportController::class, 'summary']);
                Route::get('/by-tax', [EnterpriseTaxReportController::class, 'byTax']);
                Route::get('/details', [EnterpriseTaxReportController::class, 'details']);
                Route::get('/export/{format}', [EnterpriseTaxReportController::class, 'download'])
                    ->whereIn('format', ['pdf', 'xlsx', 'csv']);
                Route::get('/zimra-receipts', [EnterpriseTaxReportController::class, 'zimraRegister']);
                Route::get('/zimra-receipts/{receiptId}', [EnterpriseTaxReportController::class, 'zimraReceiptDetail']);
            });

        // Hospitality menu configuration is a Back Office concern. The POS product
        // options lookup remains in the till-bound group below.
        Route::middleware('business.mode:hospitality')->group(function () {
            Route::get('/fast-food/prep-messages', [FastFoodPrepModifierController::class, 'prepMessages']);
            Route::post('/fast-food/prep-messages', [FastFoodPrepModifierController::class, 'storePrepMessage']);
            Route::get('/fast-food/prep-messages/{id}', [FastFoodPrepModifierController::class, 'showPrepMessage']);
            Route::put('/fast-food/prep-messages/{id}', [FastFoodPrepModifierController::class, 'updatePrepMessage']);
            Route::delete('/fast-food/prep-messages/{id}', [FastFoodPrepModifierController::class, 'destroyPrepMessage']);
            Route::post('/fast-food/prep-messages/{id}/assign-products', [FastFoodPrepModifierController::class, 'assignPrepMessageToProducts']);
            Route::delete('/fast-food/prep-messages/{id}/products/{productId}', [FastFoodPrepModifierController::class, 'removePrepMessageFromProduct']);

            Route::get('/fast-food/modifiers', [FastFoodPrepModifierController::class, 'modifiers']);
            Route::post('/fast-food/modifiers', [FastFoodPrepModifierController::class, 'storeModifier']);
            Route::get('/fast-food/modifiers/{id}', [FastFoodPrepModifierController::class, 'showModifier']);
            Route::put('/fast-food/modifiers/{id}', [FastFoodPrepModifierController::class, 'updateModifier']);
            Route::delete('/fast-food/modifiers/{id}', [FastFoodPrepModifierController::class, 'destroyModifier']);
            Route::post('/fast-food/modifiers/{id}/assign-products', [FastFoodPrepModifierController::class, 'assignModifierToProducts']);
            Route::delete('/fast-food/modifiers/{id}/products/{productId}', [FastFoodPrepModifierController::class, 'removeModifierFromProduct']);
            Route::get('/fast-food/products/{productId}/configuration-options', [FastFoodPrepModifierController::class, 'configurationProductOptions']);
        });

        // Quotation management is till-free. POS lookup and conversion remain bound
        // to the active terminal so the resulting sale keeps its cashier context.
        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::post('/quotations', [QuotationController::class, 'store']);
        Route::get('/quotations/{id}', [QuotationController::class, 'show']);
        Route::put('/quotations/{id}', [QuotationController::class, 'update']);
        Route::post('/quotations/{id}/issue', [QuotationController::class, 'issue']);
        Route::post('/quotations/{id}/cancel', [QuotationController::class, 'cancel']);

        // Fiscal configuration and monitoring identify a fiscal device explicitly;
        // they do not operate through this browser's POS till registration.
        Route::middleware('company.permission:manage_integrations')->prefix('zimra')->group(function () {
            Route::get('/settings', [ZimraCompanySettingController::class, 'show']);
            Route::put('/settings', [ZimraCompanySettingController::class, 'update']);
            Route::get('/devices', [FiscalDeviceController::class, 'index']);
            Route::post('/devices', [FiscalDeviceController::class, 'store']);
            Route::post('/devices/{device}/sync-config', [FiscalDeviceController::class, 'syncConfig']);
            Route::get('/devices/{device}/status', [FiscalDeviceController::class, 'status']);
            Route::get('/fiscal-days', [FiscalDayController::class, 'index']);
            Route::post('/fiscal-days/open', [FiscalDayController::class, 'open']);
            Route::post('/fiscal-days/{fiscalDay}/close', [FiscalDayController::class, 'close']);
            Route::post('/fiscal-days/{fiscalDay}/poll', [FiscalDayController::class, 'poll']);
            Route::get('/receipts', [ZimraReceiptController::class, 'index']);
            Route::get('/receipts/{receipt}', [ZimraReceiptController::class, 'show']);
            Route::post('/sales/{saleId}/retry', [ZimraReceiptController::class, 'retrySale']);
        });

        // Hospitality analysis is a management/reporting surface. Branch and till
        // are filters, not a requirement to register the Back Office browser as a till.
        Route::middleware([
            'company.permission:view_sales_reports',
            'business.mode:hospitality',
        ])->group(function () {
            Route::get('/reports/food-industry-sales/turnover', [FoodIndustrySalesReportController::class, 'turnover']);
            Route::get('/reports/food-industry-sales/daily', [FoodIndustrySalesReportController::class, 'daily']);
            Route::get('/reports/food-industry-sales/category-sales', [FoodIndustrySalesReportController::class, 'categorySales']);
            Route::get('/reports/food-industry-sales/menu-profitability', [FoodIndustrySalesReportController::class, 'menuProfitability']);
            Route::get('/reports/food-industry-sales/excel', [FoodIndustrySalesReportController::class, 'downloadExcel']);
            Route::get('/reports/food-industry-sales/pdf', [FoodIndustrySalesReportController::class, 'downloadPdf']);
            Route::get('/reports/food-industry-sales/currencies', [FoodIndustrySalesReportController::class, 'currencies']);
            Route::get('/reports/food-industry-sales/daily/excel', [FoodIndustrySalesReportController::class, 'downloadDailyExcel']);
            Route::get('/reports/food-industry-sales/daily/pdf', [FoodIndustrySalesReportController::class, 'downloadDailyPdf']);
            Route::get('/reports/food-industry-sales/business-dates', [FoodIndustrySalesReportController::class, 'businessDates']);
        });
    });
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {
    Route::middleware([
        'workspace:front-office',
        ResolveTillFromToken::class,
        BlockPosWhenDayEndRunning::class,
    ])->group(function () {
        Route::prefix('/hospitality')->middleware('business.mode:hospitality')->group(function () {
            Route::get('/table-service', [HospitalityTableOrderController::class, 'snapshot'])
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::get('/table-orders', [HospitalityTableOrderController::class, 'index'])
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::post('/table-orders', [HospitalityTableOrderController::class, 'store'])
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::get('/table-orders/{orderId}', [HospitalityTableOrderController::class, 'show'])
                ->whereUuid('orderId')
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::put('/table-orders/{orderId}', [HospitalityTableOrderController::class, 'update'])
                ->whereUuid('orderId')
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::post('/table-orders/{orderId}/hang', [HospitalityTableOrderController::class, 'hang'])
                ->whereUuid('orderId')
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::post('/table-orders/{orderId}/recall', [HospitalityTableOrderController::class, 'recall'])
                ->whereUuid('orderId')
                ->middleware('company.permission:create_sale,manage_open_tables');
            Route::post('/table-orders/{orderId}/send-to-kitchen', [HospitalityTableOrderController::class, 'sendToKitchen'])
                ->whereUuid('orderId')
                ->middleware('company.permission:create_sale,manage_open_tables');
            // A cashier may submit this route without clear_open_tables only
            // when a scoped manager approval for this exact order is supplied.
            Route::post('/table-orders/{orderId}/clear', [HospitalityTableOrderController::class, 'clear'])
                ->whereUuid('orderId');
        });

        Route::prefix('/front/quotations')->group(function () {
            Route::get('/', [QuotationController::class, 'frontIndex']);
            Route::post('/', [QuotationController::class, 'frontStore']);
            Route::get('/code/{quoteCode}', [QuotationController::class, 'lookup']);
            Route::post('/code/{quoteCode}/convert', [QuotationController::class, 'convert']);
            Route::get('/{id}', [QuotationController::class, 'frontShow']);
            Route::put('/{id}', [QuotationController::class, 'frontUpdate']);
            Route::post('/{id}/issue', [QuotationController::class, 'frontIssue']);
            Route::post('/{id}/cancel', [QuotationController::class, 'frontCancel']);
        });

        Route::post('/sales', [SalesController::class, 'store'])
            ->middleware('company.permission:create_sale');
        Route::get('/reports/sales-dashboard/export/excel', [SalesReportController::class, 'dashboardExcel']);
        Route::get('/reports/sales-dashboard/export/pdf', [SalesReportController::class, 'dashboardPdf']);
        Route::get('/reports/daily-sales/export/excel', [SalesReportController::class, 'dailySalesExcel']);
        Route::get('/reports/daily-sales/export/pdf', [SalesReportController::class, 'dailySalesPdf']);
        Route::get('/reports/sales/export/excel', [SalesReportController::class, 'salesListExcel']);
        Route::get('/reports/sales/export/pdf', [SalesReportController::class, 'salesListPdf']);
        Route::get('/reports/payments/export/excel', [SalesReportController::class, 'paymentsExcel']);
        Route::get('/reports/payments/export/pdf', [SalesReportController::class, 'paymentsPdf']);
        Route::get('/reports/products/export/excel', [SalesReportController::class, 'productsExcel']);
        Route::get('/reports/products/export/pdf', [SalesReportController::class, 'productsPdf']);
        Route::get('/reports/cashiers/export/excel', [SalesReportController::class, 'cashiersExcel']);
        Route::get('/reports/cashiers/export/pdf', [SalesReportController::class, 'cashiersPdf']);
        Route::get('/reports/shifts/export/excel', [SalesReportController::class, 'shiftsExcel']);
        Route::get('/reports/shifts/export/pdf', [SalesReportController::class, 'shiftsPdf']);
        Route::get('/reports/day-ends/export/excel', [SalesReportController::class, 'dayEndsExcel']);
        Route::get('/reports/day-ends/export/pdf', [SalesReportController::class, 'dayEndsPdf']);
        Route::get('/reports/profit-loss/export/excel', [SalesReportController::class, 'profitAndLossExcel']);
        Route::get('/reports/profit-loss/export/pdf', [SalesReportController::class, 'profitAndLossPdf']);
        Route::get('/reports/branch-turnover/export/excel', [SalesReportController::class, 'branchTurnoverExcel']);
        Route::get('/reports/branch-turnover/export/pdf', [SalesReportController::class, 'branchTurnoverPdf']);

        //        Route::get('/cashup/active-shift-summary', [CashupController::class, 'activeShiftSummary']);
        //        Route::post('/cashup', [CashupController::class, 'cashup']);
        //        Route::post('/shifts/end', [CashupController::class, 'endShift']);
        Route::get('/shift-compliance', [CashupController::class, 'shiftCompliance']);
        Route::get('/cashup/active-shift-summary', [CashupController::class, 'activeShiftSummary'])
            ->middleware('company.permission:cashup_shift');
        Route::post('/cashup', [CashupController::class, 'cashup'])
            ->middleware('company.permission:cashup_shift');
        Route::post('/cashup/end-shift', [CashupController::class, 'endShift'])
            ->middleware('company.permission:end_shift');
        Route::post('/cash-drawer-movements', [CashupController::class, 'recordCashMovement'])
            ->middleware('company.permission:cashup_shift');

        Route::get('/reports/sales-dashboard', [SalesReportController::class, 'dashboard']);

        Route::get('/sales/{saleId}/drilldown', [SalesReportController::class, 'saleDrilldown']);

        Route::get('/reports/payments', [SalesReportController::class, 'paymentSummary']);

        Route::get('/reports/products', [SalesReportController::class, 'productSales']);
        Route::get('/reports/products/{productId}/drilldown', [SalesReportController::class, 'productDrilldown']);

        Route::get('/reports/cashiers', [SalesReportController::class, 'cashierSales']);
        Route::get('/reports/branch-turnover', [SalesReportController::class, 'branchTurnover']);

        Route::get('/reports/shifts', [SalesReportController::class, 'shiftSales']);
        Route::get('/reports/shifts/{shiftSessionId}/sales', [SalesReportController::class, 'shiftSalesDrilldown']);

        Route::get('/reports/day-ends', [SalesReportController::class, 'dayEnds']);

        Route::get('/reports/shift-sessions', [SalesReportController::class, 'shiftSessions']);

        Route::get('/reports/daily-sales', [SalesReportController::class, 'dailyReport']);

        Route::get('/reports/profit-loss', [SalesReportController::class, 'profitAndLoss']);

        // Existing endpoint now returns summary + cost/profit columns:
        Route::get('/reports/sales', [SalesReportController::class, 'salesList']);

        Route::get('/price-level-overrides/config', [PriceLevelOverrideController::class, 'config']);
        Route::get('/price-level-overrides/products/{productId}/levels', [PriceLevelOverrideController::class, 'productPriceLevels']);
        Route::post('/price-level-overrides/apply', [PriceLevelOverrideController::class, 'apply']);

        Route::get('/discounts/eligible/list', [DiscountController::class, 'eligible']);
        Route::post('/discounts/calculate', [DiscountController::class, 'calculate']);

        Route::get('/fast-food/products/{productId}/options', [FastFoodPrepModifierController::class, 'productOptions'])
            ->middleware('business.mode:hospitality');
        Route::get('/kitchen-print-jobs', [KitchenPrintJobController::class, 'index'])
            ->middleware(['business.mode:hospitality', 'company.permission:create_sale']);
        Route::post('/kitchen-print-jobs/{id}/acknowledge', [KitchenPrintJobController::class, 'acknowledge'])
            ->middleware(['business.mode:hospitality', 'company.permission:create_sale']);
        Route::post('/kitchen-print-jobs/{id}/retry', [KitchenPrintJobController::class, 'retry'])
            ->middleware(['business.mode:hospitality', 'company.permission:create_sale']);
        Route::get('/quotations/code/{quoteCode}', [QuotationController::class, 'lookup']);
        Route::post('/quotations/code/{quoteCode}/convert', [QuotationController::class, 'convert']);

    });
});

Route::get('/assets/receipts/{companyId}/{branchId}/{filename}', function (
    string $companyId,
    string $branchId,
    string $filename
) {
    $safeFilename = basename($filename);

    $path = base_path(
        'assets/receipts/'.
        $companyId.'/'.
        $branchId.'/'.
        $safeFilename
    );

    if (! file_exists($path)) {
        abort(404, 'Receipt not found.');
    }

    return response()->file($path, [
        'Content-Type' => 'application/pdf',
    ]);
});
