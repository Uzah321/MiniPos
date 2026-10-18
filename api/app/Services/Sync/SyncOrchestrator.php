<?php

namespace App\Services\Sync;

use App\Models\SyncLocalState;
use App\Models\SyncOutboxEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SyncOrchestrator
{
    public function __construct(
        private readonly SyncSaleBundleService $sales,
        private readonly SyncFrontStockClosingService $frontStockClosings,
        private readonly SyncTransportService $transport
    ) {}

    public function run(?SyncLocalState $state = null): array
    {
        $state ??= SyncLocalState::query()->latest()->first();
        if (! $state) {
            throw new RuntimeException('This edge installation has not been paired.');
        }

        return $this->withRunLock($state, 'full', function (SyncLocalState $state): array {
            $result = [
                'queued_sales' => 0,
                'queued_shift_cashups' => 0,
                'queued_front_stock_closings' => 0,
            ];
            $failures = [];

            try {
                $result['queued_sales'] = $this->queueCompletedSales($state);
            } catch (Throwable $exception) {
                $failures['sale_scan'] = $exception;
            }

            try {
                $result['queued_shift_cashups'] = $this->queueCompletedShiftCashups($state);
            } catch (Throwable $exception) {
                $failures['shift_cashup_scan'] = $exception;
            }

            try {
                $result['queued_front_stock_closings'] = $this->queueCompletedFrontStockClosings($state);
            } catch (Throwable $exception) {
                $failures['front_stock_closing_scan'] = $exception;
            }

            try {
                $result['push'] = $this->drainPush($state);
            } catch (Throwable $exception) {
                $failures['push'] = $exception;
            }

            // Upload and download are independent directions. In particular,
            // a failed upload must not leave an edge on stale permissions or
            // other configuration. Snapshot application separately protects
            // operational values while any local sale remains undelivered.
            try {
                $result['pull'] = $this->transport->pull($state->fresh());
            } catch (Throwable $exception) {
                $failures['pull'] = $exception;
            }

            $this->throwPhaseFailures($failures);

            return $result;
        });
    }

    public function pushOnly(?SyncLocalState $state = null): array
    {
        return $this->withRunLock($this->requiredState($state), 'push', fn (SyncLocalState $locked): array => [
            'push' => $this->drainPush($locked),
        ]);
    }

    public function pullOnly(?SyncLocalState $state = null): array
    {
        return $this->withRunLock($this->requiredState($state), 'pull', fn (SyncLocalState $locked): array => [
            'pull' => $this->transport->pull($locked),
        ]);
    }

    /**
     * Flush the complete branch ledger before Central executes Day End.
     *
     * The edge never mutates its own trading calendar independently. A local
     * operator initiates the command, but the signed cloud endpoint performs
     * the one authoritative close after sales, cashups and the branch-wide
     * FRONT closing have all been acknowledged. The resulting snapshot is
     * then pulled back into the shared branch database.
     *
     * @param  array<string, mixed>  $command
     * @param  null|callable(SyncLocalState):array{ready:bool,status:int,body:array<string,mixed>}  $localReadiness
     */
    public function runAuthoritativeDayEnd(
        ?SyncLocalState $state,
        array $command,
        ?callable $localReadiness = null
    ): array {
        return $this->withRunLock(
            $this->requiredState($state),
            'day_end',
            function (SyncLocalState $locked) use ($command, $localReadiness): array {
                $result = [
                    'queued_sales' => $this->queueCompletedSales($locked),
                    'queued_shift_cashups' => $this->queueCompletedShiftCashups($locked),
                    'queued_front_stock_closings' => $this->queueCompletedFrontStockClosings($locked),
                ];
                $result['push'] = $this->drainPush($locked);

                $pending = SyncOutboxEvent::query()
                    ->where('source_node_id', $locked->node_id)
                    ->where('company_id', $locked->company_id)
                    ->where('branch_id', $locked->branch_id)
                    ->whereNull('delivered_at')
                    ->count();
                if ($pending > 0) {
                    throw new RuntimeException(
                        "Day End is waiting for {$pending} branch operation(s) to reach Central Back Office. Retry after both synchronization indicators are green."
                    );
                }

                // Apply any Central changes before asking it to close the
                // currently authoritative date.
                $result['pre_command_pull'] = $this->transport->pull($locked->fresh());

                // Pull first so a closing captured by Central/Android is
                // visible locally. Then verify LAN-only facts (for example an
                // open no-sales shift) before the cloud command is allowed.
                if ($localReadiness !== null) {
                    $readiness = $localReadiness($locked->fresh());
                    if (! is_array($readiness)
                        || ! array_key_exists('ready', $readiness)
                        || ! is_array($readiness['body'] ?? null)) {
                        throw new RuntimeException('The branch Day End readiness result was invalid.');
                    }
                    $result['local_preflight'] = $readiness;
                    if (! (bool) $readiness['ready']) {
                        return $result;
                    }
                }

                $result['command'] = $this->transport->authoritativeDayEnd(
                    $locked->fresh(),
                    $command
                );

                // A successful command already committed on Central. A
                // transient download failure must not be presented as a
                // failed Day End; the always-on worker will self-heal it.
                if ((bool) ($result['command']['successful'] ?? false)) {
                    try {
                        $result['post_command_pull'] = $this->transport->pull($locked->fresh());
                        $result['snapshot_applied'] = true;
                    } catch (Throwable $exception) {
                        report($exception);
                        $result['snapshot_applied'] = false;
                        $result['snapshot_warning'] = Str::limit($exception->getMessage(), 500);
                    }
                }

                return $result;
            },
            // Do not add a second status round-trip after an authoritative
            // command. The command response and post-command pull are the
            // evidence; failure of optional telemetry cannot reverse it.
            false
        );
    }

    private function withRunLock(
        SyncLocalState $state,
        string $action,
        callable $operation,
        bool $checkRemoteStatus = true
    ): array
    {
        $lockSeconds = max(60, (int) config('salexa_sync.run_lock_seconds', 1800));
        $lock = Cache::lock('salexa-sync-run:'.(string) $state->node_id, $lockSeconds);
        if (! $lock->get()) {
            throw new SyncRunAlreadyInProgress;
        }

        $runId = (string) Str::uuid();
        $this->persistRunState($state, [
            'id' => $runId,
            'action' => $action,
            'state' => 'running',
            'started_at' => now()->toIso8601String(),
            'lease_expires_at' => now()->addSeconds($lockSeconds)->toIso8601String(),
            'finished_at' => null,
        ], [
            'last_attempt_at' => now()->toIso8601String(),
        ]);

        try {
            $result = $operation($state->fresh());
            $freshState = $state->fresh();
            $remoteStatus = $checkRemoteStatus
                ? $this->transport->remoteStatus($freshState)
                : null;
            $checkedAt = now();
            $terminalNodeState = in_array($freshState->status, ['revoked', 'suspended'], true)
                ? $freshState->status
                : null;
            $metadata = [
                ...($freshState->metadata ?? []),
                'last_remote_check_at' => $checkedAt->toIso8601String(),
                'sync_run' => [
                    'id' => $runId,
                    'action' => $action,
                    'state' => 'completed',
                    'started_at' => data_get($freshState->metadata, 'sync_run.started_at'),
                    'lease_expires_at' => data_get($freshState->metadata, 'sync_run.lease_expires_at'),
                    'finished_at' => $checkedAt->toIso8601String(),
                ],
            ];
            if ($remoteStatus !== null) {
                $metadata['remote_status'] = $remoteStatus;
            }
            $freshState->forceFill([
                'last_sync_at' => now(),
                'last_error_at' => $terminalNodeState ? $freshState->last_error_at : null,
                'last_error' => $terminalNodeState ? $freshState->last_error : null,
                'status' => $terminalNodeState ?: 'paired',
                'metadata' => $metadata,
            ])->save();

            $response = [
                ...$result,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
            if ($remoteStatus !== null) {
                $response['remote_status'] = $remoteStatus;
            }

            return $response;
        } catch (Throwable $exception) {
            $freshState = $state->fresh();
            $terminalNodeState = in_array($freshState->status, ['revoked', 'suspended'], true)
                ? $freshState->status
                : null;
            $freshState->forceFill([
                'last_error_at' => now(),
                'last_error' => $exception->getMessage(),
                'status' => $terminalNodeState ?: 'error',
                'metadata' => [
                    ...($freshState->metadata ?? []),
                    'sync_run' => [
                        'id' => $runId,
                        'action' => $action,
                        'state' => 'failed',
                        'started_at' => data_get($freshState->metadata, 'sync_run.started_at'),
                        'lease_expires_at' => data_get($freshState->metadata, 'sync_run.lease_expires_at'),
                        'finished_at' => now()->toIso8601String(),
                        'error' => Str::limit($exception->getMessage(), 500),
                    ],
                ],
            ])->save();
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function drainPush(SyncLocalState $state): array
    {
        $summary = ['sent' => 0, 'accepted' => 0, 'deferred' => 0, 'rejected' => 0];
        $passes = 0;
        do {
            $batch = $this->transport->push($state->fresh());
            foreach (array_keys($summary) as $key) {
                $summary[$key] += (int) ($batch[$key] ?? 0);
            }
            $passes++;
            $continue = (int) ($batch['sent'] ?? 0) > 0
                && (int) ($batch['accepted'] ?? 0) === (int) ($batch['sent'] ?? 0)
                && (int) ($batch['deferred'] ?? 0) === 0
                && (int) ($batch['rejected'] ?? 0) === 0;
        } while ($continue && $passes < 20);

        if ($passes >= 20 && SyncOutboxEvent::query()
            ->where('source_node_id', $state->node_id)
            ->where('company_id', $state->company_id)
            ->where('branch_id', $state->branch_id)
            ->whereNull('delivered_at')
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->exists()) {
            throw new RuntimeException(
                'The upload queue reached its safe batch limit and remains incomplete; run synchronization again.'
            );
        }

        return $summary;
    }

    private function queueCompletedSales(SyncLocalState $state): int
    {
        $queued = 0;
        $scanPasses = 0;
        do {
            $queuedThisPass = $this->sales->queueCompletedSales($state, 500);
            $queued += $queuedThisPass;
            $scanPasses++;
        } while ($queuedThisPass === 500 && $scanPasses < 20);

        if ($queuedThisPass === 500) {
            throw new RuntimeException(
                'The completed-sales recovery scan reached its safe limit; run synchronization again.'
            );
        }

        return $queued;
    }

    private function queueCompletedShiftCashups(SyncLocalState $state): int
    {
        $queued = 0;
        $scanPasses = 0;
        do {
            $queuedThisPass = $this->sales->queueCompletedShiftCashups($state, 500);
            $queued += $queuedThisPass;
            $scanPasses++;
        } while ($queuedThisPass === 500 && $scanPasses < 20);

        if ($queuedThisPass === 500) {
            throw new RuntimeException(
                'The completed shift-cashup recovery scan reached its safe limit; run synchronization again.'
            );
        }

        return $queued;
    }

    private function queueCompletedFrontStockClosings(SyncLocalState $state): int
    {
        // A branch closes FRONT stock once, regardless of which browser/till
        // captured it. The recovery scan turns that committed branch row into
        // an immutable, idempotent edge -> cloud event.
        return $this->frontStockClosings->queueCompletedClosings($state, 500);
    }

    /** @param array<string, Throwable> $failures */
    private function throwPhaseFailures(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $first = $failures[array_key_first($failures)];
        if (count($failures) === 1) {
            throw $first;
        }

        $details = [];
        foreach ($failures as $phase => $failure) {
            $details[] = "{$phase}: {$failure->getMessage()}";
        }

        throw new RuntimeException(
            'Synchronization phases failed ('.implode('; ', $details).').',
            0,
            $first
        );
    }

    private function requiredState(?SyncLocalState $state): SyncLocalState
    {
        $state ??= SyncLocalState::query()->latest()->first();
        if (! $state) {
            throw new RuntimeException('This edge installation has not been paired.');
        }

        return $state;
    }

    private function persistRunState(SyncLocalState $state, array $run, array $metadata = []): void
    {
        $freshState = $state->fresh();
        $freshState->forceFill([
            'metadata' => [
                ...($freshState->metadata ?? []),
                ...$metadata,
                'sync_run' => $run,
            ],
        ])->save();
    }
}
