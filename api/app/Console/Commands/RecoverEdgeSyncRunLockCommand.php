<?php

namespace App\Console\Commands;

use App\Models\SyncLocalState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecoverEdgeSyncRunLockCommand extends Command
{
    protected $signature = 'sync:recover-edge-run-lock
        {--services-stopped : Confirm that SalexaEdgeSync, SalexaEdgePHP and any manual edge worker are stopped}';

    protected $description = 'Release only the paired edge node sync lease after its local services have been stopped';

    public function handle(): int
    {
        if (config('salexa_sync.deployment_mode') !== 'edge') {
            $this->error('Sync run-lock recovery is available only on an edge deployment.');

            return self::FAILURE;
        }

        if (! $this->option('services-stopped')) {
            $this->error(
                'Refusing recovery until SalexaEdgeSync, SalexaEdgePHP and every manual sync:edge-worker process are stopped.'
            );

            return self::FAILURE;
        }

        $state = SyncLocalState::query()
            ->whereNotNull('node_id')
            ->latest()
            ->first();

        if (! $state) {
            $this->info('No paired local sync node was found; no cache lock needed recovery.');

            return self::SUCCESS;
        }

        $nodeId = (string) $state->node_id;
        $lockName = 'salexa-sync-run:'.$nodeId;

        // forceRelease is safe here only because the deployment script first
        // stops every process capable of holding this node-scoped lease. The
        // key is derived from local pairing state, never from operator input.
        Cache::lock($lockName, 1)->forceRelease();

        $recoveredAt = now();
        DB::transaction(function () use ($state, $nodeId, $recoveredAt): void {
            $locked = SyncLocalState::query()->whereKey($state->id)->lockForUpdate()->first();
            if (! $locked || (string) $locked->node_id !== $nodeId) {
                return;
            }

            $metadata = $locked->metadata ?? [];
            $run = is_array($metadata['sync_run'] ?? null)
                ? $metadata['sync_run']
                : null;

            if (($run['state'] ?? null) === 'running') {
                $metadata['sync_run'] = [
                    ...$run,
                    'state' => 'interrupted',
                    'finished_at' => $recoveredAt->toIso8601String(),
                    'recovered_at' => $recoveredAt->toIso8601String(),
                    'error' => 'The edge services were stopped during deployment; the abandoned sync lease was recovered.',
                ];
                $locked->forceFill(['metadata' => $metadata])->saveQuietly();
            }
        }, 3);

        $this->info("Recovered the sync run lock for paired local node {$nodeId}.");

        return self::SUCCESS;
    }
}
