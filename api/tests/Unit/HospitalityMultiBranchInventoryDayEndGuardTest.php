<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mockery;
use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Support\HospitalityMultiBranchTradingDayGuard;
use ReflectionClass;
use Tests\TestCase;

class HospitalityMultiBranchInventoryDayEndGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_snapshots_and_locks_all_hospitality_branches_in_deterministic_order_only(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')
            ->times(3)
            ->andReturnUsing(fn (string $companyId, string $branchId) => $branchId !== 'retail-c');

        $guard = new class($settings) extends HospitalityMultiBranchTradingDayGuard {
            /** @var array<int, string> */
            public array $snapshotOrder = [];

            /** @var array<int, string> */
            public array $lockOrder = [];

            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                $this->snapshotOrder[] = $branchId;

                return (object) [
                    'id' => "day-{$branchId}",
                    'branch_id' => $branchId,
                    'trading_date' => '2026-10-02',
                    'status' => 'open',
                ];
            }

            protected function lockExpectedTradingDays(string $companyId, array $expectedByBranch): array
            {
                $this->lockOrder = array_keys($expectedByBranch);

                return collect($expectedByBranch)
                    ->mapWithKeys(fn (array $expected, string $branchId) => [
                        $branchId => (object) [
                            'id' => $expected['id'],
                            'branch_id' => $branchId,
                            'trading_date' => $expected['trading_date'],
                            'status' => 'open',
                        ],
                    ])
                    ->all();
            }

            protected function runtimeLock(string $companyId, string $branchId): ?array
            {
                return null;
            }
        };

        $snapshot = $guard->snapshot('company-a', ['hospitality-b', 'retail-c', 'hospitality-a', 'hospitality-b']);
        $guard->lockAndValidate($snapshot);

        $this->assertSame(['hospitality-a', 'hospitality-b'], array_keys($snapshot['branches']));
        $this->assertSame(['hospitality-a', 'hospitality-b'], $guard->snapshotOrder);
        $this->assertSame(['hospitality-a', 'hospitality-b'], $guard->lockOrder);
        $this->assertArrayNotHasKey('retail-c', $snapshot['branches']);
    }

    public function test_it_rejects_the_whole_mutation_when_any_branch_trading_day_changed(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')->twice()->andReturnTrue();

        $guard = new class($settings) extends HospitalityMultiBranchTradingDayGuard {
            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => "old-day-{$branchId}",
                    'branch_id' => $branchId,
                    'trading_date' => '2026-10-01',
                    'status' => 'open',
                ];
            }

            protected function lockExpectedTradingDays(string $companyId, array $expectedByBranch): array
            {
                return [
                    'branch-a' => (object) [
                        'id' => 'old-day-branch-a',
                        'branch_id' => 'branch-a',
                        'trading_date' => '2026-10-01',
                        'status' => 'open',
                    ],
                    'branch-b' => (object) [
                        'id' => 'new-day-branch-b',
                        'branch_id' => 'branch-b',
                        'trading_date' => '2026-10-02',
                        'status' => 'open',
                    ],
                ];
            }

            protected function runtimeLock(string $companyId, string $branchId): ?array
            {
                return null;
            }
        };

        $snapshot = $guard->snapshot('company-a', ['branch-b', 'branch-a']);

        try {
            $guard->lockAndValidate($snapshot);
            $this->fail('A changed trading day must reject the multi-branch mutation.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $payload = $response->getData(true);

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('HOSPITALITY_TRADING_DAY_CHANGED_RETRY', $payload['code']);
            $this->assertSame('branch-b', $payload['data']['branch_id']);
            $this->assertSame(['branch-a', 'branch-b'], $payload['data']['branch_ids']);
            $this->assertSame('old-day-branch-b', $payload['data']['request_trading_day_id']);
            $this->assertSame('new-day-branch-b', $payload['data']['current_trading_day_id']);
        }
    }

    public function test_it_blocks_the_whole_mutation_when_day_end_is_running_for_any_affected_branch(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')->twice()->andReturnTrue();

        $guard = new class($settings) extends HospitalityMultiBranchTradingDayGuard {
            public bool $insideLockValidation = false;

            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => "day-{$branchId}",
                    'branch_id' => $branchId,
                    'trading_date' => '2026-10-02',
                    'status' => 'open',
                ];
            }

            protected function lockExpectedTradingDays(string $companyId, array $expectedByBranch): array
            {
                $this->insideLockValidation = true;

                return collect($expectedByBranch)
                    ->mapWithKeys(fn (array $expected, string $branchId) => [
                        $branchId => (object) [
                            'id' => $expected['id'],
                            'branch_id' => $branchId,
                            'trading_date' => $expected['trading_date'],
                            'status' => 'open',
                        ],
                    ])
                    ->all();
            }

            protected function runtimeLock(string $companyId, string $branchId): ?array
            {
                return $this->insideLockValidation && $branchId === 'branch-b'
                    ? ['status' => 'running', 'run_key' => 'day-end-b']
                    : null;
            }
        };

        $snapshot = $guard->snapshot('company-a', ['branch-a', 'branch-b']);

        try {
            $guard->lockAndValidate($snapshot);
            $this->fail('A running Day End must lock the multi-branch mutation.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $payload = $response->getData(true);

            $this->assertSame(423, $response->getStatusCode());
            $this->assertSame('HOSPITALITY_DAY_END_INVENTORY_LOCKED', $payload['code']);
            $this->assertSame('branch-b', $payload['data']['branch_id']);
            $this->assertSame(['branch-a', 'branch-b'], $payload['data']['branch_ids']);
        }
    }

    public function test_it_rejects_a_snapshot_whose_exact_trading_day_row_is_now_closed(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')->once()->andReturnTrue();

        $guard = new class($settings) extends HospitalityMultiBranchTradingDayGuard {
            protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
            {
                return (object) [
                    'id' => 'day-a',
                    'branch_id' => $branchId,
                    'trading_date' => '2026-10-01',
                    'status' => 'open',
                ];
            }

            protected function lockExpectedTradingDays(string $companyId, array $expectedByBranch): array
            {
                return [
                    'branch-a' => (object) [
                        'id' => 'day-a',
                        'branch_id' => 'branch-a',
                        'trading_date' => '2026-10-01',
                        'status' => 'closed',
                    ],
                ];
            }

            protected function runtimeLock(string $companyId, string $branchId): ?array
            {
                return null;
            }
        };

        $snapshot = $guard->snapshot('company-a', ['branch-a']);

        try {
            $guard->lockAndValidate($snapshot);
            $this->fail('A closed request-start trading day must reject the mutation.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $payload = $response->getData(true);

            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('HOSPITALITY_TRADING_DAY_CHANGED_RETRY', $payload['code']);
            $this->assertSame('day-a', $payload['data']['request_trading_day_id']);
            $this->assertSame('day-a', $payload['data']['current_trading_day_id']);
        }
    }

    public function test_product_opening_stock_and_recall_close_are_wired_to_the_multi_branch_guard(): void
    {
        $productSource = file_get_contents(
            (new ReflectionClass(ProductController::class))->getFileName()
        );
        $enterpriseSource = file_get_contents(
            (new ReflectionClass(EnterpriseInventoryController::class))->getFileName()
        );
        $routes = file_get_contents(base_path('Modules/Product/routes/api.php'));

        $openingBranches = strpos($productSource, '$openingStockBranchIds = collect(');
        $productSnapshot = strpos($productSource, '$tradingDaySnapshot = $tradingDayGuard->snapshot(', $openingBranches);
        $productTransaction = strpos($productSource, 'DB::beginTransaction();', $productSnapshot);
        $productLock = strpos($productSource, '$tradingDayGuard->lockAndValidate($tradingDaySnapshot);', $productTransaction);

        $this->assertNotFalse($openingBranches);
        $this->assertNotFalse($productSnapshot);
        $this->assertNotFalse($productTransaction);
        $this->assertNotFalse($productLock);
        $this->assertTrue($openingBranches < $productSnapshot);
        $this->assertTrue($productSnapshot < $productTransaction);
        $this->assertTrue($productTransaction < $productLock);

        $affectedRecallBranches = strpos($enterpriseSource, '$affectedBranchIds = DB::table(\'inventory_recall_batches as rb\')');
        $recallSnapshot = strpos($enterpriseSource, '$tradingDaySnapshot = $tradingDayGuard->snapshot(', $affectedRecallBranches);
        $recallTransaction = strpos($enterpriseSource, 'DB::transaction(function () use (', $recallSnapshot);
        $recallLock = strpos($enterpriseSource, '$tradingDayGuard->lockAndValidate($tradingDaySnapshot);', $recallTransaction);

        $this->assertNotFalse($affectedRecallBranches);
        $this->assertNotFalse($recallSnapshot);
        $this->assertNotFalse($recallTransaction);
        $this->assertNotFalse($recallLock);
        $this->assertTrue($affectedRecallBranches < $recallSnapshot);
        $this->assertTrue($recallSnapshot < $recallTransaction);
        $this->assertTrue($recallTransaction < $recallLock);

        $this->assertStringContainsString("Route::apiResource('products', ProductController::class)", $routes);
        $this->assertStringContainsString("Route::post('/products', [ProductController::class, 'store'])", $routes);
        $this->assertStringContainsString("Route::post('/recalls/{recallId}/close', [EnterpriseInventoryController::class, 'closeRecall'])", $routes);

        foreach ([
            "Route::post('/products', [ProductController::class, 'store'])",
            "Route::post('/recalls/{recallId}/close', [EnterpriseInventoryController::class, 'closeRecall'])",
        ] as $routeDeclaration) {
            $routeStart = strpos($routes, $routeDeclaration);
            $routeEnd = strpos($routes, ';', $routeStart);
            $this->assertStringNotContainsString(
                'BlockHospitalityInventoryWhenDayEndRunning',
                substr($routes, $routeStart, $routeEnd - $routeStart),
                'Multi-branch mutations must use the controller guard, not the single-branch middleware.'
            );
        }

        $guardSource = file_get_contents(
            (new ReflectionClass(HospitalityMultiBranchTradingDayGuard::class))->getFileName()
        );
        $this->assertStringContainsString('ksort($expectedByBranch, SORT_STRING);', $guardSource);
        $this->assertStringContainsString('->lockForUpdate()', $guardSource);
        $this->assertStringContainsString("'HOSPITALITY_TRADING_DAY_CHANGED_RETRY'", $guardSource);
    }
}
