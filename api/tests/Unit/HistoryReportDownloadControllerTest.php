<?php

namespace Tests\Unit;

use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Mockery;
use Modules\Product\Http\Controllers\InventoryReportDownloadController;
use Modules\Product\Http\Controllers\PriceController;
use Modules\Product\Http\Controllers\ProductController;
use Tests\TestCase;

class HistoryReportDownloadControllerTest extends TestCase
{
    public function test_price_history_export_reuses_every_page_from_the_secured_json_endpoint(): void
    {
        $requestedPages = [];
        $source = Mockery::mock(PriceController::class);
        $source->shouldReceive('productPriceAdjustmentHistory')
            ->twice()
            ->andReturnUsing(function (Request $request, string $productId) use (&$requestedPages) {
                $requestedPages[] = [
                    'page' => (int) $request->input('page'),
                    'per_page' => (int) $request->input('per_page'),
                    'product_id' => $productId,
                ];

                $page = (int) $request->input('page');
                $record = [
                    'id' => 'adjustment-' . $page,
                    'branch_id' => 'branch-1',
                    'old_price' => $page === 1 ? '10.0000' : '12.5000',
                    'new_price' => $page === 1 ? '12.5000' : '11.0000',
                    'currency' => 'usd',
                    'adjustment_type' => 'manual',
                    'reason' => 'Approved change',
                    'adjusted_at' => '2026-10-0' . $page . 'T09:30:00.000000Z',
                    'metadata' => [
                        'product_name' => 'Chicken Meal',
                        'product_code' => 'MENU-001',
                        'price_level_name' => 'Standard',
                    ],
                    'price_level' => ['name' => 'Standard'],
                    'adjudtedby' => [
                        'name' => 'Manager One',
                        'username' => 'manager',
                        'employee_code' => 'EMP-1',
                    ],
                ];

                return response()->json([
                    'data' => [
                        'data' => [$record],
                        'current_page' => $page,
                        'last_page' => 2,
                    ],
                ]);
            });
        $this->app->instance(PriceController::class, $source);

        $capturedSheets = null;
        $downloads = Mockery::mock(EnterpriseReportDownloadService::class);
        $downloads->shouldReceive('downloadSpreadsheet')
            ->once()
            ->withArgs(function ($title, $filename, $sheets, $autoFilter) use (&$capturedSheets) {
                $capturedSheets = $sheets;

                return $title === 'Product Price Adjustment History'
                    && str_ends_with($filename, '.xlsx')
                    && $autoFilter === true;
            })
            ->andReturn(response('spreadsheet'));

        $request = Request::create(
            '/api/pos/products/product-1/price-adjustments/export/xlsx',
            'GET',
            ['per_page' => 10]
        );

        $response = app(InventoryReportDownloadController::class)
            ->productPriceAdjustmentHistory($request, 'product-1', 'xlsx', $downloads);

        $this->assertSame('spreadsheet', $response->getContent());
        $this->assertSame([
            ['page' => 1, 'per_page' => 500, 'product_id' => 'product-1'],
            ['page' => 2, 'per_page' => 500, 'product_id' => 'product-1'],
        ], $requestedPages);
        $this->assertSame('Report Context', $capturedSheets[0]['title']);
        $this->assertSame(['Product ID', 'product-1'], $capturedSheets[0]['rows'][2]);
        $this->assertCount(2, $capturedSheets[1]['rows']);
        $this->assertSame('Chicken Meal', $capturedSheets[1]['rows'][0][1]);
        $this->assertSame('2.5000', $capturedSheets[1]['rows'][0][6]);
        $this->assertSame('-1.5000', $capturedSheets[1]['rows'][1][6]);
        $this->assertSame('Manager One', $capturedSheets[1]['rows'][0][10]);
    }

    public function test_stock_history_export_preserves_filters_and_maps_the_json_rows_to_pdf(): void
    {
        $capturedSourceRequest = null;
        $source = Mockery::mock(ProductController::class);
        $source->shouldReceive('stockAdjustmentHistory')
            ->once()
            ->andReturnUsing(function (Request $request) use (&$capturedSourceRequest) {
                $capturedSourceRequest = $request->all();

                return response()->json([
                    'data' => [
                        'data' => [[
                            'id' => 'movement-1',
                            'created_at' => '2026-10-02T08:00:00.000000Z',
                            'quantity_in' => '4.0000',
                            'quantity_out' => '0.0000',
                            'unit_cost' => '99.0000',
                            'total_cost' => '396.0000',
                            'currency_code' => 'zwg',
                            'average_cost' => '1.2500',
                            'cost_value' => '5.0000',
                            'cost_currency_code' => 'usd',
                            'stock_area' => 'back',
                            'notes' => 'Opening correction',
                            'product' => [
                                'name' => 'Flour',
                                'barcode' => '10001',
                                'sku' => 'RM-001',
                                'code' => 'FLOUR',
                                'uom' => ['name' => 'Kilogram', 'code' => 'KG'],
                            ],
                            'branch' => ['branch_name' => 'Harare'],
                            'created_by' => [
                                'name' => 'Stock Controller',
                                'username' => 'stock.one',
                                'user_type' => 'back_office',
                            ],
                        ]],
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ]);
            });
        $this->app->instance(ProductController::class, $source);

        $capturedSections = null;
        $downloads = Mockery::mock(EnterpriseReportDownloadService::class);
        $downloads->shouldReceive('downloadPdf')
            ->once()
            ->withArgs(function (
                $title,
                $filename,
                $sections,
                $rowLimit,
                $paper,
                $orientation,
                $compact
            ) use (&$capturedSections) {
                $capturedSections = $sections;

                return $title === 'Stock Adjustment History'
                    && str_ends_with($filename, '.pdf')
                    && $rowLimit === null
                    && $paper === 'a3'
                    && $orientation === 'landscape'
                    && $compact === true;
            })
            ->andReturn(response('pdf'));

        $filters = [
            'branch_id' => 'branch-1',
            'q' => 'flour',
            'adjustment_type' => 'increase',
            'stock_area' => 'back',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-02',
            'page' => 7,
            'per_page' => 25,
        ];
        $request = Request::create(
            '/api/pos/stock-adjustment-history/export/pdf',
            'GET',
            $filters
        );

        $response = app(InventoryReportDownloadController::class)
            ->stockAdjustmentHistory($request, 'pdf', $downloads);

        $this->assertSame('pdf', $response->getContent());
        $this->assertSame('branch-1', $capturedSourceRequest['branch_id']);
        $this->assertSame('flour', $capturedSourceRequest['q']);
        $this->assertSame('increase', $capturedSourceRequest['adjustment_type']);
        $this->assertSame('back', $capturedSourceRequest['stock_area']);
        $this->assertSame('2026-10-01', $capturedSourceRequest['date_from']);
        $this->assertSame('2026-10-02', $capturedSourceRequest['date_to']);
        $this->assertSame(1, (int) $capturedSourceRequest['page']);
        $this->assertSame(500, (int) $capturedSourceRequest['per_page']);

        $this->assertSame('Applied Filters', $capturedSections[0]['title']);
        $this->assertSame('Stock Adjustment History', $capturedSections[1]['title']);
        $this->assertSame('Flour', $capturedSections[1]['rows'][0][1]);
        $this->assertSame('Kilogram (KG)', $capturedSections[1]['rows'][0][5]);
        $this->assertSame('4.0000', $capturedSections[1]['rows'][0][10]);
        $this->assertSame('1.2500', $capturedSections[1]['rows'][0][11]);
        $this->assertSame('5.0000', $capturedSections[1]['rows'][0][12]);
        $this->assertSame('USD', $capturedSections[1]['rows'][0][13]);
        $this->assertSame('Average Cost', $capturedSections[1]['headers'][11]);
        $this->assertSame('Average Cost Value', $capturedSections[1]['headers'][12]);
        $this->assertSame('Cost Currency', $capturedSections[1]['headers'][13]);
        $this->assertSame('Stock Controller', $capturedSections[1]['rows'][0][15]);
    }

    public function test_source_endpoint_authorization_errors_are_propagated_without_generating_a_file(): void
    {
        $source = Mockery::mock(PriceController::class);
        $source->shouldReceive('productPriceAdjustmentHistory')
            ->once()
            ->andReturn(response()->json([
                'message' => 'You do not have permission to manage prices.',
            ], 403));
        $this->app->instance(PriceController::class, $source);

        $downloads = Mockery::mock(EnterpriseReportDownloadService::class);
        $downloads->shouldNotReceive('downloadSpreadsheet');
        $downloads->shouldNotReceive('downloadPdf');

        $this->expectException(HttpResponseException::class);

        app(InventoryReportDownloadController::class)->productPriceAdjustmentHistory(
            Request::create(
                '/api/pos/products/product-1/price-adjustments/export/pdf',
                'GET'
            ),
            'product-1',
            'pdf',
            $downloads
        );
    }
}
