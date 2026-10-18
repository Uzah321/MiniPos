<?php

namespace App\Services\Sync;

use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Models\SyncOutboxEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralSettings\Models\PosTill;
use RuntimeException;
use Throwable;

class SyncTransportService
{
    public function __construct(
        private readonly SyncRequestSigner $signer,
        private readonly SyncPayloadHasher $hasher,
        private readonly SyncConfigurationService $configuration
    ) {}

    public function pair(
        string $cloudUrl,
        string $companyCode,
        string $pairingCode,
        ?string $nodeName = null,
        bool $recoveryAuthorized = false
    ): SyncLocalState {
        $this->assertEdgeMode();
        $cloudUrl = $this->normaliseCloudUrl($cloudUrl);
        $configuredCloud = trim((string) config('salexa_sync.cloud_url'));
        if ($configuredCloud === '' && ! config('salexa_sync.allow_unpinned_cloud_url', false)) {
            throw new InvalidArgumentException(
                'Set SERVENEST_SYNC_CLOUD_URL before pairing. Arbitrary runtime cloud origins are disabled.'
            );
        }
        if ($configuredCloud !== '' && rtrim($configuredCloud, '/') !== $cloudUrl) {
            throw new InvalidArgumentException('The cloud URL does not match this installation configuration.');
        }

        $existing = SyncLocalState::query()->with('node')->latest()->first();
        $pairingNodeCode = strtoupper(trim((string) Str::beforeLast(trim($pairingCode), '.')));
        $replacementNodeId = null;
        if ($existing?->node_id) {
            $boundNodeCode = strtoupper(trim((string) $existing->node?->node_code));
            if ($pairingNodeCode === '' || $boundNodeCode === '') {
                throw new RuntimeException(
                    'This installation has an incomplete local sync-node identity. Repair it from the local server console before pairing again.'
                );
            }
            if (! hash_equals($boundNodeCode, $pairingNodeCode)) {
                $replacementNodeId = (string) $existing->node_id;
            }
        }
        $pending = SyncOutboxEvent::query()->whereNull('delivered_at')->count();
        if ($pending > 0 && $replacementNodeId !== null) {
            throw new RuntimeException(
                "Node replacement is blocked while {$pending} outbox events are pending. "
                .'Export or reconcile them before replacing the revoked cloud node; they must never be silently reassigned.'
            );
        }
        if ($pending > 0 && ! $recoveryAuthorized) {
            throw new RuntimeException(
                "Re-pairing is blocked while {$pending} outbox events are pending. "
                .'Use the local server recovery command only after exporting or resolving them.'
            );
        }

        $response = $this->client()->post($cloudUrl.'/api/pos/sync/pair', [
            'company_code' => strtoupper(trim($companyCode)),
            'pairing_code' => trim($pairingCode),
            'node_name' => $nodeName ?: config('salexa_sync.node_name'),
            'app_version' => config('app.version'),
            ...($replacementNodeId ? ['replace_node_id' => $replacementNodeId] : []),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'Cloud node pairing failed.'));
        }

        $data = $response->json('data');
        if (! is_array($data)
            || ! is_array($data['node'] ?? null)
            || ! is_array($data['company'] ?? null)
            || ! is_array($data['branch'] ?? null)
            || ! is_string($data['node']['id'] ?? null)
            || ! Str::isUuid($data['node']['id'])
            || ! is_string($data['credential'] ?? null)) {
            throw new RuntimeException('The cloud returned an invalid pairing response.');
        }
        if ((string) ($data['branch']['company_id'] ?? '') !== (string) ($data['company']['id'] ?? '')
            || (is_array($data['till'] ?? null)
                && ((string) ($data['till']['company_id'] ?? '') !== (string) ($data['company']['id'] ?? '')
                    || (string) ($data['till']['branch_id'] ?? '') !== (string) ($data['branch']['id'] ?? '')))) {
            throw new RuntimeException('The cloud returned an invalid company, branch or till scope.');
        }
        if ($existing?->node_id
            && ((string) ($data['company']['id'] ?? '') !== (string) $existing->company_id
                || (string) ($data['branch']['id'] ?? '') !== (string) $existing->branch_id
                || (string) ($data['till']['id'] ?? '') !== (string) ($existing->till_id ?: ''))) {
            throw new RuntimeException(
                'The recovery response does not match this installation\'s immutable company, branch and till binding.'
            );
        }
        $remoteNodeId = (string) ($data['node']['id'] ?? '');
        $nodeChanged = $existing?->node_id
            && $remoteNodeId !== (string) $existing->node_id;
        if ($nodeChanged
            && ($replacementNodeId === null
                || ! is_string($data['replaced_node_id'] ?? null)
                || ! hash_equals($replacementNodeId, (string) $data['replaced_node_id']))) {
            throw new RuntimeException(
                'The cloud did not authorize this installation to replace its existing sync node.'
            );
        }
        if ($replacementNodeId !== null && ! $nodeChanged) {
            throw new RuntimeException('The cloud returned the existing node for a replacement pairing code.');
        }
        if (! hash_equals($pairingNodeCode, strtoupper((string) ($data['node']['node_code'] ?? '')))) {
            throw new RuntimeException('The cloud pairing response does not match the submitted node code.');
        }

        $localControlToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $state = DB::transaction(function () use (
            $data,
            $cloudUrl,
            $existing,
            $localControlToken,
            $replacementNodeId
        ): SyncLocalState {
            $lockedExisting = $existing;
            if ($replacementNodeId !== null) {
                // SyncSaleBundleService takes this same row lock before every
                // outbox append. Recheck both identity and the empty-outbox
                // precondition at the commit boundary so an event can neither
                // be stranded on the revoked UUID nor silently reassigned.
                $lockedExisting = SyncLocalState::query()
                    ->whereKey($existing?->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedExisting
                    || (string) $lockedExisting->node_id !== $replacementNodeId) {
                    throw new RuntimeException('The local node changed while replacement pairing was in progress.');
                }
                $pendingAtCommit = SyncOutboxEvent::query()->whereNull('delivered_at')->count();
                if ($pendingAtCommit > 0) {
                    throw new RuntimeException(
                        "Node replacement is blocked because {$pendingAtCommit} outbox events became pending while the replacement was being authorized. "
                        .'The cloud code was consumed; issue an authorized recovery code for the replacement node after reconciling these events.'
                    );
                }
            }

            $company = $data['company'];
            $branch = $data['branch'];
            $till = is_array($data['till'] ?? null) ? $data['till'] : null;
            $enterprise = is_array($data['enterprise_hierarchy'] ?? null)
                ? $data['enterprise_hierarchy']
                : [];
            $nodeData = $data['node'];
            $credential = (string) $data['credential'];

            if (is_array($enterprise['country'] ?? null)) {
                $this->upsertPublicPairingRow('countries', $enterprise['country'], [
                    'id', 'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code',
                    'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active',
                ]);
            }
            $this->upsertPublicPairingRow('companies', $company, [
                'id', 'country_id', 'company_code', 'company_name', 'location_address', 'city', 'country',
                'domain', 'host_ip', 'license_support_phone', 'logo', 'status',
            ]);
            if (is_array($enterprise['brand'] ?? null)) {
                $this->upsertPublicPairingRow('brands', $enterprise['brand'], [
                    'id', 'company_id', 'country_id', 'code', 'name', 'description',
                    'logo_path', 'logo_url', 'status', 'metadata',
                ]);
            }
            if (is_array($enterprise['complex'] ?? null)) {
                $this->upsertPublicPairingRow('complexes', $enterprise['complex'], [
                    'id', 'company_id', 'country_id', 'code', 'name',
                    'address', 'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata',
                ]);
            }
            $this->upsertPublicPairingRow('company_branches', $branch, [
                'id', 'company_id', 'branch_name', 'branch_code', 'branch_address', 'branch_city',
                'branch_country', 'level', 'is_main_branch', 'status',
            ]);
            if (is_array($enterprise['shop'] ?? null)) {
                $this->upsertPublicPairingRow('shops', $enterprise['shop'], [
                    'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id',
                    'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode',
                    'configuration_version', 'status', 'metadata',
                ]);
            }
            if ($till) {
                $this->upsertPublicPairingRow('pos_tills', $till, [
                    'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name',
                    'device_label', 'configuration_version', 'is_active',
                ]);
            }

            $shopId = (string) ($data['shop_id']
                ?? data_get($enterprise, 'shop.id')
                ?? ($till['shop_id'] ?? ''));
            $shopId = $shopId !== '' ? $shopId : null;

            $remoteNodeId = (string) $nodeData['id'];
            if ($replacementNodeId !== null) {
                $replacedNode = SyncNode::query()->lockForUpdate()->find($replacementNodeId);
                if (! $replacedNode || (string) $lockedExisting?->node_id !== (string) $replacedNode->id) {
                    throw new RuntimeException('The local node changed while replacement pairing was in progress.');
                }
                $replacedNode->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => $replacedNode->revoked_at ?: now(),
                    'credential_secret' => null,
                    'credential_hash' => null,
                    'metadata' => [
                        ...($replacedNode->metadata ?? []),
                        'replaced_by_node_id' => $remoteNodeId,
                        'replaced_at' => now()->toIso8601String(),
                    ],
                ])->save();
            }
            $node = SyncNode::query()->find($remoteNodeId);
            if (! $node) {
                $conflictingNode = SyncNode::query()
                    ->where('node_code', (string) $nodeData['node_code'])
                    ->first();
                if ($conflictingNode) {
                    throw new RuntimeException(
                        'The local node code exists with a different identifier. Repair the local node identity before pairing again.'
                    );
                }

                // SyncNode uses generated UUIDs and guards its primary key.
                // Assign the cloud UUID explicitly so signed requests name the
                // exact node the cloud activated instead of a new local UUID.
                $node = new SyncNode;
                $node->setAttribute($node->getKeyName(), $remoteNodeId);
            }
            $node->forceFill([
                'company_id' => (string) $company['id'],
                'branch_id' => (string) $branch['id'],
                'shop_id' => $shopId,
                'till_id' => $till ? (string) $till['id'] : null,
                'node_code' => (string) $nodeData['node_code'],
                'node_name' => (string) ($nodeData['node_name'] ?? config('salexa_sync.node_name')),
                'deployment_mode' => 'edge',
                'activated_at' => now(),
                'credential_secret' => $credential,
                'credential_hash' => hash('sha256', $credential),
                'credential_version' => (int) ($nodeData['credential_version'] ?? 1),
                'status' => 'active',
                'revoked_at' => null,
            ])->save();

            $state = $lockedExisting ?: new SyncLocalState;
            $stateMetadata = $lockedExisting?->metadata ?? [];
            if ($replacementNodeId !== null) {
                $stateMetadata = [
                    ...Arr::except($stateMetadata, [
                        'outbox_event_set_proof',
                        'remote_status',
                        'last_remote_check_at',
                        'sync_run',
                    ]),
                    'previous_node_id' => $replacementNodeId,
                    'node_replaced_at' => now()->toIso8601String(),
                ];
            }
            $state->forceFill([
                'node_id' => $node->id,
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'shop_id' => $node->shop_id,
                'till_id' => $node->till_id,
                'cloud_url' => $cloudUrl,
                'credential' => $credential,
                'local_control_token_hash' => hash('sha256', $localControlToken),
                'push_cursor' => $replacementNodeId !== null ? 0 : ($lockedExisting?->push_cursor ?? 0),
                'pull_cursor' => $lockedExisting?->pull_cursor ?? 0,
                'status' => 'paired',
                'last_error' => null,
                'last_error_at' => null,
                'metadata' => [
                    ...$stateMetadata,
                    'credential_version' => (int) $node->credential_version,
                    'paired_at' => now()->toIso8601String(),
                ],
            ])->save();

            return $state->fresh(['node', 'company', 'branch', 'shop', 'till']);
        });

        // A successful pair must leave the edge login-capable. Pull the first
        // signed configuration snapshot before reporting pairing success.
        try {
            $initialPull = $this->pull($state);
            $state->forceFill([
                'status' => 'paired',
                'metadata' => [
                    ...($state->metadata ?? []),
                    'configuration_pending' => false,
                    'initial_configuration_cursor' => $initialPull['cursor'],
                    'initial_configuration_applied_at' => now()->toIso8601String(),
                ],
            ])->save();
        } catch (\Throwable $exception) {
            // The cloud code has already been consumed. Preserve the valid
            // node credential and report a retriable configuration-pending
            // state instead of stranding the operator with an unusable code.
            $state->forceFill([
                'status' => 'error',
                'last_error_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 2000),
                'metadata' => [
                    ...($state->metadata ?? []),
                    'configuration_pending' => true,
                ],
            ])->save();
        }

        $state = $state->fresh(['node', 'company', 'branch', 'shop', 'till']);
        // Ephemeral, response-only value. The hash is persisted locally; the
        // raw token is intended for the paired desktop/browser secure store.
        $state->setAttribute('pairing_local_control_token', $localControlToken);
        if ($state->till_id) {
            // Till registration is edge-local machine identity. It is never
            // returned by the cloud or included in configuration snapshots.
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
            // Ephemeral, response-only value; never call save() after setting it.
            $state->setAttribute('pairing_till_token', $tillToken);
            $state->setRelation('till', $state->till?->fresh());
        }

        return $state;
    }

    /**
     * Redeem a cloud-issued capability using the paired node credential, then
     * create the actual browser credential locally. Neither the raw browser
     * credential nor its digest is sent to or received from the cloud.
     *
     * @return array{terminal: PosTill, terminal_credential: string}
     */
    public function redeemTerminalActivation(
        SyncLocalState $state,
        string $activationCode,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $this->assertEdgeMode();
        $this->assertPaired($state);

        $activationCode = strtoupper(trim($activationCode));
        if (! preg_match('/^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/', $activationCode)) {
            throw new InvalidArgumentException(
                'The terminal activation code is invalid, expired, or already used.'
            );
        }

        $response = $this->signedRequest(
            $state,
            'POST',
            '/api/pos/sync/terminal-activations/consume',
            ['terminal_activation_code' => $activationCode]
        );
        if (! $response->successful()) {
            if ($response->status() === 422
                && $response->json('code') === 'TERMINAL_ACTIVATION_CODE_INVALID') {
                throw new InvalidArgumentException(
                    'The terminal activation code is invalid, expired, or already used.'
                );
            }

            throw new RuntimeException('The cloud service could not complete terminal activation.');
        }

        $terminal = $response->json('data.terminal');
        if (! is_array($terminal)
            || ! is_string($terminal['id'] ?? null)
            || ! Str::isUuid($terminal['id'])
            || (string) ($terminal['company_id'] ?? '') !== (string) $state->company_id
            || (string) ($terminal['branch_id'] ?? '') !== (string) $state->branch_id
            || ! is_string($terminal['till_code'] ?? null)
            || trim((string) $terminal['till_code']) === ''
            || ! is_string($terminal['till_name'] ?? null)
            || trim((string) $terminal['till_name']) === ''
            || ! filter_var($terminal['is_active'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('The cloud returned an invalid terminal activation response.');
        }

        $rawCredential = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $activatedAt = now();
        $localTill = DB::transaction(function () use (
            $state,
            $terminal,
            $rawCredential,
            $activatedAt,
            $ipAddress,
            $userAgent
        ): PosTill {
            $this->upsertPublicPairingRow('pos_tills', $terminal, [
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name', 'device_label', 'is_active',
            ]);

            $updated = DB::table('pos_tills')
                ->where('id', $terminal['id'])
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->where('is_active', true)
                ->update([
                    'registration_token' => hash('sha256', $rawCredential),
                    'activation_code' => null,
                    'is_registered' => true,
                    'registered_by' => null,
                    'registered_at' => $activatedAt,
                    'last_seen_at' => $activatedAt,
                    'last_ip_address' => $ipAddress ? Str::limit($ipAddress, 255, '') : null,
                    'last_user_agent' => $userAgent ? Str::limit($userAgent, 2000, '') : null,
                    'deleted_at' => null,
                    'updated_at' => $activatedAt,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The activated terminal could not be stored on this edge.');
            }

            return PosTill::query()->findOrFail($terminal['id']);
        }, 3);

        return [
            'terminal' => $localTill,
            'terminal_credential' => $rawCredential,
        ];
    }

    public function push(SyncLocalState $state): array
    {
        $this->assertPaired($state);
        $limit = max(1, (int) config('salexa_sync.batch.push_size', 200));
        $events = SyncOutboxEvent::query()
            ->where('source_node_id', $state->node_id)
            ->where('company_id', $state->company_id)
            ->where('branch_id', $state->branch_id)
            ->whereNull('delivered_at')
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();
        if ($events->isEmpty()) {
            return ['sent' => 0, 'accepted' => 0, 'deferred' => 0, 'rejected' => 0];
        }

        $body = [
            'events' => $events->map(fn (SyncOutboxEvent $event) => [
                'id' => (string) $event->id,
                'aggregate_type' => (string) $event->aggregate_type,
                'aggregate_id' => (string) $event->aggregate_id,
                'event_type' => (string) $event->event_type,
                'schema_version' => (int) $event->schema_version,
                'payload' => $event->payload,
                'payload_hash' => (string) $event->payload_hash,
                'occurred_at' => $event->occurred_at?->toIso8601String() ?: now()->toIso8601String(),
            ])->all(),
        ];
        $response = $this->signedRequest($state, 'POST', '/api/pos/sync/push', $body);
        if (! $response->successful()) {
            $this->deferBatch($events, 'Cloud push failed: HTTP '.$response->status());
            throw new RuntimeException((string) ($response->json('message') ?: 'Cloud push failed.'));
        }

        $results = collect($response->json('data.results', []))->keyBy('event_id');
        $summary = ['sent' => $events->count(), 'accepted' => 0, 'deferred' => 0, 'rejected' => 0];
        foreach ($events as $event) {
            $result = $results->get((string) $event->id);
            $status = is_array($result) ? (string) ($result['status'] ?? 'deferred') : 'deferred';
            if (in_array($status, ['accepted', 'duplicate'], true)) {
                $event->forceFill([
                    'delivered_at' => now(),
                    'last_attempt_at' => now(),
                    'attempt_count' => ((int) $event->attempt_count) + 1,
                    'last_error' => null,
                ])->save();
                $summary['accepted']++;

                continue;
            }

            $retryable = (bool) (is_array($result) ? ($result['retryable'] ?? true) : true);
            $error = (string) (is_array($result) ? ($result['error'] ?? 'Cloud deferred event.') : 'Missing cloud receipt.');
            $this->deferEvent($event, $error, $retryable);
            $summary[$retryable ? 'deferred' : 'rejected']++;
        }

        $state->forceFill(['last_push_at' => now(), 'last_sync_at' => now(), 'status' => 'paired'])->save();

        return $summary;
    }

    public function pull(SyncLocalState $state): array
    {
        $this->assertPaired($state);
        $cursor = (int) $state->pull_cursor;
        $applied = 0;
        $pages = 0;
        $hasMore = false;
        $remoteHead = null;
        $acknowledgement = null;
        do {
            $query = [
                'cursor' => $cursor,
                'limit' => (int) config('salexa_sync.batch.pull_size', 500),
                ...$this->configurationAcknowledgementQuery($state, $cursor),
            ];
            $target = '/api/pos/sync/pull?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $response = $this->signedRequest($state, 'GET', $target);
            if (! $response->successful()) {
                throw new RuntimeException((string) ($response->json('message') ?: 'Cloud pull failed.'));
            }
            $changes = $response->json('data.changes', []);
            if (! is_array($changes)) {
                throw new RuntimeException('The cloud returned an invalid change stream.');
            }

            foreach ($changes as $change) {
                if (! is_array($change)
                    || ! is_array($change['payload'] ?? null)
                    || ! hash_equals((string) ($change['payload_hash'] ?? ''), $this->hasher->hash($change['payload']))) {
                    throw new RuntimeException('A cloud configuration change failed its payload integrity check.');
                }
                $changeCursor = (int) ($change['cursor'] ?? 0);
                if ($changeCursor <= $cursor) {
                    throw new RuntimeException('The cloud configuration cursor did not advance monotonically.');
                }
                // The cloud stamps edge snapshots with the configured schema
                // version (currently 2); envelope and payload must agree.
                $changeSchema = (int) ($change['schema_version'] ?? 0);
                if (($change['resource_type'] ?? null) !== 'configuration.snapshot'
                    || ($change['operation'] ?? null) !== 'snapshot'
                    || ! in_array($changeSchema, SyncConfigurationService::EDGE_SNAPSHOT_SCHEMA_VERSIONS, true)
                    || $changeSchema !== (int) ($change['payload']['schema_version'] ?? 0)) {
                    throw new RuntimeException('The cloud returned an unsupported configuration change.');
                }

                $this->configuration->applySnapshot($state, $change['payload']);
                $operationalBootstrapComplete = collect([
                    'product_costs',
                    'product_stocks',
                    'inventory_batches',
                    'inventory_location_balances',
                    'trading_days',
                ])->every(fn (string $dataset): bool => array_key_exists(
                    $dataset,
                    $change['payload']['datasets'] ?? []
                ));
                $cursor = $changeCursor;
                $state->forceFill([
                    'pull_cursor' => $cursor,
                    'last_pull_at' => now(),
                    'last_sync_at' => now(),
                    'metadata' => [
                        ...($state->metadata ?? []),
                        'configuration_pending' => false,
                        'operational_bootstrap_complete' => $operationalBootstrapComplete,
                        'last_applied_change' => [
                            'cursor' => $cursor,
                            'event_id' => (string) ($change['event_id'] ?? ''),
                            'payload_hash' => strtolower((string) $change['payload_hash']),
                            'content_hash' => strtolower((string) data_get(
                                $change,
                                'payload.content_hash',
                                ''
                            )),
                            'applied_at' => now()->toIso8601String(),
                        ],
                    ],
                ])->save();
                $applied++;
            }
            $hasMore = (bool) $response->json('data.has_more', false);
            $remoteHead = $this->remoteConfigurationHead((array) $response->json('data', []));
            $acknowledgement = [
                'cursor' => (int) $response->json('data.acknowledged_cursor', 0),
                'confirmed' => (bool) $response->json('data.acknowledgement_confirmed', false),
            ];
            $this->persistRemoteConfigurationHead($state, $remoteHead);
            $pages++;
        } while ($hasMore && $pages < 20);

        if ($hasMore) {
            throw new RuntimeException(
                'Cloud configuration synchronization exceeded the safe page limit and remains incomplete.'
            );
        }

        $state->forceFill([
            'last_pull_at' => now(),
            'metadata' => [
                ...($state->metadata ?? []),
                'last_cloud_contact_at' => now()->toIso8601String(),
            ],
        ])->save();

        return [
            'applied' => $applied,
            'cursor' => $cursor,
            'has_more' => false,
            'head' => $remoteHead,
            'acknowledgement' => $acknowledgement,
        ];
    }

    /**
     * Ask Central to execute a branch Day End after the edge upload has been
     * drained. The command is HMAC authenticated and its caller-supplied run
     * key is the cloud idempotency boundary, so a lost HTTP response can be
     * retried without closing the same trading date twice.
     *
     * @param  array<string, mixed>  $command
     * @return array{status:int, successful:bool, body:array<string, mixed>}
     */
    public function authoritativeDayEnd(SyncLocalState $state, array $command): array
    {
        $this->assertEdgeMode();
        $this->assertPaired($state);

        $response = $this->signedRequest(
            $state,
            'POST',
            '/api/pos/sync/day-end',
            $command,
            max(30, (int) config('salexa_sync.http.day_end_timeout_seconds', 300))
        );
        $body = $response->json();
        $validBody = is_array($body);
        if (! $validBody) {
            $body = [
                'message' => 'Central Back Office returned an invalid Day End response.',
                'code' => 'DAY_END_CLOUD_RESPONSE_INVALID',
            ];
        }

        return [
            'status' => $validBody ? $response->status() : 502,
            'successful' => $validBody && $response->successful(),
            'body' => $body,
        ];
    }

    public function remoteStatus(SyncLocalState $state): array
    {
        $this->assertPaired($state);
        // Presence is operational telemetry, not a synchronization commit.
        // A temporary monitoring failure must never roll back or mark an
        // otherwise successful sales/configuration run as failed.
        try {
            $this->reportWebTerminalPresence($state);
        } catch (Throwable) {
            // Best effort by design; node-status below remains authoritative
            // for data synchronization equality.
        }
        $cursor = (int) $state->pull_cursor;
        $query = [
            'cursor' => $cursor,
            ...$this->configurationAcknowledgementQuery($state, $cursor),
        ];
        $target = '/api/pos/sync/node-status?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $response = $this->signedRequest($state, 'GET', $target);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'Cloud status check failed.'));
        }

        $data = $response->json('data');
        if (! is_array($data)
            || (string) ($data['node_id'] ?? '') !== (string) $state->node_id
            || (string) ($data['company_id'] ?? '') !== (string) $state->company_id
            || (string) ($data['branch_id'] ?? '') !== (string) $state->branch_id
            || ! is_array($data['pos_to_cloud'] ?? null)
            || ! is_array($data['cloud_to_pos'] ?? null)
            || data_get($data, 'pos_to_cloud.proof_algorithm') !== SyncEventSetProof::ALGORITHM
            || ! is_int(data_get($data, 'pos_to_cloud.acknowledged_events'))
            || ! is_string(data_get($data, 'pos_to_cloud.acknowledged_digest'))
            || ! preg_match(
                '/^[a-f0-9]{64}$/',
                (string) data_get($data, 'pos_to_cloud.acknowledged_digest')
            )
            || ! is_int(data_get($data, 'cloud_to_pos.head_cursor'))
            || ! is_int(data_get($data, 'cloud_to_pos.acknowledged_cursor'))
            || ! is_bool(data_get($data, 'cloud_to_pos.acknowledgement_confirmed'))) {
            throw new RuntimeException('The cloud returned an invalid synchronization comparison.');
        }

        return $data;
    }

    private function reportWebTerminalPresence(SyncLocalState $state): void
    {
        $terminals = PosTill::query()
            ->where('company_id', $state->company_id)
            ->where('branch_id', $state->branch_id)
            ->where('is_registered', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'till_name', 'device_label', 'last_seen_at'])
            ->map(fn (PosTill $till): array => [
                'till_id' => (string) $till->id,
                'device_name' => (string) ($till->device_label ?: $till->till_name),
                'last_seen_at' => $till->last_seen_at?->toIso8601String(),
            ])
            ->all();

        $pendingActions = SyncOutboxEvent::query()
            ->where('source_node_id', $state->node_id)
            ->whereNull('delivered_at')
            ->count();
        $payload = [
            'platform' => 'web',
            'app_version' => (string) config('app.version'),
            'device_name' => (string) ($state->node?->node_name ?: config('salexa_sync.node_name')),
            'pending_actions' => $pendingActions,
            'last_pull_cursor' => (int) $state->pull_cursor,
            'last_sync_at' => $state->last_sync_at?->toIso8601String(),
            'terminals' => $terminals,
        ];

        $response = $this->signedRequest($state, 'POST', '/api/pos/sync/heartbeat', $payload);
        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('message') ?: 'Cloud terminal presence report failed.')
            );
        }
    }

    private function configurationAcknowledgementQuery(SyncLocalState $state, int $cursor): array
    {
        if ($cursor <= 0) {
            return [];
        }

        $proof = data_get($state->metadata, 'last_applied_change');
        if (! is_array($proof)
            || (int) ($proof['cursor'] ?? 0) !== $cursor
            || ! Str::isUuid((string) ($proof['event_id'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($proof['payload_hash'] ?? ''))) {
            return [];
        }

        $query = [
            'ack_event_id' => (string) $proof['event_id'],
            'ack_payload_hash' => strtolower((string) $proof['payload_hash']),
        ];
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($proof['content_hash'] ?? ''))) {
            $query['ack_content_hash'] = strtolower((string) $proof['content_hash']);
        }

        return $query;
    }

    private function remoteConfigurationHead(array $data): ?array
    {
        if (! array_key_exists('head_cursor', $data)) {
            return null;
        }

        $cursor = (int) $data['head_cursor'];
        $eventId = $data['head_event_id'] ?? null;
        $payloadHash = strtolower((string) ($data['head_payload_hash'] ?? ''));
        $contentHash = strtolower((string) ($data['head_content_hash'] ?? ''));
        if ($cursor < 0
            || ($cursor > 0 && (! is_string($eventId) || ! Str::isUuid($eventId)))
            || ($cursor > 0 && ! preg_match('/^[a-f0-9]{64}$/', $payloadHash))
            || ($contentHash !== '' && ! preg_match('/^[a-f0-9]{64}$/', $contentHash))) {
            throw new RuntimeException('The cloud returned an invalid authoritative configuration head.');
        }

        return [
            'cursor' => $cursor,
            'event_id' => $cursor > 0 ? (string) $eventId : null,
            'payload_hash' => $cursor > 0 ? $payloadHash : null,
            'content_hash' => $contentHash !== '' ? $contentHash : null,
            'observed_at' => now()->toIso8601String(),
        ];
    }

    private function persistRemoteConfigurationHead(SyncLocalState $state, ?array $head): void
    {
        if ($head === null) {
            return;
        }

        // When an older cloud response omits explicit acknowledgement fields,
        // the edge still records the head but never treats it as equality.
        $metadata = [
            ...($state->metadata ?? []),
            'remote_configuration_head' => $head,
        ];
        $lastApplied = data_get($metadata, 'last_applied_change');
        if (! is_array($lastApplied)
            && (int) $state->pull_cursor === (int) $head['cursor']
            && (int) $head['cursor'] === 0) {
            $metadata['last_applied_change'] = [
                'cursor' => 0,
                'event_id' => null,
                'payload_hash' => null,
                'content_hash' => null,
                'applied_at' => now()->toIso8601String(),
            ];
        }

        $state->forceFill(['metadata' => $metadata])->save();
    }

    private function signedRequest(
        SyncLocalState $state,
        string $method,
        string $target,
        ?array $payload = null,
        ?int $timeoutSeconds = null
    ): Response {
        $rawBody = $payload === null
            ? ''
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $credential = (string) $state->credential;
        $signature = $this->signer->signature(
            $method,
            $target,
            $timestamp,
            $nonce,
            $rawBody,
            $credential,
            (string) $state->node_id
        );
        $request = $this->client($timeoutSeconds)->withHeaders([
            'X-Sync-Node' => (string) $state->node_id,
            'X-Sync-Timestamp' => $timestamp,
            'X-Sync-Nonce' => $nonce,
            'X-Sync-Signature' => $signature,
        ]);
        if ($rawBody !== '') {
            $request = $request->withBody($rawBody, 'application/json');
        }

        $response = $request->send($method, rtrim((string) $state->cloud_url, '/').$target);
        $this->recordAuthoritativeNodeRejection($state, $response);

        return $response;
    }

    /**
     * Persist terminal cloud-side node state so the local status endpoint does
     * not continue claiming that a revoked installation is paired. The cloud
     * response comes from the pinned origin and names the already-bound UUID.
     */
    private function recordAuthoritativeNodeRejection(
        SyncLocalState $state,
        Response $response
    ): void {
        $remoteCode = (string) $response->json('code');
        $remoteState = match ($remoteCode) {
            'SYNC_NODE_REVOKED' => 'revoked',
            'SYNC_NODE_SUSPENDED' => 'suspended',
            default => null,
        };
        if ($remoteState === null) {
            return;
        }

        $message = $remoteState === 'revoked'
            ? 'The cloud has revoked this sync node. Pair this installation with an administrator-issued same-scope replacement code.'
            : 'The cloud has suspended this sync node. Ask an administrator to resume it.';
        $observedAt = now();

        DB::transaction(function () use ($state, $remoteState, $remoteCode, $message, $observedAt): void {
            $locked = SyncLocalState::query()->lockForUpdate()->find($state->id);
            if (! $locked || (string) $locked->node_id !== (string) $state->node_id) {
                return;
            }

            $locked->forceFill([
                'status' => $remoteState,
                'credential' => $remoteState === 'revoked' ? null : $locked->credential,
                'last_error' => $message,
                'last_error_at' => $observedAt,
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'remote_status' => [
                        'node_id' => (string) $locked->node_id,
                        'company_id' => (string) $locked->company_id,
                        'branch_id' => (string) $locked->branch_id,
                        'status' => $remoteState,
                        'code' => $remoteCode,
                    ],
                    'last_remote_check_at' => $observedAt->toIso8601String(),
                ],
            ])->save();

            $nodeValues = [
                'status' => $remoteState,
                'updated_at' => $observedAt,
            ];
            if ($remoteState === 'revoked') {
                $nodeValues += [
                    'revoked_at' => $observedAt,
                    'credential_secret' => null,
                    'credential_hash' => null,
                ];
            }
            SyncNode::query()->whereKey($locked->node_id)->update($nodeValues);
        }, 3);
    }

    private function client(?int $timeoutSeconds = null): PendingRequest
    {
        $verifyTls = (bool) config('salexa_sync.http.verify_tls', true);
        if (! $verifyTls && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('TLS verification can only be disabled in local or testing environments.');
        }

        $caBundle = trim((string) config('salexa_sync.http.ca_bundle', ''));
        if ($caBundle !== '') {
            if (! $verifyTls) {
                throw new RuntimeException('A custom sync CA bundle requires TLS verification to remain enabled.');
            }
            $resolvedBundle = realpath($caBundle);
            if ($resolvedBundle === false || ! is_file($resolvedBundle) || ! is_readable($resolvedBundle)) {
                throw new RuntimeException('The configured sync CA bundle is missing or unreadable.');
            }
            $verifyTls = $resolvedBundle;
        }

        return Http::connectTimeout(max(1, (int) config('salexa_sync.http.connect_timeout_seconds', 5)))
            ->timeout(max(1, $timeoutSeconds ?? (int) config('salexa_sync.http.timeout_seconds', 30)))
            ->withOptions(['verify' => $verifyTls])
            ->acceptJson();
    }

    private function deferBatch($events, string $error): void
    {
        foreach ($events as $event) {
            $this->deferEvent($event, $error, true);
        }
    }

    private function deferEvent(SyncOutboxEvent $event, string $error, bool $retryable): void
    {
        $attempt = ((int) $event->attempt_count) + 1;
        $backoff = config('salexa_sync.retry.backoff_seconds', [5, 15, 30, 60, 300]);
        $delay = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 300);
        $exhausted = $attempt >= max(1, (int) config('salexa_sync.retry.max_attempts', 20));
        $event->forceFill([
            'attempt_count' => $attempt,
            'last_attempt_at' => now(),
            'last_error' => Str::limit($error, 2000),
            'available_at' => ($retryable && ! $exhausted) ? now()->addSeconds($delay) : null,
        ])->save();
    }

    private function assertPaired(SyncLocalState $state): void
    {
        if (! $state->node_id || ! $state->company_id || ! $state->branch_id
            || ! $state->cloud_url || ! $state->credential
            || ! in_array($state->status, ['paired', 'error'], true)) {
            throw new RuntimeException('This edge installation is not paired to the cloud.');
        }
    }

    private function assertEdgeMode(): void
    {
        if (config('salexa_sync.deployment_mode') !== 'edge') {
            throw new RuntimeException('Pairing is only available on an edge deployment.');
        }
    }

    private function normaliseCloudUrl(string $value): string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            throw new InvalidArgumentException('Enter a cloud origin such as https://cloud.example.com, without a path or credentials.');
        }

        if (strtolower((string) $parts['scheme']) !== 'https'
            && ! $this->insecureHttpAllowedForHost((string) $parts['host'])) {
            throw new InvalidArgumentException('The cloud URL must use HTTPS. Insecure HTTP is limited to explicit local development.');
        }

        return $value;
    }

    private function insecureHttpAllowedForHost(string $host): bool
    {
        if (! config('salexa_sync.allow_insecure_http', false)
            || ! app()->environment(['local', 'testing'])) {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
    }

    private function upsertPublicPairingRow(string $table, array $row, array $allowed): void
    {
        $row = array_intersect_key($row, array_flip($allowed));
        if (! is_string($row['id'] ?? null) || ! Str::isUuid($row['id'])) {
            throw new RuntimeException("The cloud returned an invalid {$table} identifier.");
        }
        foreach ($row as $column => $value) {
            if (is_array($value) || is_object($value)) {
                $row[$column] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }
        $row['updated_at'] = now();
        if (! DB::table($table)->where('id', $row['id'])->exists()) {
            $row['created_at'] = now();
        }
        DB::table($table)->upsert([$row], ['id'], array_values(array_diff(array_keys($row), ['id', 'created_at'])));
    }
}
