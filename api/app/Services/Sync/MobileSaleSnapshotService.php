<?php

namespace App\Services\Sync;

use App\Models\SyncChange;
use App\Models\SyncNode;
use App\Models\SyncNodeCursor;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Models\PosTill;

final class MobileSaleSnapshotService
{
    public function __construct(
        private readonly SyncConfigurationService $configuration,
        private readonly SyncPayloadHasher $hasher
    ) {}

    public function resolve(
        SyncNode $node,
        Company $company,
        PosTill $till,
        mixed $claimedContentHash
    ): MobileSaleSnapshot {
        $hash = strtolower(trim(is_scalar($claimedContentHash) ? (string) $claimedContentHash : ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new MobileSaleSnapshotException(
                'The queued sale is missing a valid acknowledged configuration identity.',
                'MOBILE_CONFIGURATION_HASH_INVALID',
                422
            );
        }
        if ($node->deployment_mode !== 'android'
            || (string) $node->company_id !== (string) $company->id
            || (string) $node->branch_id !== (string) $till->branch_id
            || (string) $node->till_id !== (string) $till->id) {
            throw new MobileSaleSnapshotException(
                'The signed Android node does not match this company, branch and till.',
                'MOBILE_SALE_NODE_SCOPE_MISMATCH',
                403
            );
        }

        $cursor = SyncNodeCursor::query()
            ->where('sync_node_id', $node->id)
            ->where('stream', (string) config('salexa_sync.streams.changes', 'changes'))
            ->first();
        $acknowledged = strtolower((string) data_get($cursor?->metadata, 'acknowledged_content_hash', ''));
        if (! $cursor
            || ! (bool) data_get($cursor->metadata, 'acknowledgement_confirmed', false)
            || ! hash_equals($acknowledged, $hash)) {
            throw new MobileSaleSnapshotException(
                'The sale configuration was not acknowledged by this Android terminal. Synchronize configuration before creating more sales.',
                'MOBILE_CONFIGURATION_NOT_ACKNOWLEDGED',
                409
            );
        }

        $resourceType = $this->configuration->snapshotResourceType($node);
        $change = $this->configuration->newestSnapshotIn(
            SyncChange::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $till->branch_id)
                ->where('resource_type', $resourceType),
            $hash
        );
        if (! $change) {
            throw new MobileSaleSnapshotException(
                'The acknowledged sale configuration is no longer available on the cloud. Keep the receipt queued for administrator reconciliation.',
                'MOBILE_CONFIGURATION_SNAPSHOT_UNAVAILABLE',
                409
            );
        }

        $payload = is_array($change->payload) ? $change->payload : [];
        $datasets = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        if ((int) ($payload['schema_version'] ?? 0) !== 3) {
            throw new MobileSaleSnapshotException(
                'This acknowledged mobile configuration predates the required sale context. Synchronize configuration before selling.',
                'MOBILE_CONFIGURATION_RESYNC_REQUIRED',
                409
            );
        }
        $valid = (string) ($payload['company_id'] ?? '') === (string) $company->id
            && (string) ($payload['branch_id'] ?? '') === (string) $till->branch_id
            && (string) ($payload['till_id'] ?? '') === (string) $till->id
            && (string) ($payload['target_platform'] ?? '') === 'android'
            && hash_equals((string) $change->payload_hash, $this->hasher->hash($payload))
            && hash_equals($hash, $this->hasher->hash($datasets));
        if (! $valid) {
            throw new MobileSaleSnapshotException(
                'The retained mobile configuration failed its server integrity check.',
                'MOBILE_CONFIGURATION_INTEGRITY_FAILED',
                409
            );
        }

        return new MobileSaleSnapshot($payload, (string) $till->branch_id, (string) $till->id);
    }
}
