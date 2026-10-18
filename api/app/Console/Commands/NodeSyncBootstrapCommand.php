<?php

namespace App\Console\Commands;

use App\Models\SyncLocalState;
use App\Models\SyncOutboxEvent;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncTransportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class NodeSyncBootstrapCommand extends Command
{
    protected $signature = 'sync:node
        {action=status : status, pair, run, push, pull or reissue-local-tokens}
        {--cloud= : Cloud base URL}
        {--company-code= : Human company code}
        {--pairing-code= : One-time pairing code}
        {--node-name= : Friendly edge name}
        {--recovery-authorized : Explicitly authorize re-pairing with pending local state}';

    protected $description = 'Pair, inspect or manually synchronize an edge node (the service worker is separate)';

    public function handle(SyncTransportService $transport, SyncOrchestrator $orchestrator): int
    {
        try {
            return match (strtolower((string) $this->argument('action'))) {
                'status' => $this->status(),
                'pair' => $this->pair($transport),
                'run' => $this->runSync($orchestrator),
                'push' => $this->push($orchestrator),
                'pull' => $this->pull($orchestrator),
                'reissue-local-tokens' => $this->reissueLocalTokens(),
                default => $this->invalidAction(),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function status(): int
    {
        $state = SyncLocalState::query()->with('node')->latest()->first();
        if (! $state || ! $state->node_id) {
            $this->warn('Pairing required. Run sync:node pair with the cloud URL, company code and pairing code.');

            return self::FAILURE;
        }

        $configuredCloud = rtrim(trim((string) config('salexa_sync.cloud_url')), '/');
        $pairedCloud = rtrim(trim((string) $state->cloud_url), '/');

        $this->table(['Property', 'Value'], [
            ['Paired', 'yes'],
            ['Node', (string) ($state->node?->node_code ?: $state->node_id)],
            ['Company', (string) $state->company_id],
            ['Branch', (string) $state->branch_id],
            ['Till', (string) ($state->till_id ?: '-')],
            ['Paired cloud', (string) $state->cloud_url],
            ['Configured cloud', $configuredCloud !== '' ? $configuredCloud : '-'],
            ['State', (string) $state->status],
            ['Pending outbox', (string) SyncOutboxEvent::query()->whereNull('delivered_at')->count()],
            ['Pull cursor', (string) $state->pull_cursor],
            ['Last sync', $state->last_sync_at?->toIso8601String() ?: '-'],
            ['Last error', (string) ($state->last_error ?: '-')],
        ]);

        if ($configuredCloud !== '' && $configuredCloud !== $pairedCloud) {
            $this->warn(
                'The configured cloud differs from this installation\'s signed pairing. '
                .'Changing .env does not move an existing device; issue a recovery pairing code on the configured Central and re-pair it.'
            );
        }

        return self::SUCCESS;
    }

    private function pair(SyncTransportService $transport): int
    {
        $cloud = (string) ($this->option('cloud') ?: config('salexa_sync.cloud_url'));
        $companyCode = (string) $this->option('company-code');
        $pairingCode = (string) $this->option('pairing-code');
        if ($cloud === '' || $companyCode === '' || $pairingCode === '') {
            $this->error('The --cloud, --company-code and --pairing-code options are required.');

            return self::INVALID;
        }

        $state = $transport->pair(
            $cloud,
            $companyCode,
            $pairingCode,
            $this->option('node-name') ?: null,
            (bool) $this->option('recovery-authorized')
        );
        $this->info("Paired node {$state->node_id} to company {$state->company_id}, branch {$state->branch_id}.");
        $this->table(['Credential', 'One-time value'], array_values(array_filter([
            $state->getAttribute('pairing_local_control_token')
                ? ['Server recovery code', (string) $state->getAttribute('pairing_local_control_token')]
                : null,
            $state->getAttribute('pairing_till_token')
                ? ['Terminal recovery code', (string) $state->getAttribute('pairing_till_token')]
                : null,
        ])));

        return self::SUCCESS;
    }

    private function runSync(SyncOrchestrator $orchestrator): int
    {
        $result = $orchestrator->run();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function push(SyncOrchestrator $orchestrator): int
    {
        $result = $orchestrator->pushOnly($this->state());
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function pull(SyncOrchestrator $orchestrator): int
    {
        $result = $orchestrator->pullOnly($this->state());
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function state(): SyncLocalState
    {
        $state = SyncLocalState::query()->latest()->first();
        if (! $state) {
            throw new \RuntimeException('Pairing is required before synchronization.');
        }

        return $state;
    }

    private function reissueLocalTokens(): int
    {
        if (! $this->option('recovery-authorized')) {
            $this->error('Pass --recovery-authorized from the local server console to replace local installation tokens.');

            return self::INVALID;
        }

        $state = $this->state();
        $localControl = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state->forceFill([
            'local_control_token_hash' => hash('sha256', $localControl),
        ])->save();

        $rows = [['Server recovery code', $localControl]];
        if ($state->till_id) {
            $tillToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            DB::table('pos_tills')
                ->where('id', $state->till_id)
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->update([
                    'registration_token' => hash('sha256', $tillToken),
                    'is_registered' => true,
                    'registered_at' => now(),
                    'updated_at' => now(),
                ]);
            $rows[] = ['Terminal recovery code', $tillToken];
        }

        $this->warn('These replacement values are shown only once. Enter them immediately in the trusted local Front Office browser profile.');
        $this->table(['Credential', 'One-time value'], $rows);

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be one of: status, pair, run, push, pull, reissue-local-tokens.');

        return self::INVALID;
    }
}
