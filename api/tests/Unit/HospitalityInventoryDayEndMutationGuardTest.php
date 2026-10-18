<?php

namespace Tests\Unit;

use App\Http\Middleware\BlockHospitalityInventoryWhenDayEndRunning;
use App\Services\CompanySettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\Product\Exports\MenuRecipeDsrSpreadsheetExport;
use Modules\Product\Http\Controllers\MenuRecipeDailyStockReconciliationController;
use Modules\Product\Http\Controllers\TradingDayEndController;
use Modules\Product\Models\DailyStockReconciliationReport;
use Modules\Product\Models\TradingDayEnd;
use Modules\Product\Support\HospitalityTradingDatePolicy;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityInventoryDayEndMutationGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::store('file')->forget(HospitalityTradingDatePolicy::runtimeLockCacheKey('company-a', 'branch-a'));
        Cache::store('file')->forget(HospitalityTradingDatePolicy::runtimeLockCacheKey('company-a', 'branch-retail'));
        Mockery::close();
        parent::tearDown();
    }

    public function test_runtime_lock_rejects_only_the_locked_hospitality_branch_and_retail_is_unchanged(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);

        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')
            ->andReturnUsing(fn (string $companyId, string $branchId) => $branchId !== 'branch-retail');
        app()->instance(CompanySettingService::class, $settings);

        $guard = new class extends BlockHospitalityInventoryWhenDayEndRunning
        {
            protected function resolveBranchId(Request $request, string $companyId, string $branchSource): ?string
            {
                return (string) $request->input('branch_id');
            }

            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => "day-{$branchId}",
                    'status' => 'open',
                    'trading_date' => '2026-10-02',
                ];
            }

            protected function lockActiveTradingDay(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => "day-{$branchId}",
                    'status' => 'open',
                    'trading_date' => '2026-10-02',
                ];
            }

            protected function withinTransaction(Closure $callback): mixed
            {
                return $callback();
            }
        };

        Cache::store('file')->put(
            HospitalityTradingDatePolicy::runtimeLockCacheKey('company-a', 'branch-a'),
            ['status' => 'running', 'run_key' => 'run-a'],
            now()->addMinute()
        );
        Cache::store('file')->put(
            HospitalityTradingDatePolicy::runtimeLockCacheKey('company-a', 'branch-retail'),
            ['status' => 'running', 'run_key' => 'retail-run'],
            now()->addMinute()
        );

        $locked = $guard->handle(
            Request::create('/api/pos/stock/issue-to-front', 'POST', ['branch_id' => 'branch-a']),
            fn () => response()->json(['ok' => true])
        );
        $otherBranch = $guard->handle(
            Request::create('/api/pos/stock/issue-to-front', 'POST', ['branch_id' => 'branch-b']),
            fn () => response()->json(['ok' => true])
        );
        $retail = $guard->handle(
            Request::create('/api/pos/products/product-a/adjust-stock', 'POST', ['branch_id' => 'branch-retail']),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(423, $locked->getStatusCode());
        $this->assertSame('HOSPITALITY_DAY_END_INVENTORY_LOCKED', $locked->getData(true)['code']);
        $this->assertSame('branch-a', $locked->getData(true)['data']['branch_id']);
        $this->assertSame(200, $otherBranch->getStatusCode());
        $this->assertSame(200, $retail->getStatusCode());
    }

    public function test_mutation_is_rejected_when_day_end_rolls_the_branch_forward_while_it_waits(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);

        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')->once()->andReturnTrue();
        app()->instance(CompanySettingService::class, $settings);

        $guard = new class extends BlockHospitalityInventoryWhenDayEndRunning
        {
            protected function resolveBranchId(Request $request, string $companyId, string $branchSource): ?string
            {
                return 'branch-a';
            }

            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => 'day-old',
                    'status' => 'open',
                    'trading_date' => '2026-10-01',
                ];
            }

            protected function lockActiveTradingDay(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => 'day-new',
                    'status' => 'open',
                    'trading_date' => '2026-10-02',
                ];
            }

            protected function withinTransaction(Closure $callback): mixed
            {
                return $callback();
            }
        };

        $downstreamCalled = false;
        $response = $guard->handle(
            Request::create('/api/pos/front-stock/daily-closings', 'POST', [
                'branch_id' => 'branch-a',
                'closing_date' => '2026-10-01',
            ]),
            function () use (&$downstreamCalled) {
                $downstreamCalled = true;

                return response()->json(['ok' => true]);
            }
        );

        $payload = $response->getData(true);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('HOSPITALITY_TRADING_DAY_CHANGED_RETRY', $payload['code']);
        $this->assertSame('day-old', $payload['data']['request_trading_day_id']);
        $this->assertSame('2026-10-01', $payload['data']['request_trading_date']);
        $this->assertSame('day-new', $payload['data']['current_trading_day_id']);
        $this->assertSame('2026-10-02', $payload['data']['current_trading_date']);
        $this->assertFalse($downstreamCalled);
    }

    public function test_inventory_mutation_routes_use_the_branch_serializing_guard(): void
    {
        $routes = file_get_contents(base_path('Modules/Product/routes/api.php'));

        foreach ([
            "'/stock/issue-enterprise'",
            "'/stock/issue-to-front'",
            "'/front-stock/daily-closings'",
            "'/stock-wastages'",
            "'/products/{id}/receive-stock'",
            "'/products/{id}/adjust-stock'",
            "'/products/adjust-stock-bulk'",
            "'/products/{childProductId}/break'",
            "'/stock-takes/month-end/back-stock/upload-trading-count-sheets'",
            "'/products/import'",
            "'/orders/{purchaseOrderId}/receive'",
            "'/receipts'",
            "'/returns'",
        ] as $uri) {
            $position = strpos($routes, $uri);
            $this->assertNotFalse($position, "Missing inventory mutation route {$uri}.");
            $routeDeclaration = substr($routes, $position, 500);
            $this->assertStringContainsString(
                'BlockHospitalityInventoryWhenDayEndRunning::class',
                $routeDeclaration,
                "Inventory mutation route {$uri} must serialize against branch Day End."
            );
        }

        $this->assertStringContainsString(
            "BlockHospitalityInventoryWhenDayEndRunning::class.':transfer_from'",
            $routes
        );
        $this->assertStringContainsString(
            "BlockHospitalityInventoryWhenDayEndRunning::class.':transfer_to'",
            $routes
        );

        $foodManufacturingRoutes = file_get_contents(base_path('Modules/FoodManufacturing/routes/api.php'));
        $batchRoute = strpos($foodManufacturingRoutes, "Route::post('batches'");
        $this->assertNotFalse($batchRoute, 'Missing Hospitality food production batch route.');
        $this->assertStringContainsString(
            'BlockHospitalityInventoryWhenDayEndRunning::class',
            substr($foodManufacturingRoutes, $batchRoute, 300),
            'Food production material consumption/output must serialize against branch Day End.'
        );

        $guardSource = file_get_contents((new ReflectionClass(BlockHospitalityInventoryWhenDayEndRunning::class))->getFileName());
        $this->assertStringContainsString('->lockForUpdate()', $guardSource);
        $this->assertStringContainsString("->where('company_id', \$companyId)", $guardSource);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $guardSource);
    }

    public function test_multi_currency_dsr_has_no_cross_currency_headline_or_download_total(): void
    {
        $controllerSource = file_get_contents(
            (new ReflectionClass(MenuRecipeDailyStockReconciliationController::class))->getFileName()
        );
        $this->assertStringContainsString("'headline_sales_totals_available' => \$headlineSalesAvailable", $controllerSource);
        $this->assertStringContainsString(
            '$headlineSalesAvailable = $reportCurrencies->count() <= 1',
            $controllerSource
        );
        $this->assertStringContainsString(
            '$headlineVarianceAvailable = $reportCurrencies->count() <= 1',
            $controllerSource
        );
        $this->assertStringContainsString('? round($totalRevenue, 6) : null', $controllerSource);
        $this->assertStringContainsString('Cross-currency headline totals are unavailable.', $controllerSource);

        $report = (object) [
            'trading_date' => '2026-10-02',
            'status' => 'final',
            'metadata' => [
                'summary' => [
                    'total_revenue' => null,
                    'total_recipe_cost' => null,
                    'gross_profit' => null,
                    'headline_sales_totals_available' => false,
                    'currency_totals' => [
                        ['currency_code' => 'USD', 'total_revenue' => 10, 'total_recipe_cost' => 4, 'gross_profit' => 6],
                        ['currency_code' => 'ZWG', 'total_revenue' => 100, 'total_recipe_cost' => 40, 'gross_profit' => 60],
                    ],
                    'inventory_currency_totals' => [],
                ],
            ],
            'lines' => [],
        ];

        $sheet = (new MenuRecipeDsrSpreadsheetExport($report))->build()->getSheetByName('Executive Summary');
        $foundExplicitUnavailableTotal = false;
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell('A'.$row->getRowIndex())->getValue() === 'NO CROSS-CURRENCY TOTAL') {
                $foundExplicitUnavailableTotal = true;
                $this->assertSame(
                    'Use the authoritative currency rows above',
                    $sheet->getCell('B'.$row->getRowIndex())->getValue()
                );
            }
        }

        $this->assertTrue($foundExplicitUnavailableTotal);
    }

    public function test_saved_report_and_day_end_summary_expose_null_not_legacy_zero_for_multi_currency(): void
    {
        $summary = [
            'total_revenue' => null,
            'total_recipe_cost' => null,
            'gross_profit' => null,
            'total_variance_value' => null,
            'headline_sales_totals_available' => false,
            'headline_variance_total_available' => false,
            'headline_totals_note' => 'Cross-currency headline totals are unavailable.',
            'currency_totals' => [
                ['currency_code' => 'USD', 'total_revenue' => 10, 'total_recipe_cost' => 4, 'gross_profit' => 6],
                ['currency_code' => 'ZWG', 'total_revenue' => 100, 'total_recipe_cost' => 40, 'gross_profit' => 60],
            ],
            'inventory_currency_totals' => [
                ['currency_code' => 'USD', 'total_variance_value' => 1],
                ['currency_code' => 'ZWG', 'total_variance_value' => 5],
            ],
        ];
        $report = new DailyStockReconciliationReport;
        $report->setRawAttributes([
            'id' => 'report-a',
            'currency_code' => 'MULTI',
            'total_revenue' => '0.000000',
            'total_recipe_cost' => '0.000000',
            'gross_profit' => '0.000000',
            'total_variance_value' => '0.000000',
            'metadata' => json_encode(['summary' => $summary], JSON_THROW_ON_ERROR),
        ], true);

        $serialized = $report->toArray();
        $this->assertNull($serialized['total_revenue']);
        $this->assertNull($serialized['total_recipe_cost']);
        $this->assertNull($serialized['gross_profit']);
        $this->assertNull($serialized['total_variance_value']);

        $method = new ReflectionMethod(TradingDayEndController::class, 'reconciliationAccountingSummary');
        $method->setAccessible(true);
        $dayEndSummary = $method->invoke(new TradingDayEndController, $report);

        $this->assertFalse($dayEndSummary['headline_sales_totals_available']);
        $this->assertFalse($dayEndSummary['headline_variance_total_available']);
        $this->assertNull($dayEndSummary['total_revenue']);
        $this->assertNull($dayEndSummary['total_recipe_cost']);
        $this->assertNull($dayEndSummary['gross_profit']);
        $this->assertNull($dayEndSummary['total_variance_value']);
        $this->assertSame($summary['currency_totals'], $dayEndSummary['currency_totals']);
        $this->assertSame($summary['inventory_currency_totals'], $dayEndSummary['inventory_currency_totals']);

        $completedRun = new TradingDayEnd;
        $completedRun->setRawAttributes([
            'id' => 'run-a',
            'status' => 'complete',
            'summary' => json_encode([
                'report_id' => 'report-a',
                ...$dayEndSummary,
            ], JSON_THROW_ON_ERROR),
        ], true);
        $serializedRun = $completedRun->toArray();
        $this->assertNull($serializedRun['summary']['total_revenue']);
        $this->assertNull($serializedRun['summary']['total_recipe_cost']);
        $this->assertNull($serializedRun['summary']['gross_profit']);
        $this->assertNull($serializedRun['summary']['total_variance_value']);
        $this->assertFalse($serializedRun['summary']['headline_sales_totals_available']);
        $this->assertSame($summary['currency_totals'], $serializedRun['summary']['currency_totals']);
    }
}
