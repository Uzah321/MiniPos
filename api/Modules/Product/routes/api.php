<?php

use App\Http\Middleware\BlockHospitalityInventoryWhenDayEndRunning;
use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use App\Services\CompanyBranchContextService as BranchContext;
use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\BackStockMonthEndStockTakeController;
use Modules\Product\Http\Controllers\ClosingStockMovementReportController;
use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use Modules\Product\Http\Controllers\HospitalityCatalogImportController;
use Modules\Product\Http\Controllers\HospitalityMonthEndController;
use Modules\Product\Http\Controllers\HsCodeController;
use Modules\Product\Http\Controllers\InventoryReportDownloadController;
use Modules\Product\Http\Controllers\InventoryVarianceAgentController;
use Modules\Product\Http\Controllers\MenuRecipeDailyStockReconciliationController;
use Modules\Product\Http\Controllers\PriceController;
use Modules\Product\Http\Controllers\ProductBarcodePrintController;
use Modules\Product\Http\Controllers\ProductCategoryController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ProductImportController;
use Modules\Product\Http\Controllers\ProductSubCategoryController;
use Modules\Product\Http\Controllers\ProductTaxController;
use Modules\Product\Http\Controllers\PurchaseController;
use Modules\Product\Http\Controllers\StockIssuedToFrontReportController;
use Modules\Product\Http\Controllers\StockTakeController;
use Modules\Product\Http\Controllers\StockTransferController;
use Modules\Product\Http\Controllers\StockWastageController;
use Modules\Product\Http\Controllers\SupplierController;
use Modules\Product\Http\Controllers\TaxController;
use Modules\Product\Http\Controllers\TradingDayEndController;

Route::middleware(['auth:sanctum', ResolveCompanyFromDomain::class, CheckCompanyLicense::class, 'workspace:back-office', 'company.permission:product_management'])->prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class)->names('product');
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
])->prefix('pos/management/inventory')->group(function () {
    Route::get('/dashboard', [EnterpriseInventoryController::class, 'dashboard'])->middleware('company.permission:inventory_dashboard,inventory_management,view_stock_reports');
    Route::get('/variance-agent', [InventoryVarianceAgentController::class, 'overview'])->middleware('company.permission:view_inventory_variance_agent,manage_inventory_variance_agent,inventory_dashboard,inventory_management');
    Route::patch('/variance-agent/settings', [InventoryVarianceAgentController::class, 'updateSettings'])->middleware('company.permission:manage_inventory_variance_agent,inventory_management');
    Route::post('/variance-agent/scan', [InventoryVarianceAgentController::class, 'scan'])->middleware('company.permission:manage_inventory_variance_agent,inventory_management');
    Route::get('/variance-agent/variances', [InventoryVarianceAgentController::class, 'variances'])->middleware('company.permission:view_inventory_variance_agent,manage_inventory_variance_agent,inventory_dashboard,inventory_management');
    Route::get('/variance-agent/variances/{varianceId}', [InventoryVarianceAgentController::class, 'show'])->middleware('company.permission:view_inventory_variance_agent,manage_inventory_variance_agent,inventory_dashboard,inventory_management');
    Route::get('/warehouses', [EnterpriseInventoryController::class, 'warehouses'])->middleware('company.permission:stock_transfers,view_warehouse_stock,request_warehouse_transfer,manage_warehouses,inventory_management,view_stock_reports,receive_stock,purchase_orders,view_purchase_receipts');
    Route::post('/warehouses', [EnterpriseInventoryController::class, 'storeWarehouse'])->middleware('company.permission:manage_warehouses,inventory_management');
    Route::put('/warehouses/{warehouseId}', [EnterpriseInventoryController::class, 'updateWarehouse'])->middleware('company.permission:manage_warehouses,inventory_management');
    Route::delete('/warehouses/{warehouseId}', [EnterpriseInventoryController::class, 'destroyWarehouse'])->middleware('company.permission:manage_warehouses,inventory_management');
    Route::get('/warehouses/{warehouseId}/products', [EnterpriseInventoryController::class, 'warehouseProducts'])->middleware('company.permission:view_warehouse_stock,manage_warehouses,inventory_management,view_stock_reports');
    Route::post('/warehouses/{warehouseId}/locations', [EnterpriseInventoryController::class, 'storeLocation'])->middleware('company.permission:manage_warehouses,inventory_management');
    Route::put('/locations/{locationId}', [EnterpriseInventoryController::class, 'updateLocation'])->middleware('company.permission:manage_warehouses,inventory_management');
    Route::get('/batches', [EnterpriseInventoryController::class, 'batches'])->middleware('company.permission:view_inventory_batches,manage_inventory_batches,manage_inventory_recalls,inventory_management,view_stock_reports');
    Route::get('/batches/{batchId}', [EnterpriseInventoryController::class, 'showBatch'])->middleware('company.permission:view_inventory_batches,manage_inventory_batches,manage_inventory_recalls,inventory_management,view_stock_reports');
    Route::put('/batches/{batchId}', [EnterpriseInventoryController::class, 'updateBatch'])->middleware('company.permission:manage_inventory_batches,inventory_management');
    Route::post('/batches/reconcile', [EnterpriseInventoryController::class, 'reconcileBatches'])->middleware('company.permission:manage_inventory_batches,inventory_management');
    Route::get('/movements', [EnterpriseInventoryController::class, 'movements'])->middleware('company.permission:view_inventory_movements,inventory_management,view_stock_reports');
    Route::get('/alerts', [EnterpriseInventoryController::class, 'alerts'])->middleware('company.permission:view_inventory_alerts,manage_inventory_alerts,inventory_management,view_stock_reports');
    Route::post('/alerts/refresh', [EnterpriseInventoryController::class, 'refreshAlerts'])->middleware('company.permission:manage_inventory_alerts,inventory_management');
    Route::get('/recalls', [EnterpriseInventoryController::class, 'recalls'])->middleware('company.permission:view_inventory_recalls,manage_inventory_recalls,inventory_management');
    Route::post('/recalls', [EnterpriseInventoryController::class, 'storeRecall'])->middleware('company.permission:manage_inventory_recalls,inventory_management');
    Route::get('/recalls/{recallId}', [EnterpriseInventoryController::class, 'showRecall'])->middleware('company.permission:view_inventory_recalls,manage_inventory_recalls,inventory_management');
    Route::post('/recalls/{recallId}/close', [EnterpriseInventoryController::class, 'closeRecall'])->middleware('company.permission:manage_inventory_recalls,inventory_management');
});
Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,

])->prefix('pos')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PRODUCT CATEGORIES
    |--------------------------------------------------------------------------
    */

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store'])->middleware('workspace:back-office');
    Route::get('/product-categories/{id}', [ProductCategoryController::class, 'show']);
    Route::put('/product-categories/{id}', [ProductCategoryController::class, 'update'])->middleware('workspace:back-office');
    Route::delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy'])->middleware('workspace:back-office');

    Route::post('/product-categories/{id}/image', [ProductCategoryController::class, 'uploadImage'])->middleware('workspace:back-office');
    Route::delete('/product-categories/{id}/image', [ProductCategoryController::class, 'deleteImage'])->middleware('workspace:back-office');

    Route::post('/product-categories/{id}/banner-image', [ProductCategoryController::class, 'uploadBannerImage'])->middleware('workspace:back-office');
    Route::delete('/product-categories/{id}/banner-image', [ProductCategoryController::class, 'deleteBannerImage'])->middleware('workspace:back-office');

    /*
    |--------------------------------------------------------------------------
    | PRODUCT SUB CATEGORIES
    |--------------------------------------------------------------------------
    */

    Route::get('/product-sub-categories', [ProductSubCategoryController::class, 'index']);
    Route::post('/product-sub-categories', [ProductSubCategoryController::class, 'store'])->middleware('workspace:back-office');
    Route::get('/product-sub-categories/{id}', [ProductSubCategoryController::class, 'show']);
    Route::put('/product-sub-categories/{id}', [ProductSubCategoryController::class, 'update'])->middleware('workspace:back-office');
    Route::delete('/product-sub-categories/{id}', [ProductSubCategoryController::class, 'destroy'])->middleware('workspace:back-office');

    Route::post('/product-sub-categories/{id}/image', [ProductSubCategoryController::class, 'uploadImage'])->middleware('workspace:back-office');
    Route::delete('/product-sub-categories/{id}/image', [ProductSubCategoryController::class, 'deleteImage'])->middleware('workspace:back-office');

    Route::post('/product-sub-categories/{id}/banner-image', [ProductSubCategoryController::class, 'uploadBannerImage'])->middleware('workspace:back-office');
    Route::delete('/product-sub-categories/{id}/banner-image', [ProductSubCategoryController::class, 'deleteBannerImage'])->middleware('workspace:back-office');

    /*
    |--------------------------------------------------------------------------
    | SUPPLIERS
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:back-office')->group(function () {
        Route::get(
            '/suppliers',
            [SupplierController::class, 'index']
        );

        Route::post(
            '/suppliers',
            [SupplierController::class, 'store']
        );

        Route::get(
            '/suppliers/{id}',
            [SupplierController::class, 'show']
        );

        Route::put(
            '/suppliers/{id}',
            [SupplierController::class, 'update']
        );

        Route::delete(
            '/suppliers/{id}',
            [SupplierController::class, 'destroy']
        );
    });

    Route::middleware(['business.mode:hospitality', 'workspace:back-office'])->group(function () {
        Route::get('/stock-takes/month-end/back-stock/snapshot', [BackStockMonthEndStockTakeController::class, 'snapshot']);
        Route::get('/stock-takes/month-end/back-stock/download-balance-snapshot', [BackStockMonthEndStockTakeController::class, 'downloadBalanceSnapshot']);
        Route::get('/stock-takes/month-end/back-stock/download-trading-count-sheet', [BackStockMonthEndStockTakeController::class, 'downloadTradingCountSheet']);
        Route::post('/stock-takes/month-end/back-stock/begin-trading', [BackStockMonthEndStockTakeController::class, 'beginTradingStockTake']);
        Route::post('/stock-takes/month-end/back-stock/upload-trading-count-sheets', [BackStockMonthEndStockTakeController::class, 'uploadTradingCountSheets'])
            ->middleware(BlockHospitalityInventoryWhenDayEndRunning::class);
        Route::get('/stock-takes/month-end/back-stock/{id}', [BackStockMonthEndStockTakeController::class, 'show'])->whereUuid('id');

        Route::get('/month-end/hospitality/preflight', [HospitalityMonthEndController::class, 'preflight']);
        Route::get('/month-end/hospitality/live-preview', [HospitalityMonthEndController::class, 'livePreview']);
        Route::get('/month-end/hospitality/progress/{runKey}', [HospitalityMonthEndController::class, 'progress']);
        Route::post('/month-end/hospitality/run', [HospitalityMonthEndController::class, 'run']);
        Route::get('/month-end/hospitality/reports', [HospitalityMonthEndController::class, 'latest']);
        Route::get('/month-end/hospitality/reports/{id}', [HospitalityMonthEndController::class, 'show'])->whereUuid('id');
        Route::get('/month-end/hospitality/reports/{id}/csv', [HospitalityMonthEndController::class, 'downloadCsv'])->whereUuid('id');
    });

    Route::get('/hs-code/suggestions', [HsCodeController::class, 'suggestions'])->middleware('workspace:back-office');
    Route::post('/products/{productId}/hs-code/approve', [HsCodeController::class, 'approve'])->middleware('workspace:back-office');

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/export/{format}', [ProductController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->middleware('workspace:back-office');
    Route::post('/products', [ProductController::class, 'store'])->middleware('workspace:back-office')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('workspace:back-office')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('workspace:back-office');

    Route::post('/products/{id}/prices', [ProductController::class, 'addPrice'])->middleware('workspace:back-office')
        ->defaults(BranchContext::RECORD_BRANCH_ROUTE_FLAG, true);
    Route::delete('/products/{productId}/prices/{priceId}', [ProductController::class, 'deletePrice'])->middleware('workspace:back-office');

    Route::post('/products/{id}/receive-stock', [ProductController::class, 'receiveStock'])
        ->middleware(['workspace:back-office', BlockHospitalityInventoryWhenDayEndRunning::class]);
    Route::post('/products/{id}/adjust-stock', [ProductController::class, 'adjustStock'])
        ->middleware(['workspace:back-office', BlockHospitalityInventoryWhenDayEndRunning::class]);

    Route::post('/products/adjust-stock-bulk', [ProductController::class, 'adjustStockBulk'])
        ->middleware(['workspace:back-office', BlockHospitalityInventoryWhenDayEndRunning::class]);

    Route::get('/stock-adjustment-history', [ProductController::class, 'stockAdjustmentHistory'])->middleware('workspace:back-office');
    Route::get('/stock-adjustment-history/export/{format}', [InventoryReportDownloadController::class, 'stockAdjustmentHistory'])
        ->where('format', 'pdf|xlsx')
        ->middleware('workspace:back-office');

    Route::post('/products/{childProductId}/break', [ProductController::class, 'breakProduct'])
        ->middleware(['workspace:back-office', BlockHospitalityInventoryWhenDayEndRunning::class]);

    Route::post('products/generate-identity', [ProductController::class, 'generateIdentity'])->middleware('workspace:back-office');

    Route::get('/uoms', [ProductController::class, 'getUoms']);
    // Compatibility for POS pages that were already open on the previously
    // deployed bundle. The canonical endpoint is /api/pos/uoms; retain this
    // read-only alias until all cached clients have moved to the new bundle.
    Route::get('/pos/uoms', [ProductController::class, 'getUoms']);
    Route::post('/products/{id}/image', [ProductController::class, 'uploadImage'])->middleware('workspace:back-office');
    Route::delete('/products/{id}/image', [ProductController::class, 'deleteImage'])->middleware('workspace:back-office');

    Route::middleware(['business.mode:hospitality', 'workspace:back-office'])->group(function () {
        // Hospitality stock-area views and transfers.
        Route::get('/stock/front', [ProductController::class, 'frontStock'])
            ->middleware('company.permission:view_stock_reports,boh_issue_stock,inventory_management');
        Route::get('/stock/back', [ProductController::class, 'backStock'])
            ->middleware('company.permission:view_stock_reports,boh_issue_stock,inventory_management');
        Route::post('/stock/issue-enterprise', [ProductController::class, 'issueBackStockEnterprise'])
            ->middleware([
                'company.permission:boh_issue_stock,inventory_management',
                BlockHospitalityInventoryWhenDayEndRunning::class,
            ]);
        Route::post('/stock/issue-to-front', [ProductController::class, 'issueToFront'])
            ->middleware([
                'company.permission:boh_issue_stock,inventory_management',
                BlockHospitalityInventoryWhenDayEndRunning::class,
            ]);
        Route::get('/issued-stock', [StockIssuedToFrontReportController::class, 'index'])
            ->middleware('company.permission:boh_issue_stock,view_stock_reports,inventory_management');
        Route::get('/issued-stock/{issueId}/pdf', [StockIssuedToFrontReportController::class, 'downloadIssuePdf'])
            ->whereUuid('issueId')
            ->middleware('company.permission:boh_issue_stock,view_stock_reports,inventory_management');
        Route::get('/issued-stock/{issueId}', [StockIssuedToFrontReportController::class, 'show'])
            ->whereUuid('issueId')
            ->middleware('company.permission:boh_issue_stock,view_stock_reports,inventory_management');

        Route::get('/front-stock/daily-closings', [FrontStockDailyClosingController::class, 'index']);
        Route::get('/front-stock/daily-closings/snapshot', [FrontStockDailyClosingController::class, 'snapshot']);
        Route::get('/front-stock/daily-closings/{id}', [FrontStockDailyClosingController::class, 'show']);
        Route::post('/front-stock/daily-closings', [FrontStockDailyClosingController::class, 'store'])
            ->middleware(BlockHospitalityInventoryWhenDayEndRunning::class);
    });

    //    Route::get('stock/issue-enterprise/report', [StockIssuedToFrontReportController::class, 'preview']);
    //    Route::get('stock/issue-enterprise/report/excel', [StockIssuedToFrontReportController::class, 'downloadExcel']);
    //    Route::get('stock/issue-enterprise/report/pdf', [StockIssuedToFrontReportController::class, 'downloadPdf']);

    Route::get('/price-levels', [PriceController::class, 'getPriceLevels'])->middleware('workspace:back-office');
    Route::post('/price-levels', [PriceController::class, 'storePriceLevel'])->middleware('workspace:back-office');
    Route::put('/price-levels/{id}', [PriceController::class, 'updatePriceLevel'])->middleware('workspace:back-office');
    Route::delete('/price-levels/{id}', [PriceController::class, 'deletePriceLevel'])->middleware('workspace:back-office');

    Route::get('/roles/{roleId}/price-levels', [PriceController::class, 'getRolePriceLevels'])->middleware('workspace:back-office');
    Route::put('/roles/{roleId}/price-levels/sync', [PriceController::class, 'syncRolePriceLevels'])->middleware('workspace:back-office');

    Route::get('/products/{productId}/visible-prices', [PriceController::class, 'getProductPricesForCashier']);

    Route::get('/product-price-list', [PriceController::class, 'productPriceListForLoggedInBranch'])->middleware('workspace:back-office');
    Route::get('/product-price-list/download/{format}', [InventoryReportDownloadController::class, 'productPriceList'])
        ->where('format', 'xlsx|excel|pdf')
        ->middleware('workspace:back-office');

    Route::get('/stock/{area}/download/{format}', [InventoryReportDownloadController::class, 'stockArea'])
        ->where('area', 'front|back')
        ->where('format', 'xlsx|excel|pdf')
        ->middleware(['business.mode:hospitality', 'workspace:back-office']);

    Route::post('/products/{productId}/prices/adjust', [PriceController::class, 'adjustProductPrice'])->middleware('workspace:back-office');

    Route::get('/products/{productId}/price-adjustments', [PriceController::class, 'productPriceAdjustmentHistory'])->middleware('workspace:back-office');
    Route::get('/products/{productId}/price-adjustments/export/{format}', [InventoryReportDownloadController::class, 'productPriceAdjustmentHistory'])
        ->where('format', 'pdf|xlsx')
        ->middleware('workspace:back-office');

    Route::post('/products/import', [ProductImportController::class, 'import'])
        ->middleware(['workspace:back-office', BlockHospitalityInventoryWhenDayEndRunning::class]);
    Route::get('/products/import/template', [ProductImportController::class, 'downloadTemplate'])->middleware('workspace:back-office');
    Route::get('/products/import/template-headers', [ProductImportController::class, 'templateHeaders'])->middleware('workspace:back-office');

    Route::middleware(['workspace:back-office', 'company.permission:import_products'])->group(function () {
        Route::get('/hospitality-imports/scope', [HospitalityCatalogImportController::class, 'scope']);
        Route::post('/hospitality-imports/preview', [HospitalityCatalogImportController::class, 'preview']);
        Route::post('/hospitality-imports/commit', [HospitalityCatalogImportController::class, 'commit']);
    });

    // Retail keeps the branch-wide stock-take workflow. Hospitality must use
    // its explicit Back Stock month-end count and Front Stock daily closing so
    // the two physical areas can never be combined accidentally.
    if (! (bool) config('servnest.hospitality_only', true)) {
        Route::middleware(['business.mode:retail', 'workspace:back-office'])->group(function () {
        Route::get('/stock-takes/download-balance-snapshot', [StockTakeController::class, 'downloadBalanceSnapshot']);
        Route::get('/stock-takes/download-trading-count-sheet', [StockTakeController::class, 'downloadTradingCountSheet']);
        Route::post('/stock-takes/begin-trading', [StockTakeController::class, 'beginTradingStockTake']);
        Route::post('/stock-takes/upload-trading-count-sheets', [StockTakeController::class, 'uploadTradingCountSheets']);

        Route::get('/stock-takes/download-count-sheet', [StockTakeController::class, 'downloadCountSheet']);
        Route::post('/stock-takes/upload-count-sheet', [StockTakeController::class, 'uploadCountSheet']);

        Route::get('/stock-takes/reports/dashboard', [StockTakeController::class, 'dashboard']);
        Route::get('/stock-takes/reports/variance', [StockTakeController::class, 'varianceReport']);
        Route::get('/stock-takes/reports/category-variance', [StockTakeController::class, 'categoryVarianceReport']);
        Route::get('/stock-takes/reports/branch-variance', [StockTakeController::class, 'branchVarianceReport']);
        Route::get('/stock-takes/reports/audit', [StockTakeController::class, 'auditReport']);
        Route::get('/stock-takes/reports/export', [StockTakeController::class, 'exportReport']);

        Route::get('/stock-takes', [StockTakeController::class, 'index']);
        Route::get('/stock-takes/{sessionId}', [StockTakeController::class, 'show']);
        Route::post('/stock-takes/{sessionId}/approve', [StockTakeController::class, 'approve']);
        Route::post('/stock-takes/{sessionId}/post-adjustments', [StockTakeController::class, 'postAdjustments']);
        Route::get('/stock-takes/{sessionId}/variance-export', [StockTakeController::class, 'exportVariance']);
        });
    }

    Route::middleware('workspace:back-office')->group(function () {
        Route::get('/stock-transfer-products', [StockTransferController::class, 'lookupProducts'])->middleware('company.permission:stock_transfers,request_warehouse_transfer,view_warehouse_stock');
        Route::get('/stock-transfers/reports/dashboard', [StockTransferController::class, 'dashboard'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');
        Route::get('/stock-transfers/reports/transfers', [StockTransferController::class, 'report'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');
        Route::get('/stock-transfers/reports/lines', [StockTransferController::class, 'lineReport'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');
        Route::get('/stock-transfers/reports/branch', [StockTransferController::class, 'branchReport'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');
        Route::get('/stock-transfers/reports/aging', [StockTransferController::class, 'agingReport'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');
        Route::get('/stock-transfers/reports/export', [StockTransferController::class, 'exportReport'])->middleware('company.permission:stock_transfers,view_warehouse_stock,view_stock_reports');

        Route::get('/stock-transfers', [StockTransferController::class, 'index'])->middleware('company.permission:stock_transfers,view_warehouse_stock,request_warehouse_transfer,approve_warehouse_transfer,dispatch_warehouse_transfer,receive_warehouse_transfer');
        Route::post('/stock-transfers', [StockTransferController::class, 'store'])->middleware('company.permission:stock_transfers,request_warehouse_transfer');

        Route::get('/stock-transfers/{id}', [StockTransferController::class, 'show'])->middleware('company.permission:stock_transfers,view_warehouse_stock,request_warehouse_transfer,approve_warehouse_transfer,dispatch_warehouse_transfer,receive_warehouse_transfer');
        Route::put('/stock-transfers/{id}', [StockTransferController::class, 'update'])->middleware('company.permission:stock_transfers,request_warehouse_transfer');
        Route::post('/stock-transfers/{id}/approve', [StockTransferController::class, 'approve'])->middleware('company.permission:approve_warehouse_transfer');
        Route::post('/stock-transfers/{id}/reject', [StockTransferController::class, 'reject'])->middleware('company.permission:approve_warehouse_transfer');
        Route::post('/stock-transfers/{id}/dispatch', [StockTransferController::class, 'dispatch'])
            ->middleware([
                'company.permission:dispatch_warehouse_transfer,stock_transfers',
                BlockHospitalityInventoryWhenDayEndRunning::class.':transfer_from',
            ]);
        Route::post('/stock-transfers/{id}/receive', [StockTransferController::class, 'receive'])
            ->middleware([
                'company.permission:receive_warehouse_transfer,stock_transfers',
                BlockHospitalityInventoryWhenDayEndRunning::class.':transfer_to',
            ]);
        Route::post('/stock-transfers/{id}/cancel', [StockTransferController::class, 'cancel'])->middleware('company.permission:request_warehouse_transfer,stock_transfers');
    });

    Route::get('/product-barcodes/search', [ProductBarcodePrintController::class, 'search'])->middleware('workspace:back-office');
    Route::get('/product-barcodes/resolve-printer', [ProductBarcodePrintController::class, 'resolvePrinter'])->middleware('workspace:back-office');
    Route::post('/product-barcodes/prepare-print', [ProductBarcodePrintController::class, 'preparePrint'])->middleware('workspace:back-office');

    Route::get('/taxes', [TaxController::class, 'index']);
    Route::get('/taxes/dropdown', [TaxController::class, 'dropdown']);
    Route::get('/taxes/{id}', [TaxController::class, 'show']);
    Route::post('/taxes', [TaxController::class, 'store'])->middleware('workspace:back-office');
    Route::put('/taxes/{id}', [TaxController::class, 'update'])->middleware('workspace:back-office');
    Route::delete('/taxes/{id}', [TaxController::class, 'destroy'])->middleware('workspace:back-office');

    Route::post('/products/taxes/sync-many', [ProductTaxController::class, 'syncMany'])->middleware('workspace:back-office');
    Route::get('/products/{productId}/taxes', [ProductTaxController::class, 'index'])->middleware('workspace:back-office');
    Route::post('/products/{productId}/taxes/sync', [ProductTaxController::class, 'sync'])->middleware('workspace:back-office');
    Route::delete('/products/{productId}/taxes/{productTaxId}', [ProductTaxController::class, 'destroy'])->middleware('workspace:back-office');
    Route::post('/products/{productId}/taxes/preview', [ProductTaxController::class, 'calculatePreview'])->middleware('workspace:back-office');

    Route::middleware(['auth:sanctum', 'workspace:back-office'])->prefix('purchases')->group(function () {
        Route::get('/overview', [PurchaseController::class, 'overview'])->middleware('company.permission:purchase_orders,view_purchase_orders_page,view_purchase_receipts,supplier_invoices');
        Route::get('/orders', [PurchaseController::class, 'orders'])->middleware('company.permission:purchase_orders,view_purchase_orders_page');
        Route::post('/orders', [PurchaseController::class, 'storeOrder'])->middleware('company.permission:purchase_orders');
        Route::get('/orders/{id}', [PurchaseController::class, 'showOrder'])->middleware('company.permission:purchase_orders,view_purchase_orders_page');
        Route::get('/orders/{id}/pdf', [PurchaseController::class, 'orderPdf'])->middleware('company.permission:purchase_orders,view_purchase_orders_page');
        Route::put('/orders/{id}', [PurchaseController::class, 'updateOrder'])->middleware('company.permission:purchase_orders');
        Route::post('/orders/{purchaseOrderId}/receive', [PurchaseController::class, 'receiveOrder'])
            ->middleware([
                'company.permission:receive_stock,purchase_orders',
                BlockHospitalityInventoryWhenDayEndRunning::class,
            ]);

        Route::get('/receipts', [PurchaseController::class, 'receipts'])->middleware('company.permission:view_purchase_receipts,receive_stock,purchase_orders');
        Route::post('/receipts', [PurchaseController::class, 'receiveOrder'])
            ->middleware([
                'company.permission:receive_stock,purchase_orders',
                BlockHospitalityInventoryWhenDayEndRunning::class,
            ]);
        Route::get('/receipts/{id}', [PurchaseController::class, 'showReceipt'])->middleware('company.permission:view_purchase_receipts,receive_stock,purchase_orders');
        Route::get('/receipts/{id}/pdf', [PurchaseController::class, 'receiptPdf'])->middleware('company.permission:view_purchase_receipts,receive_stock,purchase_orders');

        Route::get('/returns', [PurchaseController::class, 'returns'])->middleware('company.permission:purchase_returns,view_purchase_returns_page');
        Route::post('/returns', [PurchaseController::class, 'storeReturn'])
            ->middleware([
                'company.permission:purchase_returns',
                BlockHospitalityInventoryWhenDayEndRunning::class,
            ]);
        Route::get('/returns/{id}', [PurchaseController::class, 'showReturn'])->middleware('company.permission:purchase_returns,view_purchase_returns_page');
        Route::get('/returns/{id}/pdf', [PurchaseController::class, 'returnPdf'])->middleware('company.permission:purchase_returns,view_purchase_returns_page');

        Route::get('/supplier-invoices', [PurchaseController::class, 'invoices'])->middleware('company.permission:supplier_invoices,view_supplier_invoices_page');
        Route::post('/supplier-invoices', [PurchaseController::class, 'storeSupplierInvoice'])->middleware('company.permission:supplier_invoices');
        Route::get('/supplier-invoices/{id}', [PurchaseController::class, 'showInvoice'])->middleware('company.permission:supplier_invoices,view_supplier_invoices_page');
        Route::get('/supplier-invoices/{id}/pdf', [PurchaseController::class, 'supplierInvoicePdf'])->middleware('company.permission:supplier_invoices,view_supplier_invoices_page');
        Route::get('/supplier-payments', [PurchaseController::class, 'supplierPayments'])->middleware('company.permission:view_supplier_payments,supplier_payments,supplier_invoices');
        Route::post('/supplier-payments', [PurchaseController::class, 'storeSupplierPayment'])->middleware('company.permission:supplier_payments,supplier_invoices');
        Route::post('/supplier-payments/{id}/reverse', [PurchaseController::class, 'reverseSupplierPayment'])->middleware('company.permission:reverse_supplier_payments,supplier_payments');
        Route::get('/reports/{type}/export/{format}', [PurchaseController::class, 'purchaseRegisterExport'])
            ->whereIn('type', ['orders', 'receipts', 'returns', 'invoices', 'payments'])
            ->whereIn('format', ['xlsx', 'pdf'])
            ->middleware('company.permission:purchase_orders,view_purchase_orders_page,view_purchase_receipts,purchase_returns,view_purchase_returns_page,supplier_invoices,view_supplier_invoices_page,view_supplier_payments,supplier_payments');
    });

});

Route::middleware([

    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
])->prefix('pos')->group(function () {

    Route::get('/assets/barcode-labels/{companyId}/{fileName}', [ProductBarcodePrintController::class, 'serveBarcodeLabel'])
        ->where('fileName', '.*');

});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:front-office,back-office',
    'business.mode:hospitality',
])->prefix('pos')->group(function () {

    Route::get('/trading-day/current', [TradingDayEndController::class, 'current']);

});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:front-office',
    ResolveTillFromToken::class,
    'business.mode:hospitality',
    'company.permission:general_procedure',
])->prefix('pos/front')->group(function () {
    Route::get('/trading-day/day-end/preflight', [TradingDayEndController::class, 'preflight']);
    Route::get('/trading-day/day-end/progress/{runKey}', [TradingDayEndController::class, 'progress']);
    Route::post('/trading-day/day-end', [TradingDayEndController::class, 'runDayEnd']);
    Route::post('/trading-day/day-end/catch-up', [TradingDayEndController::class, 'catchUpToSystemDate']);
    Route::post('/trading-day/day-end/rollback', [TradingDayEndController::class, 'rollbackRunning']);
    Route::post('/trading-day/day-end/force-front-stock-closing', [TradingDayEndController::class, 'forceMissingFrontStockClosings']);
});

Route::middleware([
    'auth:sanctum',
    ResolveCompanyFromDomain::class,
    CheckCompanyLicense::class,
    'workspace:back-office',
    'business.mode:hospitality',
])->prefix('pos')->group(function () {

    Route::get('/trading-day/day-end/preflight', [TradingDayEndController::class, 'preflight']);
    Route::get('/trading-day/day-end/progress/{runKey}', [TradingDayEndController::class, 'progress']);

    Route::middleware('company.permission:general_procedure')->group(function () {
        Route::post('/trading-day/day-end', [TradingDayEndController::class, 'runDayEnd']);
        Route::post('/trading-day/day-end/catch-up', [TradingDayEndController::class, 'catchUpToSystemDate']);
        Route::post('/trading-day/day-end/rollback', [TradingDayEndController::class, 'rollbackRunning']);
        Route::post('/trading-day/day-end/force-front-stock-closing', [TradingDayEndController::class, 'forceMissingFrontStockClosings']);
    });

    /*
    |--------------------------------------------------------------------------
    | Menu Recipe DSR
    |--------------------------------------------------------------------------
    | Important:
    | Put /live routes BEFORE /{id} routes.
    */
    Route::get('/reports/menu-recipe-daily-stock-reconciliation/live', [MenuRecipeDailyStockReconciliationController::class, 'live']);

    Route::get('/reports/menu-recipe-daily-stock-reconciliation/live/excel', [MenuRecipeDailyStockReconciliationController::class, 'downloadLiveExcel']);

    Route::get('/reports/menu-recipe-daily-stock-reconciliation/live/pdf', [MenuRecipeDailyStockReconciliationController::class, 'downloadLivePdf']);

    Route::get('/reports/menu-recipe-daily-stock-reconciliation', [MenuRecipeDailyStockReconciliationController::class, 'listSaved']);

    Route::get('/reports/menu-recipe-daily-stock-reconciliation/{id}', [MenuRecipeDailyStockReconciliationController::class, 'showSaved'])
        ->whereUuid('id');

    Route::get('/reports/menu-recipe-daily-stock-reconciliation/{id}/excel', [MenuRecipeDailyStockReconciliationController::class, 'downloadExcel'])
        ->whereUuid('id');

    Route::get('/reports/menu-recipe-daily-stock-reconciliation/{id}/pdf', [MenuRecipeDailyStockReconciliationController::class, 'downloadPdf'])
        ->whereUuid('id');

    Route::get('/reports/closing-stock-movements/closings', [ClosingStockMovementReportController::class, 'closings']);
    Route::get('/reports/closing-stock-movements/preview', [ClosingStockMovementReportController::class, 'preview']);
    Route::get('/reports/closing-stock-movements/excel', [ClosingStockMovementReportController::class, 'downloadExcel']);
    Route::get('/reports/closing-stock-movements/pdf', [ClosingStockMovementReportController::class, 'downloadPdf']);

    //    Route::get('/reports/stock-issued-to-front/preview', [StockIssuedToFrontReportController::class, 'preview']);
    //    Route::get('/reports/stock-issued-to-front/excel', [StockIssuedToFrontReportController::class, 'downloadExcel']);
    //    Route::get('/reports/stock-issued-to-front/pdf', [StockIssuedToFrontReportController::class, 'downloadPdf']);

    Route::get('stock/issue-enterprise/report', [StockIssuedToFrontReportController::class, 'preview']);
    Route::get('stock/issue-enterprise/report/excel', [StockIssuedToFrontReportController::class, 'downloadExcel']);
    Route::get('stock/issue-enterprise/report/pdf', [StockIssuedToFrontReportController::class, 'downloadPdf']);

    // Optional backwards compatible routes if your frontend still points here.
    Route::get('reports/stock-issued-to-front/preview', [StockIssuedToFrontReportController::class, 'preview']);
    Route::get('reports/stock-issued-to-front/excel', [StockIssuedToFrontReportController::class, 'downloadExcel']);
    Route::get('reports/stock-issued-to-front/pdf', [StockIssuedToFrontReportController::class, 'downloadPdf']);

    Route::get('/stock-wastages/products', [StockWastageController::class, 'productLookup']);
    Route::post('/stock-wastages', [StockWastageController::class, 'store'])
        ->middleware(BlockHospitalityInventoryWhenDayEndRunning::class);
    Route::get('/stock-wastages/{id}', [StockWastageController::class, 'show'])->whereUuid('id');

    Route::get('/reports/stock-wastages/preview', [StockWastageController::class, 'index']);
    Route::get('/reports/stock-wastages/excel', [StockWastageController::class, 'downloadExcel']);
    Route::get('/reports/stock-wastages/pdf', [StockWastageController::class, 'downloadPdf']);

});

/*
|--------------------------------------------------------------------------
| Public asset serving route for barcode PDFs
|--------------------------------------------------------------------------
| Put this in the API routes file so the URL becomes:
| http://localhost/salexaapi/api/assets/barcode-labels/{company_id}/{file}.pdf
|
| IMPORTANT:
| If your /api/pos group has auth middleware and this route is inside it,
| QZ may fail because QZ cannot send your Bearer token when fetching the PDF.
| Best option: put this specific route outside auth, but keep company id in URL.
*/
