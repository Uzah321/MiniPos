<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanySettingService;
use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Product\Models\PriceLevel;

class InventoryReportDownloadController extends Controller
{
    private const PAGE_SIZE = 500;
    private const MAX_PAGES = 500;

    public function stockReport(
        Request $request,
        string $report,
        string $format,
        EnterpriseReportDownloadService $downloads
    ) {
        $format = $this->normaliseFormat($format);

        if ($report === 'dashboard') {
            return $this->downloadStockDashboard($request, $format, $downloads);
        }

        $definition = $this->stockReportDefinitions()[$report] ?? null;
        abort_unless($definition, 404);

        $controller = app(StockReportController::class);
        [$records, $firstPayload] = $this->collectPaginatedRows(
            $request,
            fn (Request $pageRequest) => $controller->{$definition['method']}($pageRequest)
        );

        $detailRows = array_map($definition['map'], $records);
        $sections = $this->filterSections($request);
        $summary = Arr::get($firstPayload, 'summary');

        if (is_array($summary) && $summary !== []) {
            $sections[] = [
                'title' => 'Summary',
                'headers' => ['Metric', 'Value'],
                'rows' => $this->summaryRows($summary, $report),
            ];
        }

        $sections[] = [
            'title' => 'Details',
            'headers' => $definition['headers'],
            'rows' => $detailRows,
        ];

        return $this->download(
            $downloads,
            $format,
            $definition['title'],
            $report . '-' . now()->format('Y-m-d-His'),
            $sections
        );
    }

    public function productPriceList(
        Request $request,
        string $format,
        EnterpriseReportDownloadService $downloads
    ) {
        $format = $this->normaliseFormat($format);
        $controller = app(PriceController::class);

        [$products] = $this->collectPaginatedRows(
            $request,
            fn (Request $pageRequest) => $controller->productPriceListForLoggedInBranch($pageRequest)
        );

        $company = app('company');
        $levels = PriceLevel::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name']);

        $headers = [
            'Product', 'Type', 'Code', 'SKU', 'Barcode', 'Category', 'Supplier', 'Status',
        ];

        foreach ($levels as $level) {
            $headers[] = $level->name;
            $headers[] = $level->name . ' Scope';
            $headers[] = $level->name . ' Default';
        }

        $rows = array_map(function (array $product) use ($levels) {
            $row = [
                $this->value($product, 'name'),
                $this->value($product, 'product_type'),
                $this->value($product, 'code'),
                $this->value($product, 'sku'),
                $this->value($product, 'barcode'),
                $this->value($product, 'category.name'),
                $this->value($product, 'supplier.name'),
                Arr::get($product, 'is_active', true) === false ? 'Inactive' : 'Active',
            ];

            $prices = collect(Arr::get($product, 'prices', []));
            foreach ($levels as $level) {
                $price = $prices->first(
                    fn ($candidate) => (string) Arr::get($candidate, 'price_level_id') === (string) $level->id
                );

                if (!$price) {
                    array_push($row, '-', '-', '-');
                    continue;
                }

                $currency = Arr::get($price, 'currency_code') ?: Arr::get($price, 'currency') ?: 'USD';
                $branchName = Arr::get($price, 'branch.branch_name') ?: Arr::get($price, 'branch.name');
                $scope = $branchName ?: (Arr::get($price, 'branch_id') ? 'Branch' : 'Company');

                $row[] = $this->money(Arr::get($price, 'price'), $currency);
                $row[] = $scope;
                $row[] = Arr::get($price, 'is_default') ? 'Yes' : 'No';
            }

            return $row;
        }, $products);

        $sections = array_merge($this->filterSections($request), [[
            'title' => 'Product Price List',
            'headers' => $headers,
            'rows' => $rows,
        ]]);

        return $this->download(
            $downloads,
            $format,
            'Product Price List',
            'product-price-list-' . now()->format('Y-m-d-His'),
            $sections
        );
    }

    public function productPriceAdjustmentHistory(
        Request $request,
        string $productId,
        string $format,
        EnterpriseReportDownloadService $downloads
    ) {
        $format = $this->normaliseFormat($format);
        $controller = app(PriceController::class);

        [$records] = $this->collectPaginatedRows(
            $request,
            fn (Request $pageRequest) => $controller->productPriceAdjustmentHistory(
                $pageRequest,
                $productId
            )
        );

        $rows = array_map(function (array $record) {
            $currency = strtoupper((string) (Arr::get($record, 'currency') ?: '-'));
            $oldPrice = Arr::get($record, 'old_price');
            $newPrice = Arr::get($record, 'new_price');
            $difference = $oldPrice === null || $oldPrice === ''
                ? null
                : (float) $newPrice - (float) $oldPrice;
            $adjustedBy = Arr::get($record, 'adjudtedby', Arr::get($record, 'adjusted_by_user', []));

            return [
                $this->dateTime(Arr::get($record, 'adjusted_at') ?: Arr::get($record, 'created_at')),
                $this->value($record, 'metadata.product_name'),
                $this->value($record, 'metadata.product_code'),
                $this->value($record, 'price_level.name', $this->value($record, 'metadata.price_level_name')),
                $oldPrice === null || $oldPrice === '' ? '-' : $this->quantity($oldPrice),
                $this->quantity($newPrice),
                $difference === null ? 'New price' : $this->quantity($difference),
                $currency,
                $this->value($record, 'adjustment_type', 'manual'),
                $this->value($record, 'reason', 'No reason captured'),
                $this->userName($adjustedBy),
                $this->userDetails($adjustedBy),
                $this->value($record, 'branch_id', $this->value($record, 'metadata.branch_id')),
            ];
        }, $records);

        $firstRecord = $records[0] ?? [];
        $sections = array_merge($this->filterSections($request), [[
            'title' => 'Report Context',
            'headers' => ['Field', 'Value'],
            'rows' => [
                ['Product', $this->value($firstRecord, 'metadata.product_name')],
                ['Product Code', $this->value($firstRecord, 'metadata.product_code')],
                ['Product ID', $productId],
                ['Branch ID', $this->value($firstRecord, 'branch_id', $this->value($firstRecord, 'metadata.branch_id'))],
            ],
        ], [
            'title' => 'Price Adjustment History',
            'headers' => [
                'Date / Time', 'Product', 'Product Code', 'Price Level', 'Old Price',
                'New Price', 'Change', 'Currency', 'Type', 'Reason', 'Adjusted By',
                'User Details', 'Branch ID',
            ],
            'rows' => $rows,
        ]]);

        return $this->download(
            $downloads,
            $format,
            'Product Price Adjustment History',
            'product-price-adjustment-history-' . now()->format('Y-m-d-His'),
            $sections,
            'a3',
            true
        );
    }

    public function stockAdjustmentHistory(
        Request $request,
        string $format,
        EnterpriseReportDownloadService $downloads
    ) {
        $format = $this->normaliseFormat($format);
        $controller = app(ProductController::class);

        [$records] = $this->collectPaginatedRows(
            $request,
            fn (Request $pageRequest) => $controller->stockAdjustmentHistory($pageRequest)
        );

        $rows = array_map(function (array $record) {
            $quantityIn = (float) (Arr::get($record, 'quantity_in') ?? 0);
            $quantityOut = (float) (Arr::get($record, 'quantity_out') ?? 0);
            $createdBy = Arr::get($record, 'created_by', Arr::get($record, 'created_by_user', []));
            $uomName = $this->value($record, 'product.uom.name');
            $uomCode = Arr::get($record, 'product.uom.code');
            $uom = $uomCode && $uomName !== '-'
                ? $uomName . ' (' . $uomCode . ')'
                : ($uomCode ?: $uomName);

            return [
                $this->dateTime(Arr::get($record, 'created_at')),
                $this->value($record, 'product.name', $this->value($record, 'metadata.product_name')),
                $this->value($record, 'product.barcode', $this->value($record, 'metadata.barcode')),
                $this->value($record, 'product.sku', $this->value($record, 'metadata.sku')),
                $this->value($record, 'product.code', $this->value($record, 'metadata.product_code')),
                $uom,
                $this->value($record, 'stock_area'),
                $quantityIn > 0 ? 'Increase' : ($quantityOut > 0 ? 'Decrease' : 'No change'),
                $this->quantity($quantityIn),
                $this->quantity($quantityOut),
                $this->quantity($quantityIn - $quantityOut),
                $this->quantity(Arr::get($record, 'average_cost')),
                $this->quantity(Arr::get($record, 'cost_value')),
                strtoupper((string) (Arr::get($record, 'cost_currency_code') ?: '-')),
                $this->value($record, 'branch.branch_name', $this->value($record, 'branch.name')),
                $this->userName($createdBy),
                $this->userDetails($createdBy),
                $this->value($record, 'notes'),
            ];
        }, $records);

        $sections = array_merge($this->filterSections($request), [[
            'title' => 'Stock Adjustment History',
            'headers' => [
                'Date / Time', 'Product', 'Barcode', 'SKU', 'Product Code', 'UOM',
                'Stock Area', 'Type', 'Quantity In', 'Quantity Out', 'Difference',
                'Average Cost', 'Average Cost Value', 'Cost Currency', 'Branch', 'Adjusted By',
                'User Details', 'Notes',
            ],
            'rows' => $rows,
        ]]);

        return $this->download(
            $downloads,
            $format,
            'Stock Adjustment History',
            'stock-adjustment-history-' . now()->format('Y-m-d-His'),
            $sections,
            'a3',
            true
        );
    }

    public function stockArea(
        Request $request,
        string $area,
        string $format,
        EnterpriseReportDownloadService $downloads,
        CompanySettingService $settings
    ) {
        abort_unless(in_array($area, ['front', 'back'], true), 404);
        $format = $this->normaliseFormat($format);
        $controller = app(ProductController::class);

        [$records] = $this->collectPaginatedRows(
            $request,
            fn (Request $pageRequest) => $area === 'back'
                ? $controller->backStock($pageRequest, $settings)
                : $controller->frontStock($pageRequest)
        );

        $areaLabel = $area === 'back' ? 'Back Stock' : 'Front Stock';
        $rows = [];

        foreach ($records as $index => $record) {
            $currency = Arr::get($record, 'currency')
                ?: Arr::get($record, 'cost.currency_code')
                ?: Arr::get($record, 'cost.currency')
                ?: Arr::get($record, 'product.currency');

            $rows[] = [
                $index + 1,
                $areaLabel,
                $this->value($record, 'branch.branch_name', $this->value($record, 'branch.name')),
                $this->value($record, 'product.name', $this->value($record, 'product_name')),
                Arr::get($record, 'product.code')
                    ?: Arr::get($record, 'product.sku')
                    ?: Arr::get($record, 'product.barcode')
                    ?: Arr::get($record, 'product_code')
                    ?: '-',
                $this->value($record, 'product.product_type', $this->value($record, 'product_type')),
                $this->value($record, 'product.category.name', $this->value($record, 'category.name')),
                $this->uom($record),
                $this->quantity(Arr::get($record, 'quantity_on_hand')),
                $this->quantity(Arr::get($record, 'reserved_quantity')),
                $this->quantity(Arr::get($record, 'available_quantity')),
                $this->money(Arr::get($record, 'average_cost', Arr::get($record, 'cost.average_cost')), $currency),
                $this->money(Arr::get($record, 'last_cost', Arr::get($record, 'cost.last_cost')), $currency),
                $this->money(Arr::get($record, 'total_value', Arr::get($record, 'cost.total_value')), $currency),
                $this->quantity(Arr::get($record, 'product.reorder_level', Arr::get($record, 'reorder_level'))),
                Arr::get($record, 'product.is_active', true) === false ? 'Inactive' : 'Active',
            ];
        }

        $sections = array_merge($this->filterSections($request), [[
            'title' => $areaLabel . ' Register',
            'headers' => [
                'Line', 'Area', 'Branch', 'Product', 'Code', 'Type', 'Category', 'UOM',
                'On Hand', 'Reserved', 'Available', 'Average Cost', 'Last Cost', 'Total Value',
                'Reorder Level', 'Status',
            ],
            'rows' => $rows,
        ]]);

        return $this->download(
            $downloads,
            $format,
            $areaLabel . ' Register',
            Str::slug($areaLabel) . '-' . now()->format('Y-m-d-His'),
            $sections
        );
    }

    private function downloadStockDashboard(
        Request $request,
        string $format,
        EnterpriseReportDownloadService $downloads
    ) {
        $response = app(StockReportController::class)->dashboard(clone $request);
        $payload = $this->responsePayload($response);
        $dashboard = Arr::get($payload, 'data', []);
        $sections = $this->filterSections($request);

        $sections[] = [
            'title' => 'Summary',
            'headers' => ['Metric', 'Value'],
            'rows' => $this->summaryRows(Arr::get($dashboard, 'summary', []), 'dashboard'),
        ];
        $sections[] = [
            'title' => 'Stock Value at Average Cost by Branch',
            'headers' => ['Branch', 'Products', 'On Hand', 'Available', 'Stock Value at Average Cost', 'Currency'],
            'rows' => array_map(fn ($row) => [
                $this->value($row, 'branch_name'),
                Arr::get($row, 'product_count', 0),
                $this->quantity(Arr::get($row, 'quantity_on_hand')),
                $this->quantity(Arr::get($row, 'available_quantity')),
                $this->money(Arr::get($row, 'stock_value'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency') ?: '-',
            ], Arr::get($dashboard, 'stock_by_branch', [])),
        ];
        $sections[] = [
            'title' => 'Stock Value at Average Cost by Category',
            'headers' => ['Category', 'Products', 'On Hand', 'Available', 'Stock Value at Average Cost', 'Currency'],
            'rows' => array_map(fn ($row) => [
                $this->value($row, 'category_name'),
                Arr::get($row, 'product_count', 0),
                $this->quantity(Arr::get($row, 'quantity_on_hand')),
                $this->quantity(Arr::get($row, 'available_quantity')),
                $this->money(Arr::get($row, 'stock_value'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency') ?: '-',
            ], Arr::get($dashboard, 'stock_by_category', [])),
        ];
        $sections[] = [
            'title' => 'Low Stock Alerts',
            'headers' => ['Branch', 'Product', 'SKU', 'Category', 'On Hand', 'Reorder Level'],
            'rows' => array_map(fn ($row) => [
                $this->value($row, 'branch_name'), $this->value($row, 'product_name'),
                Arr::get($row, 'sku') ?: Arr::get($row, 'code') ?: '-',
                $this->value($row, 'category_name'), $this->quantity(Arr::get($row, 'quantity_on_hand')),
                $this->quantity(Arr::get($row, 'reorder_level')),
            ], Arr::get($dashboard, 'low_stock', [])),
        ];
        $sections[] = [
            'title' => 'Negative Stock Alerts',
            'headers' => ['Branch', 'Product', 'SKU', 'Category', 'On Hand'],
            'rows' => array_map(fn ($row) => [
                $this->value($row, 'branch_name'), $this->value($row, 'product_name'),
                Arr::get($row, 'sku') ?: Arr::get($row, 'code') ?: '-',
                $this->value($row, 'category_name'), $this->quantity(Arr::get($row, 'quantity_on_hand')),
            ], Arr::get($dashboard, 'negative_stock', [])),
        ];
        $sections[] = [
            'title' => 'Recent Stock Movements',
            'headers' => ['Date', 'Branch', 'Warehouse', 'Product', 'Movement', 'In', 'Out', 'Balance', 'Unit Cost', 'Total Cost', 'Currency', 'User'],
            'rows' => array_map(fn ($row) => $this->movementRow($row, false), Arr::get($dashboard, 'recent_movements', [])),
        ];

        return $this->download(
            $downloads,
            $format,
            'Stock Dashboard',
            'stock-dashboard-' . now()->format('Y-m-d-His'),
            $sections
        );
    }

    private function stockReportDefinitions(): array
    {
        return [
            'company-balance' => [
                'title' => 'Company Stock Balance',
                'method' => 'companyBalance',
                'headers' => ['Product', 'SKU', 'Code', 'Category', 'Supplier', 'On Hand', 'Reserved', 'Available', 'Average Cost', 'Stock Value at Average Cost', 'Currency'],
                'map' => fn ($row) => $this->stockBalanceRow($row, false),
            ],
            'branch-balance' => [
                'title' => 'Branch Stock Balance',
                'method' => 'branchBalance',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'On Hand', 'Reserved', 'Available', 'Average Cost', 'Stock Value at Average Cost', 'Currency'],
                'map' => fn ($row) => $this->stockBalanceRow($row, true),
            ],
            'current-balance' => [
                'title' => 'Current Stock Balance',
                'method' => 'balance',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'Total In', 'Total Out', 'On Hand', 'Average Cost', 'Stock Value at Average Cost', 'Last Movement', 'Currency'],
                'map' => fn ($row) => $this->currentBalanceRow($row),
            ],
            'as-of' => [
                'title' => 'Stock Balance As-Of',
                'method' => 'asOfBalance',
                'headers' => ['As Of', 'Branch', 'Product', 'SKU', 'Code', 'Category', 'Quantity As Of', 'Average Cost', 'Stock Value at Average Cost', 'Last Movement', 'Currency'],
                'map' => fn ($row) => [
                    $this->value($row, 'as_of_date'), $this->value($row, 'branch_name'),
                    $this->value($row, 'product_name'), $this->value($row, 'sku'),
                    $this->value($row, 'code'), $this->value($row, 'category_name'),
                    $this->quantity(Arr::get($row, 'quantity_as_of')),
                    $this->money(Arr::get($row, 'average_cost'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                    $this->money(Arr::get($row, 'stock_value'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                    $this->dateTime(Arr::get($row, 'last_movement_at')),
                    Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency') ?: '-',
                ],
            ],
            'movements' => [
                'title' => 'Stock Movements',
                'method' => 'movements',
                'headers' => ['Date', 'Branch', 'Warehouse', 'Product', 'SKU', 'Movement', 'In', 'Out', 'Balance', 'Unit Cost', 'Total Cost', 'Currency', 'Reference', 'User'],
                'map' => fn ($row) => $this->movementRow($row, true),
            ],
            'received' => [
                'title' => 'Stock Received Tracking',
                'method' => 'received',
                'headers' => ['Date', 'Branch', 'Warehouse', 'Product', 'SKU', 'Supplier', 'Movement', 'Qty In', 'Unit Cost', 'Recorded Cost Value', 'Currency', 'Reference'],
                'map' => fn ($row) => $this->receivedRow($row),
            ],
            'adjustments' => [
                'title' => 'Stock Adjustment Tracking',
                'method' => 'adjustments',
                'headers' => ['Date', 'Branch', 'Warehouse', 'Product', 'SKU', 'Movement', 'Increased Qty', 'Decreased Qty', 'Average Cost', 'Average Cost Value', 'Currency', 'User', 'Notes'],
                'map' => fn ($row) => $this->adjustmentRow($row),
            ],
            'valuation' => [
                'title' => 'Stock Valuation',
                'method' => 'valuation',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'On Hand', 'Average Cost', 'Stock Value at Average Cost', 'Currency'],
                'map' => fn ($row) => $this->valuationRow($row),
            ],
            'low-stock' => [
                'title' => 'Low Stock',
                'method' => 'lowStock',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'Supplier', 'On Hand', 'Reorder Level'],
                'map' => fn ($row) => $this->alertRow($row, true),
            ],
            'out-of-stock' => [
                'title' => 'Out Of Stock',
                'method' => 'outOfStock',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'Supplier', 'On Hand'],
                'map' => fn ($row) => $this->alertRow($row, false),
            ],
            'negative-stock' => [
                'title' => 'Negative Stock',
                'method' => 'negativeStock',
                'headers' => ['Branch', 'Product', 'SKU', 'Code', 'Category', 'Supplier', 'On Hand'],
                'map' => fn ($row) => $this->alertRow($row, false),
            ],
            'category-summary' => [
                'title' => 'Category Stock Summary',
                'method' => 'categorySummary',
                'headers' => ['Category', 'Products', 'On Hand', 'Reserved', 'Available', 'Stock Value at Average Cost', 'Currency'],
                'map' => fn ($row) => [
                    $this->value($row, 'category_name'), Arr::get($row, 'product_count', 0),
                    $this->quantity(Arr::get($row, 'quantity_on_hand')),
                    $this->quantity(Arr::get($row, 'reserved_quantity')),
                    $this->quantity(Arr::get($row, 'available_quantity')),
                    $this->money(Arr::get($row, 'stock_value'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                    Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency') ?: '-',
                ],
            ],
            'supplier-summary' => [
                'title' => 'Supplier Stock Summary',
                'method' => 'supplierSummary',
                'headers' => ['Supplier', 'Products', 'On Hand', 'Reserved', 'Available', 'Stock Value at Average Cost', 'Currency'],
                'map' => fn ($row) => [
                    $this->value($row, 'supplier_name'), Arr::get($row, 'product_count', 0),
                    $this->quantity(Arr::get($row, 'quantity_on_hand')),
                    $this->quantity(Arr::get($row, 'reserved_quantity')),
                    $this->quantity(Arr::get($row, 'available_quantity')),
                    $this->money(Arr::get($row, 'stock_value'), Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency')),
                    Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency') ?: '-',
                ],
            ],
        ];
    }

    private function stockBalanceRow(array $row, bool $withBranch): array
    {
        $costCurrency = Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');
        $values = [
            $this->value($row, 'product_name'), $this->value($row, 'sku'),
            $this->value($row, 'code'), $this->value($row, 'category_name'),
        ];

        if ($withBranch) {
            array_unshift($values, $this->value($row, 'branch_name'));
        } else {
            $values[] = $this->value($row, 'supplier_name');
        }

        return array_merge($values, [
            $this->quantity(Arr::get($row, 'quantity_on_hand')),
            $this->quantity(Arr::get($row, 'reserved_quantity')), $this->quantity(Arr::get($row, 'available_quantity')),
            $this->money(Arr::get($row, 'average_cost'), $costCurrency),
            $this->money(Arr::get($row, 'stock_value'), $costCurrency),
            $costCurrency ?: '-',
        ]);
    }

    private function currentBalanceRow(array $row): array
    {
        $costCurrency = Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');

        return [
            $this->value($row, 'branch_name'), $this->value($row, 'product_name'),
            $this->value($row, 'sku'), $this->value($row, 'code'), $this->value($row, 'category_name'),
            $this->quantity(Arr::get($row, 'total_in')), $this->quantity(Arr::get($row, 'total_out')),
            $this->quantity(Arr::get($row, 'quantity_on_hand')),
            $this->money(Arr::get($row, 'average_cost'), $costCurrency),
            $this->money(Arr::get($row, 'stock_value'), $costCurrency),
            $this->dateTime(Arr::get($row, 'last_movement_at')),
            $costCurrency ?: '-',
        ];
    }

    private function movementRow(array $row, bool $includeReference): array
    {
        $currency = Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');
        $values = [
            $this->dateTime(Arr::get($row, 'movement_date') ?: Arr::get($row, 'created_at')),
            $this->value($row, 'branch_name'), $this->value($row, 'warehouse_name', 'Unassigned'),
            $this->value($row, 'product_name'),
        ];

        if ($includeReference) {
            $values[] = Arr::get($row, 'sku') ?: Arr::get($row, 'code') ?: '-';
        }

        array_push(
            $values,
            $this->value($row, 'movement_type'),
            $this->quantity(Arr::get($row, 'quantity_in')),
            $this->quantity(Arr::get($row, 'quantity_out')),
            $this->quantity(Arr::get($row, 'balance_after')),
            $this->money(Arr::get($row, 'unit_cost'), $currency),
            $this->money(Arr::get($row, 'total_cost'), $currency),
            $currency ?: '-'
        );

        if ($includeReference) {
            $values[] = $this->value($row, 'reference_type');
        }
        $values[] = Arr::get($row, 'created_by_name') ?: Arr::get($row, 'user_name') ?: '-';

        return $values;
    }

    private function receivedRow(array $row): array
    {
        $costCurrency = Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');

        return [
            $this->dateTime(Arr::get($row, 'movement_date') ?: Arr::get($row, 'received_date')),
            $this->value($row, 'branch_name'), $this->value($row, 'warehouse_name', 'Unassigned'),
            $this->value($row, 'product_name'), Arr::get($row, 'sku') ?: Arr::get($row, 'code') ?: '-',
            $this->value($row, 'supplier_name'), Arr::get($row, 'movement_type') ?: 'receive',
            $this->quantity(Arr::get($row, 'quantity_in') ?: Arr::get($row, 'increased_quantity') ?: Arr::get($row, 'quantity')),
            $this->money(Arr::get($row, 'unit_cost'), $costCurrency),
            $this->money(Arr::get($row, 'total_cost') ?? Arr::get($row, 'line_total'), $costCurrency),
            $costCurrency ?: '-',
            Arr::get($row, 'reference_type') ?: Arr::get($row, 'receipt_number') ?: '-',
        ];
    }

    private function adjustmentRow(array $row): array
    {
        $costCurrency = Arr::get($row, 'cost_currency_code') ?: Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');

        return [
            $this->dateTime(Arr::get($row, 'movement_date') ?: Arr::get($row, 'adjustment_date') ?: Arr::get($row, 'created_at')),
            $this->value($row, 'branch_name'), $this->value($row, 'warehouse_name', 'Unassigned'),
            $this->value($row, 'product_name'), Arr::get($row, 'sku') ?: Arr::get($row, 'code') ?: '-',
            Arr::get($row, 'movement_type') ?: Arr::get($row, 'adjustment_type') ?: '-',
            $this->quantity(Arr::get($row, 'increased_quantity', Arr::get($row, 'quantity_in'))),
            $this->quantity(Arr::get($row, 'decreased_quantity', Arr::get($row, 'quantity_out'))),
            $this->money(Arr::get($row, 'average_cost'), $costCurrency),
            $this->money(Arr::get($row, 'cost_value'), $costCurrency),
            $costCurrency ?: '-',
            Arr::get($row, 'created_by_name') ?: Arr::get($row, 'adjusted_by_name') ?: Arr::get($row, 'user_name') ?: '-',
            Arr::get($row, 'notes') ?: Arr::get($row, 'reason') ?: '-',
        ];
    }

    private function valuationRow(array $row): array
    {
        $costCurrency = Arr::get($row, 'currency_code') ?: Arr::get($row, 'currency');

        return [
            $this->value($row, 'branch_name'), $this->value($row, 'product_name'),
            $this->value($row, 'sku'), $this->value($row, 'code'), $this->value($row, 'category_name'),
            $this->quantity(Arr::get($row, 'quantity_on_hand')),
            $this->money(Arr::get($row, 'average_cost'), $costCurrency),
            $this->money(Arr::get($row, 'stock_value'), $costCurrency),
            $costCurrency ?: '-',
        ];
    }

    private function alertRow(array $row, bool $includeReorder): array
    {
        $values = [
            $this->value($row, 'branch_name'), $this->value($row, 'product_name'),
            $this->value($row, 'sku'), $this->value($row, 'code'),
            $this->value($row, 'category_name'), $this->value($row, 'supplier_name'),
            $this->quantity(Arr::get($row, 'quantity_on_hand')),
        ];

        if ($includeReorder) {
            $values[] = $this->quantity(Arr::get($row, 'reorder_level'));
        }

        return $values;
    }

    private function collectPaginatedRows(Request $request, callable $loader): array
    {
        $rows = [];
        $firstPayload = [];
        $page = 1;

        do {
            $pageRequest = clone $request;
            $pageRequest->merge(['page' => $page, 'per_page' => self::PAGE_SIZE]);
            $payload = $this->responsePayload($loader($pageRequest));

            if ($page === 1) {
                $firstPayload = $payload;
            }

            $paginator = Arr::get($payload, 'data', []);
            $pageRows = is_array($paginator) ? Arr::get($paginator, 'data', []) : [];
            $rows = array_merge($rows, is_array($pageRows) ? $pageRows : []);
            $lastPage = max(1, (int) Arr::get($paginator, 'last_page', 1));
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        return [$rows, $firstPayload];
    }

    private function responsePayload($response): array
    {
        if ($response instanceof Response || method_exists($response, 'getStatusCode')) {
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new HttpResponseException($response);
            }
        }

        if (method_exists($response, 'getData')) {
            return (array) $response->getData(true);
        }

        return [];
    }

    private function download(
        EnterpriseReportDownloadService $downloads,
        string $format,
        string $title,
        string $filename,
        array $sections,
        string|array $paper = 'a4',
        bool $compact = false
    ) {
        if ($format === 'xlsx') {
            return $downloads->downloadSpreadsheet($title, $filename . '.xlsx', $sections, true);
        }

        return $downloads->downloadPdf(
            $title,
            $filename . '.pdf',
            $sections,
            null,
            $paper,
            'landscape',
            $compact
        );
    }

    private function normaliseFormat(string $format): string
    {
        $normalised = strtolower($format) === 'excel' ? 'xlsx' : strtolower($format);
        abort_unless(in_array($normalised, ['xlsx', 'pdf'], true), 404);

        return $normalised;
    }

    private function filterSections(Request $request): array
    {
        $ignored = ['page', 'per_page'];
        $rows = [];

        foreach ($request->query() as $key => $value) {
            if (in_array($key, $ignored, true) || $value === null || $value === '') {
                continue;
            }

            $rows[] = [Str::headline($key), is_array($value) ? implode(', ', $value) : (string) $value];
        }

        return $rows === [] ? [] : [[
            'title' => 'Applied Filters',
            'headers' => ['Filter', 'Value'],
            'rows' => $rows,
        ]];
    }

    private function summaryRows(array $summary, ?string $report = null): array
    {
        $rows = [];
        foreach ($summary as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $rows[] = [
                $this->summaryLabel($key, $report),
                is_numeric($value) ? $this->quantity($value) : ($value ?? '-'),
            ];
        }

        foreach ((array) Arr::get($summary, 'currency_totals', []) as $currencyTotal) {
            $currencyTotal = (array) $currencyTotal;
            $currency = strtoupper((string) (
                Arr::get($currencyTotal, 'cost_currency_code')
                ?: Arr::get($currencyTotal, 'currency_code')
                ?: 'Unspecified Currency'
            ));

            foreach ($currencyTotal as $key => $value) {
                if (in_array($key, ['currency_code', 'cost_currency_code'], true)) {
                    continue;
                }

                $rows[] = [
                    $currency . ' · ' . $this->summaryLabel($key, $report),
                    is_numeric($value) ? $this->quantity($value) : ($value ?? '-'),
                ];
            }
        }

        return $rows;
    }

    private function summaryLabel(string $key, ?string $report = null): string
    {
        return match (true) {
            $key === 'stock_value' => 'Stock Value at Average Cost',
            $report === 'adjustments' && $key === 'cost_value' => 'Average Cost Value',
            $report === 'adjustments' && $key === 'increased_cost_value' => 'Increased Average Cost Value',
            $report === 'adjustments' && $key === 'decreased_cost_value' => 'Decreased Average Cost Value',
            $report === 'received' && $key === 'cost_value' => 'Recorded Cost Value',
            default => Str::headline($key),
        };
    }

    private function userName(mixed $user): string
    {
        if (!is_array($user)) {
            return '-';
        }

        return (string) (Arr::get($user, 'name') ?: Arr::get($user, 'username') ?: 'Unknown User');
    }

    private function userDetails(mixed $user): string
    {
        if (!is_array($user)) {
            return '-';
        }

        $details = array_values(array_filter([
            Arr::get($user, 'username'),
            Arr::get($user, 'employee_code'),
            Arr::get($user, 'user_type'),
        ], fn ($value) => $value !== null && $value !== ''));

        return $details === [] ? '-' : implode(' | ', $details);
    }

    private function value(array $row, string $path, string $fallback = '-'): string
    {
        $value = Arr::get($row, $path);
        return $value === null || $value === '' ? $fallback : (string) $value;
    }

    private function quantity($value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }

    private function money($value, $currency = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $prefix = $currency ? strtoupper((string) $currency) . ' ' : '';
        return $prefix . number_format((float) $value, 4, '.', '');
    }

    private function dateTime($value): string
    {
        return $value ? (string) $value : '-';
    }

    private function uom(array $row): string
    {
        $name = Arr::get($row, 'product.uom.name') ?: Arr::get($row, 'uom.name');
        $code = Arr::get($row, 'product.uom.code') ?: Arr::get($row, 'uom.code');

        if (!$name && !$code) {
            return '-';
        }

        return ($name ?: 'UOM') . ($code ? ' (' . $code . ')' : '');
    }
}
