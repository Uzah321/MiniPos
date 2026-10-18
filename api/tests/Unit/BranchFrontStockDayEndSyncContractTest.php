<?php

namespace Tests\Unit;

use App\Http\Controllers\SyncProtocolController;
use App\Models\SyncLocalState;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncFrontStockClosingService;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncTransportService;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use Modules\Product\Http\Controllers\TradingDayEndController;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class BranchFrontStockDayEndSyncContractTest extends TestCase
{
    public function test_front_stock_closing_business_identity_is_branch_date_area_not_till(): void
    {
        $migration = file_get_contents(
            dirname((new ReflectionClass(FrontStockDailyClosingController::class))->getFileName(), 3)
            . '/database/migrations/2026_06_28_032137_create_front_stock_daily_closings_table.php'
        );
        $controller = $this->source(FrontStockDailyClosingController::class);
        $dayEnd = $this->source(TradingDayEndController::class);

        $this->assertStringContainsString(
            "['company_id', 'branch_id', 'closing_date', 'stock_area']",
            $migration
        );
        $this->assertStringNotContainsString(
            "['company_id', 'branch_id', 'till_id', 'closing_date'",
            $migration
        );
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $controller);
        $this->assertStringContainsString('SyncFrontStockClosingService::class', $controller);
        $this->assertStringContainsString('queueClosing(', $controller);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $dayEnd);
        $this->assertStringNotContainsString("->where('till_id', \$till", $this->methodSource(
            TradingDayEndController::class,
            'frontStockClosingExists'
        ));
        $this->assertStringContainsString("->where('company_id', \$companyId)", $this->methodSource(
            TradingDayEndController::class,
            'frontStockClosingExists'
        ));
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $this->methodSource(
            TradingDayEndController::class,
            'frontStockClosingExists'
        ));
    }

    public function test_edge_closing_uses_a_durable_idempotent_authenticated_event(): void
    {
        $producer = $this->source(SyncFrontStockClosingService::class);
        $orchestrator = $this->source(SyncOrchestrator::class);
        $protocol = $this->source(SyncProtocolController::class);

        $this->assertStringContainsString("private const EVENT_TYPE = 'front_stock.closing.completed'", $producer);
        $this->assertStringContainsString("'deduplication_key' => \$deduplicationKey", $producer);
        $this->assertStringContainsString('recordOutboxProof($lockedState, $event)', $producer);
        $this->assertStringContainsString("->whereIn('status', ['open', 'day_end_running'])", $producer);
        $this->assertStringNotContainsString("'captured_at' => now()", $producer);
        $this->assertStringContainsString('queueCompletedFrontStockClosings', $orchestrator);
        $this->assertStringContainsString("'front_stock.closing.completed'", $protocol);
        $this->assertStringContainsString('frontStockClosingImporter->import', $protocol);
        $this->assertStringContainsString('first_branch_closure_retained', $producer);
        $this->assertStringContainsString('$existingIsLiveClosed', $producer);
        $this->assertStringContainsString("'source_closing_id' => \$sourceClosingId", $producer);
        $this->assertStringContainsString("'deleted_at' => null", $producer);
        $this->assertStringContainsString('SYNC_FRONT_STOCK_MOVEMENT_ID_CONFLICT', $producer);
        $this->assertStringContainsString(
            '(string) $existingMovement->branch_id !== (string) $node->branch_id',
            $producer
        );
    }

    public function test_cloud_snapshot_distributes_branch_closing_and_trading_day_to_edges(): void
    {
        $configuration = $this->source(SyncConfigurationService::class);

        $this->assertStringContainsString("'trading_days' => [", $configuration);
        $this->assertStringContainsString("'front_stock_daily_closings' => [", $configuration);
        $this->assertStringContainsString("'scope' => 'branch-front-stock-closings'", $configuration);
        $this->assertStringContainsString('scopeBranchFrontStockClosings', $configuration);
        $this->assertStringContainsString(
            "'front_stock_daily_closings' => [\n                'company_id', 'branch_id', 'closing_date', 'stock_area'",
            $configuration
        );
    }

    public function test_day_end_distinguishes_missing_and_stale_and_edge_delegates_to_cloud_authority(): void
    {
        $dayEnd = $this->source(TradingDayEndController::class);
        $orchestrator = $this->source(SyncOrchestrator::class);
        $transport = $this->source(SyncTransportService::class);

        $this->assertStringContainsString("'FRONT_STOCK_CLOSING_STALE'", $dayEnd);
        $this->assertStringContainsString("'stale_front_stock_closings'", $dayEnd);
        $this->assertStringContainsString("->where('status', 'missing')", $dayEnd);
        $this->assertStringContainsString('runAuthoritativeEdgeDayEnd(', $dayEnd);
        $this->assertStringContainsString('runAuthoritativeDayEnd(', $dayEnd);
        $this->assertStringContainsString('runAuthoritativeDayEnd(', $orchestrator);
        $this->assertStringContainsString("->whereNull('delivered_at')", $this->methodSource(
            SyncOrchestrator::class,
            'runAuthoritativeDayEnd'
        ));
        $authoritativeRun = $this->methodSource(SyncOrchestrator::class, 'runAuthoritativeDayEnd');
        $this->assertLessThan(
            strpos($authoritativeRun, '$localReadiness($locked->fresh())'),
            strpos($authoritativeRun, "transport->pull(\$locked->fresh())")
        );
        $this->assertStringContainsString("'/api/pos/sync/day-end'", $transport);
        $this->assertStringContainsString("'DAY_END_CLOUD_AUTHORITY_REQUIRED'", $dayEnd);
        $this->assertStringContainsString("'local_day_end_started' => false", $dayEnd);
        $this->assertStringContainsString("'initiated_via' => 'paired_edge'", $dayEnd);
        $this->assertStringContainsString("'source_sync_node_id'", $dayEnd);
        $this->assertStringContainsString("'source_till_id'", $dayEnd);
    }

    public function test_signed_day_end_route_is_cloud_authoritative(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->keyBy(fn ($route): string => implode(',', $route->methods()).' '.$route->uri());
        $route = $routes->get('POST api/pos/sync/day-end');

        $this->assertNotNull($route);
        $this->assertContains('sync.node', $route->gatherMiddleware());
    }

    public function test_edge_day_end_command_uses_the_paired_signed_cloud_origin(): void
    {
        $originalMode = config('salexa_sync.deployment_mode');
        config()->set('salexa_sync.deployment_mode', 'edge');
        Http::fake([
            'https://central.salexa.test/api/pos/sync/day-end' => Http::response([
                'message' => 'Day End completed.',
                'data' => ['status' => 'complete'],
            ]),
        ]);

        try {
            $state = new SyncLocalState;
            $state->forceFill([
                'node_id' => '10000000-0000-7000-8000-000000000001',
                'company_id' => '10000000-0000-7000-8000-000000000002',
                'branch_id' => '10000000-0000-7000-8000-000000000003',
                'cloud_url' => 'https://central.salexa.test',
                'credential' => 'test-node-credential',
                'status' => 'paired',
            ]);

            $result = app(SyncTransportService::class)->authoritativeDayEnd($state, [
                'run_key' => 'branch-day-end-2026-10-07',
                'expected_trading_date' => '2026-10-07',
                'operator_user_id' => '10000000-0000-7000-8000-000000000004',
                'operation_type' => 'standard',
            ]);

            $this->assertTrue($result['successful']);
            $this->assertSame(200, $result['status']);
            Http::assertSent(fn (Request $request): bool =>
                $request->url() === 'https://central.salexa.test/api/pos/sync/day-end'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Sync-Node', (string) $state->node_id)
                && $request->hasHeader('X-Sync-Signature')
                && $request['run_key'] === 'branch-day-end-2026-10-07'
            );
        } finally {
            config()->set('salexa_sync.deployment_mode', $originalMode);
            Http::preventStrayRequests(false);
        }
    }

    public function test_import_recomputes_totals_instead_of_trusting_edge_summary(): void
    {
        $service = (new ReflectionClass(SyncFrontStockClosingService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SyncFrontStockClosingService::class, 'summary');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [
            [
                'expected_quantity' => 10,
                'counted_quantity' => 8,
                'variance_quantity' => -2,
                'variance_value' => -5,
            ],
            [
                'expected_quantity' => 4,
                'counted_quantity' => 4,
                'variance_quantity' => 0,
                'variance_value' => 0,
            ],
        ]);

        $this->assertSame(2, $summary['total_lines']);
        $this->assertSame(1, $summary['variance_lines']);
        $this->assertSame(14.0, $summary['expected_total_quantity']);
        $this->assertSame(12.0, $summary['counted_total_quantity']);
        $this->assertSame(-2.0, $summary['variance_total_quantity']);
        $this->assertSame(-5.0, $summary['variance_total_value']);
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((new ReflectionClass($class))->getFileName());
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
