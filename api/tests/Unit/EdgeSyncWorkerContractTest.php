<?php

namespace Tests\Unit;

use App\Console\Commands\EdgeSyncWorkerCommand;
use App\Http\Controllers\EdgeSyncController;
use App\Models\SyncLocalState;
use Carbon\CarbonImmutable;
use ReflectionClass;
use Tests\TestCase;

class EdgeSyncWorkerContractTest extends TestCase
{
    public function test_edge_worker_is_browser_independent_locked_and_retryable(): void
    {
        $source = file_get_contents((new ReflectionClass(EdgeSyncWorkerCommand::class))->getFileName());

        $this->assertStringContainsString("sync:edge-worker", $source);
        $this->assertStringContainsString("config('salexa_sync.deployment_mode') !== 'edge'", $source);
        $this->assertStringContainsString('$orchestrator->run($state)', $source);
        $this->assertStringContainsString('catch (SyncRunAlreadyInProgress)', $source);
        $this->assertStringContainsString('retryDelay($failureCount, $interval)', $source);
        $this->assertStringContainsString("'background_worker' => \$worker", $source);
        $this->assertStringContainsString('{--once', $source);
    }

    public function test_windows_service_installer_runs_the_worker_automatically(): void
    {
        $script = file_get_contents(base_path('../deployment/local-sync-simulation/Install-SalexaPhpServices.ps1'));

        $this->assertStringContainsString("'SalexaEdgeSync'", $script);
        $this->assertStringContainsString("'artisan sync:edge-worker'", $script);
        $this->assertStringContainsString('SERVICE_AUTO_START', $script);
        $this->assertStringContainsString('$EdgeApiDirectory', $script);
    }

    public function test_browser_runs_are_coalesced_only_while_the_worker_heartbeat_is_healthy(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T12:00:00+02:00');
        config()->set('salexa_sync.polling.push_interval_seconds', 30);
        config()->set('salexa_sync.polling.pull_interval_seconds', 30);

        $controller = (new ReflectionClass(EdgeSyncController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(EdgeSyncController::class, 'backgroundWorkerIsHealthy');
        $method->setAccessible(true);

        $state = new SyncLocalState;
        $state->metadata = ['background_worker' => [
            'status' => 'healthy',
            'heartbeat_at' => now()->subSeconds(60)->toIso8601String(),
        ]];
        $this->assertTrue($method->invoke($controller, $state));

        $state->metadata = ['background_worker' => [
            'status' => 'healthy',
            'heartbeat_at' => now()->subSeconds(91)->toIso8601String(),
        ]];
        $this->assertFalse($method->invoke($controller, $state));

        $state->metadata = ['background_worker' => [
            'status' => 'degraded',
            'heartbeat_at' => now()->toIso8601String(),
        ]];
        $this->assertFalse($method->invoke($controller, $state));

        CarbonImmutable::setTestNow();
    }
}
