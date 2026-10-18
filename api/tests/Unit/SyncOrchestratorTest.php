<?php

namespace Tests\Unit;

use App\Models\SyncLocalState;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncFrontStockClosingService;
use App\Services\Sync\SyncSaleBundleService;
use App\Services\Sync\SyncTransportService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_full_run_attempts_pull_after_push_failure_and_reports_the_failure(): void
    {
        $state = Mockery::mock(SyncLocalState::class)->makePartial();
        $state->forceFill([
            'id' => '10000000-0000-7000-8000-000000000000',
            'node_id' => '10000000-0000-7000-8000-000000000001',
            'company_id' => '10000000-0000-7000-8000-000000000002',
            'branch_id' => '10000000-0000-7000-8000-000000000003',
            'status' => 'paired',
        ]);
        $state->shouldReceive('fresh')->andReturnSelf();
        $state->shouldReceive('save')->andReturnTrue();
        $sales = Mockery::mock(SyncSaleBundleService::class);
        $sales->shouldReceive('queueCompletedSales')->once()->with(Mockery::type(SyncLocalState::class), 500)->andReturn(0);
        $sales->shouldReceive('queueCompletedShiftCashups')->once()->with(Mockery::type(SyncLocalState::class), 500)->andReturn(0);
        $frontStockClosings = Mockery::mock(SyncFrontStockClosingService::class);
        $frontStockClosings->shouldReceive('queueCompletedClosings')
            ->once()
            ->with(Mockery::type(SyncLocalState::class), 500)
            ->andReturn(0);
        $transport = Mockery::mock(SyncTransportService::class);
        $transport->shouldReceive('push')->once()->andThrow(new RuntimeException('upload unavailable'));
        $transport->shouldReceive('pull')->once()->andReturn(['applied' => 1]);
        $transport->shouldNotReceive('remoteStatus');

        try {
            (new SyncOrchestrator($sales, $frontStockClosings, $transport))->run($state);
            $this->fail('The failed push must still fail the complete sync run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('upload unavailable', $exception->getMessage());
        }

        $this->assertSame('error', $state->status);
        $this->assertSame('upload unavailable', $state->last_error);
        $this->assertSame('failed', data_get($state->metadata, 'sync_run.state'));
    }
}
