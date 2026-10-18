<?php

namespace App\Services\Sync;

use App\Models\SyncNode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class AndroidDayEndReadiness
{
    public function assess(string $companyId, string $branchId, bool $lock = false): array
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud') {
            return ['can_run_day_end' => true, 'blocking_devices' => []];
        }

        if (! Schema::hasTable('sync_nodes')) {
            return [
                'can_run_day_end' => false,
                'blocking_devices' => [['status' => 'unverifiable', 'reason' => 'Android device registry is unavailable.']],
            ];
        }

        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds(max(30, (int) config('salexa_sync.presence_stale_seconds', 180)));
        $query = SyncNode::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('deployment_mode', 'android')
            ->whereNotNull('activated_at')
            // A device leaves the accounting boundary only after explicit
            // administrator revocation has set both lifecycle fields.
            ->where(fn ($query) => $query->where('status', '!=', 'revoked')->orWhereNull('revoked_at'));
        if ($lock) {
            $query->lockForUpdate();
        }
        $devices = $query->get()
            ->map(fn (SyncNode $node): ?array => $this->blockingDevice($node, $cutoff, $now))
            ->filter()
            ->values()
            ->all();

        return ['can_run_day_end' => $devices === [], 'blocking_devices' => $devices];
    }

    public function blockingDevice(SyncNode $node, CarbonImmutable $cutoff, CarbonImmutable $now): ?array
    {
        $presence = data_get($node->metadata, 'presence', []);
        $name = (string) ($presence['device_name'] ?? $node->node_name ?? $node->node_code);
        $reasons = [];
        if ($node->status !== 'active') {
            $reasons[] = 'Device lifecycle is '.$node->status.'; review it in Device Management.';
        }
        try {
            $heartbeatAt = ! empty($presence['reported_at'])
                ? CarbonImmutable::parse((string) $presence['reported_at'])
                : null;
        } catch (\Throwable) {
            $heartbeatAt = null;
        }
        if (! $heartbeatAt || $heartbeatAt->lt($cutoff) || $heartbeatAt->gt($now->addSeconds(30))) {
            $reasons[] = 'Device is offline or its heartbeat is stale.';
        }
        if (! is_array($presence) || ! array_key_exists('pending_actions', $presence)) {
            $reasons[] = 'Pending actions have not been reported.';
        } elseif ((int) $presence['pending_actions'] > 0) {
            $reasons[] = $presence['pending_actions'].' local action(s) still need to sync.';
        }
        try {
            $lastSync = ! empty($presence['last_sync_at'])
                ? CarbonImmutable::parse((string) $presence['last_sync_at'])
                : null;
        } catch (\Throwable) {
            $lastSync = null;
        }
        if (! $lastSync || $lastSync->lt($cutoff) || $lastSync->gt($now->addSeconds(30))) {
            $reasons[] = 'Last successful sync is missing, stale, or invalid.';
        }

        return $reasons === [] ? null : [
            'node_id' => (string) $node->id,
            'node_name' => $name,
            'node_code' => (string) $node->node_code,
            'status' => (string) $node->status,
            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            'last_heartbeat_at' => $presence['reported_at'] ?? null,
            'last_sync_at' => $presence['last_sync_at'] ?? null,
            'pending_actions' => $presence['pending_actions'] ?? null,
            'reasons' => $reasons,
        ];
    }
}
