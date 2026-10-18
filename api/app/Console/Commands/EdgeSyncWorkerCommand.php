<?php

namespace App\Console\Commands;

use App\Models\SyncLocalState;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncRunAlreadyInProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EdgeSyncWorkerCommand extends Command
{
    protected $signature = 'sync:edge-worker
        {--once : Run one synchronization attempt and exit}
        {--interval= : Override the normal polling interval in seconds}';

    protected $description = 'Continuously synchronize a paired branch server, independently of Front Office browsers';

    public function handle(SyncOrchestrator $orchestrator): int
    {
        if (config('salexa_sync.deployment_mode') !== 'edge') {
            $this->error('The background synchronization worker may run only on an edge deployment.');

            return self::FAILURE;
        }

        $once = (bool) $this->option('once');
        $interval = $this->pollInterval();
        $failureCount = 0;

        while (true) {
            $state = SyncLocalState::query()->latest()->first();
            if (! $state || ! $state->node_id) {
                if ($once) {
                    $this->error('Pair the branch server before running synchronization.');

                    return self::FAILURE;
                }

                $this->sleepFor($interval);

                continue;
            }

            try {
                $orchestrator->run($state);
                $failureCount = 0;
                $this->recordHeartbeat($state, 'healthy');

                if ($once) {
                    return self::SUCCESS;
                }

                $this->sleepFor($interval);
            } catch (SyncRunAlreadyInProgress) {
                // A browser-triggered run and this service share the same node
                // lease. Let the active run finish without treating ordinary
                // contention as a failed synchronization.
                if ($once) {
                    return self::SUCCESS;
                }

                $this->sleepFor(min($interval, 5));
            } catch (Throwable $exception) {
                $failureCount++;
                $this->recordHeartbeat($state, 'degraded', $exception->getMessage());
                Log::warning('ServeNest edge background synchronization failed.', [
                    'node_id' => (string) $state->node_id,
                    'company_id' => (string) $state->company_id,
                    'branch_id' => (string) $state->branch_id,
                    'exception' => $exception,
                ]);

                if ($once) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                $this->sleepFor($this->retryDelay($failureCount, $interval));
            }
        }
    }

    private function pollInterval(): int
    {
        $configured = $this->option('interval');
        $seconds = $configured !== null && $configured !== ''
            ? (int) $configured
            : min(
                (int) config('salexa_sync.polling.push_interval_seconds', 30),
                (int) config('salexa_sync.polling.pull_interval_seconds', 30)
            );

        return max(5, $seconds);
    }

    private function retryDelay(int $failureCount, int $fallback): int
    {
        $backoff = array_values(array_filter(
            (array) config('salexa_sync.retry.backoff_seconds', []),
            static fn ($seconds): bool => is_numeric($seconds) && (int) $seconds > 0
        ));
        if ($backoff === []) {
            return $fallback;
        }

        return (int) $backoff[min(max(0, $failureCount - 1), count($backoff) - 1)];
    }

    private function recordHeartbeat(
        SyncLocalState $state,
        string $status,
        ?string $error = null
    ): void {
        DB::transaction(function () use ($state, $status, $error): void {
            $locked = SyncLocalState::query()->whereKey($state->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $worker = [
                'status' => $status,
                'heartbeat_at' => now()->toIso8601String(),
                'pid' => getmypid() ?: null,
            ];
            if ($error !== null && $error !== '') {
                $worker['last_error'] = mb_substr($error, 0, 500);
            }

            $locked->forceFill([
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'background_worker' => $worker,
                ],
            ])->saveQuietly();
        }, 3);
    }

    private function sleepFor(int $seconds): void
    {
        sleep(max(1, $seconds));
    }
}
