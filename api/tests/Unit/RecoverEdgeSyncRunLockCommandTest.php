<?php

namespace Tests\Unit;

use App\Models\SyncLocalState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverEdgeSyncRunLockCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        config()->set('salexa_sync.deployment_mode', 'edge');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sync_local_states');

        parent::tearDown();
    }

    public function test_it_releases_only_the_current_paired_node_lock(): void
    {
        config()->set('cache.default', 'database');

        $nodeId = (string) Str::uuid();
        $unrelatedNodeId = (string) Str::uuid();
        $state = SyncLocalState::query()->create([
            'node_id' => $nodeId,
            'metadata' => [
                'sync_run' => [
                    'id' => (string) Str::uuid(),
                    'state' => 'running',
                    'started_at' => now()->subMinute()->toIso8601String(),
                ],
            ],
        ]);

        $pairedLock = Cache::lock('salexa-sync-run:'.$nodeId, 1800);
        $unrelatedLock = Cache::lock('salexa-sync-run:'.$unrelatedNodeId, 1800);
        $this->assertTrue($pairedLock->get());
        $this->assertTrue($unrelatedLock->get());

        $this->artisan('sync:recover-edge-run-lock', ['--services-stopped' => true])
            ->expectsOutput("Recovered the sync run lock for paired local node {$nodeId}.")
            ->assertSuccessful();

        $this->assertTrue(Cache::lock('salexa-sync-run:'.$nodeId, 1)->get());
        $this->assertFalse(Cache::lock('salexa-sync-run:'.$unrelatedNodeId, 1)->get());
        $this->assertSame('interrupted', $state->fresh()->metadata['sync_run']['state']);
    }

    public function test_it_requires_stopped_services_acknowledgement(): void
    {
        $nodeId = (string) Str::uuid();
        SyncLocalState::query()->create(['node_id' => $nodeId]);
        $lock = Cache::lock('salexa-sync-run:'.$nodeId, 1800);
        $this->assertTrue($lock->get());

        $this->artisan('sync:recover-edge-run-lock')
            ->expectsOutputToContain('Refusing recovery')
            ->assertFailed();

        $this->assertFalse(Cache::lock('salexa-sync-run:'.$nodeId, 1)->get());
    }

    public function test_an_unpaired_fresh_install_is_a_safe_no_op(): void
    {
        $unrelatedNodeId = (string) Str::uuid();
        $unrelatedLock = Cache::lock('salexa-sync-run:'.$unrelatedNodeId, 1800);
        $this->assertTrue($unrelatedLock->get());

        $this->artisan('sync:recover-edge-run-lock', ['--services-stopped' => true])
            ->expectsOutput('No paired local sync node was found; no cache lock needed recovery.')
            ->assertSuccessful();

        $this->assertFalse(Cache::lock('salexa-sync-run:'.$unrelatedNodeId, 1)->get());
    }

    public function test_it_refuses_to_run_outside_edge_mode(): void
    {
        $nodeId = (string) Str::uuid();
        SyncLocalState::query()->create(['node_id' => $nodeId]);
        $lock = Cache::lock('salexa-sync-run:'.$nodeId, 1800);
        $this->assertTrue($lock->get());
        config()->set('salexa_sync.deployment_mode', 'cloud');

        $this->artisan('sync:recover-edge-run-lock', ['--services-stopped' => true])
            ->expectsOutputToContain('available only on an edge deployment')
            ->assertFailed();

        $this->assertFalse(Cache::lock('salexa-sync-run:'.$nodeId, 1)->get());
    }

    public function test_deployment_recovery_runs_after_edge_services_and_workers_are_stopped(): void
    {
        $script = file_get_contents(base_path('../deployment/local-sync-simulation/Deploy-AndroidFohRelease.ps1'));

        $stopSync = strpos($script, "Stop-IfRunning 'SalexaEdgeSync'");
        $stopPhp = strpos($script, "Stop-IfRunning 'SalexaEdgePHP'");
        $workerGuard = strpos($script, 'if ((Get-ManualEdgeSyncWorkers).Count -ne 0)');
        $recover = strpos(
            $script,
            "Invoke-Artisan \$WorkspaceApi @('sync:recover-edge-run-lock', '--services-stopped', '--no-interaction')"
        );

        $this->assertNotFalse($stopSync);
        $this->assertNotFalse($stopPhp);
        $this->assertNotFalse($workerGuard);
        $this->assertNotFalse($recover);
        $this->assertGreaterThan($stopSync, $recover);
        $this->assertGreaterThan($stopPhp, $recover);
        $this->assertGreaterThan($workerGuard, $recover);
        $this->assertStringContainsString("Assert-ServiceStopped 'SalexaEdgeSync'", $script);
        $this->assertStringContainsString("Assert-ServiceStopped 'SalexaEdgePHP'", $script);
    }
}
