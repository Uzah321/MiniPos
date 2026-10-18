<?php

namespace App\Http\Controllers;

use App\Models\SyncLocalState;
use App\Models\SyncOutboxEvent;
use App\Services\Sync\SyncEventSetProof;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncRunAlreadyInProgress;
use App\Services\Sync\SyncTransportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralSettings\Models\PosTill;
use RuntimeException;
use Throwable;

class EdgeSyncController extends Controller
{
    public function __construct(private readonly SyncEventSetProof $eventSetProof) {}

    public function status(Request $request): JsonResponse
    {
        $deploymentMode = (string) config('salexa_sync.deployment_mode', 'standalone');
        if ($deploymentMode === 'standalone') {
            return $this->standaloneStatus($request);
        }

        if ($deploymentMode !== 'edge') {
            return response()->json(['data' => [
                'paired' => false,
                'pairing_required' => false,
                'deployment_mode' => $deploymentMode,
                'synchronized' => false,
                'pos_to_cloud' => $this->unknownDirection('NOT_EDGE_DEPLOYMENT'),
                'cloud_to_pos' => $this->unknownDirection('NOT_EDGE_DEPLOYMENT'),
            ]]);
        }

        $configuredCloudOrigin = $this->maskedCloudUrl((string) config('salexa_sync.cloud_url'));
        $cloudUrlLocked = $configuredCloudOrigin !== null
            || ! config('salexa_sync.allow_unpinned_cloud_url', false);
        $state = SyncLocalState::query()->with(['node', 'company', 'branch', 'till'])->latest()->first();
        if (! $state || ! $state->node_id) {
            return response()->json(['data' => [
                'paired' => false,
                'pairing_required' => true,
                'deployment_mode' => 'edge',
                'synchronized' => false,
                'pending_uploads' => 0,
                'pending_downloads' => 1,
                'cloud_reachable' => false,
                'pos_to_cloud' => $this->unknownDirection('PAIRING_REQUIRED'),
                'cloud_to_pos' => $this->unknownDirection('PAIRING_REQUIRED'),
                'cloud_configured' => $configuredCloudOrigin !== null,
                'cloud_url_masked' => $configuredCloudOrigin,
                'cloud_url_locked' => $cloudUrlLocked,
                'configuration_error' => $configuredCloudOrigin === null && $cloudUrlLocked
                    ? 'SYNC_CLOUD_URL_NOT_CONFIGURED'
                    : null,
            ]]);
        }

        $nodeRevoked = $state->status === 'revoked'
            || $state->node?->status === 'revoked'
            || $state->node?->revoked_at !== null;
        if ($nodeRevoked) {
            return response()->json([
                'message' => 'The cloud sync node was revoked. Use an administrator-issued replacement pairing code for the same branch and till.',
                'code' => 'SYNC_NODE_REVOKED',
                'data' => [
                    'paired' => false,
                    'pairing_required' => true,
                    'replacement_required' => true,
                    'deployment_mode' => 'edge',
                    'status' => 'revoked',
                    'synchronized' => false,
                    'cloud_reachable' => true,
                    'pos_to_cloud' => $this->unknownDirection('NODE_REVOKED'),
                    'cloud_to_pos' => $this->unknownDirection('NODE_REVOKED'),
                    'cloud_configured' => $this->maskedCloudUrl((string) $state->cloud_url) !== null,
                    'cloud_url_masked' => $this->maskedCloudUrl((string) $state->cloud_url),
                    'cloud_url_locked' => true,
                    'last_error_at' => $state->last_error_at?->toIso8601String(),
                    'last_error' => $state->last_error,
                ],
            ]);
        }

        $authorization = $this->localAuthorization($request, $state);
        if ($authorization === null) {
            $pairedCloudOrigin = $this->maskedCloudUrl((string) $state->cloud_url)
                ?: $configuredCloudOrigin;

            return response()->json([
                'message' => 'This paired installation requires local device authorization.',
                'code' => 'SYNC_LOCAL_CONTROL_REQUIRED',
                'data' => [
                    'paired' => true,
                    'pairing_required' => false,
                    'deployment_mode' => 'edge',
                    'local_control_required' => true,
                    'synchronized' => false,
                    'cloud_reachable' => false,
                    'pos_to_cloud' => $this->unknownDirection('LOCAL_DEVICE_AUTHORIZATION_REQUIRED'),
                    'cloud_to_pos' => $this->unknownDirection('LOCAL_DEVICE_AUTHORIZATION_REQUIRED'),
                    'cloud_configured' => $pairedCloudOrigin !== null,
                    'cloud_url_masked' => $pairedCloudOrigin,
                    'cloud_url_locked' => true,
                ],
            ], 428);
        }

        /** @var PosTill|null $requestTill */
        $requestTill = $authorization['till'];
        $localOutbox = $this->localOutboxState($state);
        $pending = $localOutbox['pending'];
        $deadLetters = $localOutbox['dead_letters'];
        $localUploadProof = $localOutbox['proof'];
        $localUploadDigest = (string) $localUploadProof['digest'];

        $undiscoveredSales = $this->undiscoveredSales($state);
        $undiscoveredShiftCashups = $this->undiscoveredShiftCashups($state);
        $operationalBootstrapComplete = (bool) data_get(
            $state->metadata,
            'operational_bootstrap_complete',
            false
        );
        $configurationPending = (bool) data_get($state->metadata, 'configuration_pending', false)
            || ! $operationalBootstrapComplete;
        $requestTillReady = $requestTill
            && $requestTill->is_active
            && $requestTill->is_registered
            && trim((string) $requestTill->getRawOriginal('registration_token')) !== '';

        $remote = data_get($state->metadata, 'remote_status');
        $remoteCheckedAt = $this->date(data_get($state->metadata, 'last_remote_check_at'));
        $freshSeconds = max(1, (int) config('salexa_sync.polling.status_fresh_seconds', 180));
        $comparisonFresh = $remoteCheckedAt !== null
            && $remoteCheckedAt->greaterThanOrEqualTo(now()->subSeconds($freshSeconds));
        $identityMatches = is_array($remote)
            && (string) ($remote['node_id'] ?? '') === (string) $state->node_id
            && (string) ($remote['company_id'] ?? '') === (string) $state->company_id
            && (string) ($remote['branch_id'] ?? '') === (string) $state->branch_id;
        $cloudNodeActive = is_array($remote) && (string) ($remote['status'] ?? '') === 'active';
        $run = data_get($state->metadata, 'sync_run');
        $runLeaseExpiresAt = $this->date(is_array($run) ? ($run['lease_expires_at'] ?? null) : null);
        $runActive = is_array($run)
            && ($run['state'] ?? null) === 'running'
            && $runLeaseExpiresAt !== null
            && $runLeaseExpiresAt->isFuture();
        $comparisonKnown = $comparisonFresh && $identityMatches && $cloudNodeActive;
        $baseHealthy = $comparisonKnown
            && ! $runActive
            && (string) $state->status === 'paired'
            && $state->last_error === null;

        $cloudUpload = is_array($remote) && is_array($remote['pos_to_cloud'] ?? null)
            ? $remote['pos_to_cloud']
            : null;
        $uploadProofMatches = is_array($cloudUpload)
            && ($cloudUpload['proof_algorithm'] ?? null) === SyncEventSetProof::ALGORITHM
            && is_int($cloudUpload['acknowledged_events'] ?? null)
            && (int) $cloudUpload['acknowledged_events'] === (int) $localUploadProof['count']
            && is_string($cloudUpload['acknowledged_digest'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $cloudUpload['acknowledged_digest']) === 1
            && hash_equals($localUploadDigest, (string) $cloudUpload['acknowledged_digest']);
        $posToCloudEqual = $baseHealthy
            && $uploadProofMatches
            && $pending === 0
            && $deadLetters === 0
            && $undiscoveredSales === 0
            && $undiscoveredShiftCashups === 0
            && (int) ($cloudUpload['processing_events'] ?? -1) === 0
            && (int) ($cloudUpload['failed_events'] ?? -1) === 0;

        $cloudDownload = is_array($remote) && is_array($remote['cloud_to_pos'] ?? null)
            ? $remote['cloud_to_pos']
            : null;
        $localCursor = (int) $state->pull_cursor;
        $remoteHead = data_get($state->metadata, 'remote_configuration_head');
        $lastApplied = data_get($state->metadata, 'last_applied_change');
        $downloadProofMatches = $this->downloadProofMatches(
            $localCursor,
            $cloudDownload,
            is_array($remoteHead) ? $remoteHead : null,
            is_array($lastApplied) ? $lastApplied : null
        );
        $cloudToPosEqual = $baseHealthy
            && ! $configurationPending
            && $downloadProofMatches
            && (int) ($cloudDownload['pending_changes'] ?? -1) === 0;
        $synchronized = $posToCloudEqual && $cloudToPosEqual;

        return response()->json(['data' => [
            'paired' => true,
            'pairing_required' => false,
            'deployment_mode' => 'edge',
            'synchronized' => $synchronized,
            'configuration_pending' => $configurationPending,
            'operational_bootstrap_complete' => $operationalBootstrapComplete,
            'till_setup_required' => ! $requestTillReady,
            'sell_ready' => ! $configurationPending && (bool) $requestTillReady,
            'node_id' => (string) $state->node_id,
            'node_code' => (string) $state->node?->node_code,
            'company_id' => (string) $state->company_id,
            'company_code' => (string) $state->company?->company_code,
            'branch_id' => (string) $state->branch_id,
            'branch_name' => (string) $state->branch?->branch_name,
            'till_id' => $requestTill ? (string) $requestTill->id : null,
            'till_code' => $requestTill ? (string) $requestTill->till_code : null,
            'till_name' => $requestTill ? (string) $requestTill->till_name : null,
            'authorization_source' => (string) $authorization['source'],
            'cloud_url_masked' => $this->maskedCloudUrl((string) $state->cloud_url),
            'cloud_configured' => $this->maskedCloudUrl((string) $state->cloud_url) !== null,
            'cloud_url_locked' => true,
            'cloud_reachable' => $comparisonKnown,
            'comparison_fresh' => $comparisonFresh,
            'last_cloud_check_at' => $remoteCheckedAt?->toIso8601String(),
            'sync_running' => $runActive,
            'sync_run' => is_array($run) ? $run : null,
            'status' => (string) $state->status,
            // Compatibility fields remain fail-closed for older clients.
            'pending_uploads' => $pending + $deadLetters + $undiscoveredSales + $undiscoveredShiftCashups,
            'pending_downloads' => $cloudToPosEqual ? 0 : 1,
            'pending_events' => $pending,
            'undiscovered_sales' => $undiscoveredSales,
            'undiscovered_shift_cashups' => $undiscoveredShiftCashups,
            'dead_letter_events' => $deadLetters,
            'pos_to_cloud' => [
                'equal' => $posToCloudEqual,
                'comparison_known' => $comparisonKnown,
                'fresh' => $comparisonFresh,
                'running' => $runActive,
                'local_events' => (int) $localUploadProof['count'],
                'proof_algorithm' => SyncEventSetProof::ALGORITHM,
                'local_digest' => $localUploadDigest,
                'pending_events' => $pending,
                'dead_letter_events' => $deadLetters,
                'undiscovered_sales' => $undiscoveredSales,
                'undiscovered_shift_cashups' => $undiscoveredShiftCashups,
                'cloud_acknowledged_events' => is_array($cloudUpload)
                    ? (int) ($cloudUpload['acknowledged_events'] ?? -1)
                    : null,
                'cloud_acknowledged_digest' => is_array($cloudUpload)
                    ? ($cloudUpload['acknowledged_digest'] ?? null)
                    : null,
                'cloud_processing_events' => is_array($cloudUpload)
                    ? (int) ($cloudUpload['processing_events'] ?? -1)
                    : null,
                'cloud_failed_events' => is_array($cloudUpload)
                    ? (int) ($cloudUpload['failed_events'] ?? -1)
                    : null,
                'proof_matches' => $uploadProofMatches,
                'last_checked_at' => $remoteCheckedAt?->toIso8601String(),
            ],
            'cloud_to_pos' => [
                'equal' => $cloudToPosEqual,
                'comparison_known' => $comparisonKnown,
                'fresh' => $comparisonFresh,
                'running' => $runActive,
                'local_cursor' => $localCursor,
                'remote_head_cursor' => is_array($cloudDownload)
                    ? (int) ($cloudDownload['head_cursor'] ?? -1)
                    : null,
                'cloud_acknowledged_cursor' => is_array($cloudDownload)
                    ? (int) ($cloudDownload['acknowledged_cursor'] ?? -1)
                    : null,
                'acknowledgement_confirmed' => is_array($cloudDownload)
                    ? (bool) ($cloudDownload['acknowledgement_confirmed'] ?? false)
                    : false,
                'pending_changes' => is_array($cloudDownload)
                    ? (int) ($cloudDownload['pending_changes'] ?? -1)
                    : null,
                'proof_matches' => $downloadProofMatches,
                'last_checked_at' => $remoteCheckedAt?->toIso8601String(),
            ],
            'last_sync_at' => $state->last_sync_at?->toIso8601String(),
            'last_push_at' => $state->last_push_at?->toIso8601String(),
            'last_pull_at' => $state->last_pull_at?->toIso8601String(),
            'last_error_at' => $state->last_error_at?->toIso8601String(),
            'last_error' => $state->last_error,
            'pull_cursor' => $localCursor,
            'configuration_version' => $localCursor,
        ]]);
    }

    public function run(Request $request, SyncOrchestrator $orchestrator): JsonResponse
    {
        if (config('salexa_sync.deployment_mode') !== 'edge') {
            return response()->json([
                'message' => 'Manual synchronization is only available on an edge deployment.',
                'code' => 'SYNC_EDGE_ENDPOINT_UNAVAILABLE',
            ], 409);
        }

        $state = SyncLocalState::query()->with(['node', 'till'])->latest()->first();
        if (! $state || ! $state->node_id) {
            return response()->json([
                'message' => 'Pair this edge installation before running synchronization.',
                'code' => 'SYNC_PAIRING_REQUIRED',
            ], 409);
        }
        if ($state->status === 'revoked' || $state->node?->status === 'revoked') {
            return response()->json([
                'message' => 'The cloud sync node was revoked. Replace the node before synchronizing.',
                'code' => 'SYNC_NODE_REVOKED',
            ], 409);
        }
        $authorization = $this->localAuthorization($request, $state);
        if ($authorization === null) {
            return response()->json([
                'message' => 'This paired installation requires local device authorization.',
                'code' => 'SYNC_LOCAL_CONTROL_REQUIRED',
            ], 428);
        }

        // The branch-server service is the primary synchronization driver.
        // Several till browsers may share this API, so their automatic
        // fallback calls must not each start another complete cloud roundtrip
        // while the service heartbeat is healthy. A stale/missing worker falls
        // through to the same locked orchestrator, preserving offline recovery.
        if ($this->backgroundWorkerIsHealthy($state)) {
            /** @var PosTill|null $requestTill */
            $requestTill = $authorization['till'];

            return response()->json([
                'message' => 'Background synchronization is active.',
                'data' => [
                    'worker_managed' => true,
                    'worker_heartbeat_at' => data_get(
                        $state->metadata,
                        'background_worker.heartbeat_at'
                    ),
                    'requested_by_till_id' => $requestTill ? (string) $requestTill->id : null,
                ],
            ], 202);
        }

        try {
            $result = $orchestrator->run($state);
            /** @var PosTill|null $requestTill */
            $requestTill = $authorization['till'];

            return response()->json([
                'message' => 'Synchronization completed.',
                'data' => [
                    ...$result,
                    'requested_by_till_id' => $requestTill ? (string) $requestTill->id : null,
                ],
            ]);
        } catch (SyncRunAlreadyInProgress) {
            $freshState = $state->fresh();
            /** @var PosTill|null $requestTill */
            $requestTill = $authorization['till'];

            return response()->json([
                'message' => 'Synchronization is already in progress.',
                'data' => [
                    'already_running' => true,
                    'sync_run' => data_get($freshState->metadata, 'sync_run'),
                    'requested_by_till_id' => $requestTill ? (string) $requestTill->id : null,
                ],
            ], 202);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'SYNC_RUN_FAILED'], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Synchronization failed. The durable outbox remains available for retry.',
                'code' => 'SYNC_RUN_FAILED',
            ], 502);
        }
    }

    private function backgroundWorkerIsHealthy(SyncLocalState $state): bool
    {
        $worker = data_get($state->metadata, 'background_worker');
        if (! is_array($worker) || ($worker['status'] ?? null) !== 'healthy') {
            return false;
        }

        $heartbeat = $this->date($worker['heartbeat_at'] ?? null);
        if ($heartbeat === null) {
            return false;
        }

        $interval = max(5, min(
            (int) config('salexa_sync.polling.push_interval_seconds', 30),
            (int) config('salexa_sync.polling.pull_interval_seconds', 30)
        ));

        return $heartbeat->greaterThanOrEqualTo(
            now()->subSeconds(max(15, $interval * 3))
        );
    }

    public function redeemTerminalActivation(
        Request $request,
        SyncTransportService $transport
    ): JsonResponse {
        $deploymentMode = (string) config('salexa_sync.deployment_mode', 'standalone');
        if (! in_array($deploymentMode, ['edge', 'standalone'], true)) {
            return response()->json([
                'message' => 'Terminal activation is available only on an edge or standalone POS server.',
                'code' => 'SYNC_EDGE_ENDPOINT_UNAVAILABLE',
            ], 409);
        }

        $validated = $request->validate([
            'terminal_activation_code' => [
                'required',
                'string',
                'max:96',
            ],
        ]);
        $activationCode = strtoupper(trim((string) $validated['terminal_activation_code']));
        if (preg_match('/^[A-Z0-9_-]+\.[A-Z0-9-]+$/', $activationCode)) {
            return response()->json([
                'message' => 'This is a server pairing code, not a terminal activation code. Replace or re-pair the branch server first, then issue a TERM- code for this browser.',
                'code' => 'NODE_PAIRING_CODE_NOT_TERMINAL_ACTIVATION',
            ], 422);
        }
        if (! preg_match('/^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/', $activationCode)) {
            return response()->json([
                'message' => 'The terminal activation code format is invalid. Use the TERM- code issued for this browser terminal.',
                'code' => 'TERMINAL_ACTIVATION_CODE_FORMAT_INVALID',
            ], 422);
        }

        if ($deploymentMode === 'standalone') {
            return $this->redeemStandaloneTerminalActivation($request, $activationCode);
        }

        $state = SyncLocalState::query()->with('node')->latest()->first();
        if (! $state || ! $state->node_id) {
            return response()->json([
                'message' => 'Pair this edge installation before activating a terminal.',
                'code' => 'SYNC_PAIRING_REQUIRED',
            ], 409);
        }
        if ($state->status === 'revoked' || $state->node?->status === 'revoked') {
            return response()->json([
                'message' => 'The cloud sync node was revoked. Replace the branch server before activating terminals.',
                'code' => 'SYNC_NODE_REVOKED',
            ], 409);
        }

        try {
            $activation = $transport->redeemTerminalActivation(
                $state,
                $activationCode,
                $request->ip(),
                $request->userAgent()
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'TERMINAL_ACTIVATION_CODE_INVALID',
            ], 422);
        } catch (RuntimeException $exception) {
            $freshState = $state->fresh();
            if ($freshState?->status === 'revoked') {
                return response()->json([
                    'message' => 'The cloud sync node was revoked. Replace the branch server before activating terminals.',
                    'code' => 'SYNC_NODE_REVOKED',
                ], 409);
            }

            report($exception);

            return response()->json([
                'message' => 'Terminal activation could not be completed. Try again or request a new terminal activation code.',
                'code' => 'TERMINAL_ACTIVATION_FAILED',
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Terminal activation could not be completed. Try again or request a new terminal activation code.',
                'code' => 'TERMINAL_ACTIVATION_FAILED',
            ], 502);
        }

        /** @var PosTill $terminal */
        $terminal = $activation['terminal'];

        return response()->json([
            'message' => 'Terminal activated. Save the one-time setup value securely; it is shown only once.',
            'data' => [
                'terminal' => $terminal->only([
                    'id', 'company_id', 'branch_id', 'till_code', 'till_name', 'device_label',
                    'is_registered', 'is_active', 'registered_at',
                ]),
                'terminal_credential' => $activation['terminal_credential'],
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Report local-only readiness without inventing a cloud/node pairing. A
     * standalone response discloses tenant identity only after this browser
     * proves possession of a valid till credential.
     */
    private function standaloneStatus(Request $request): JsonResponse
    {
        $schemaReady = Schema::hasTable('companies')
            && Schema::hasTable('company_branches')
            && Schema::hasTable('pos_tills');
        $installationReady = $schemaReady && PosTill::query()
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->whereHas('branch', fn ($query) => $query
                ->whereColumn('company_branches.company_id', 'pos_tills.company_id')
                ->where('status', 'active'))
            ->exists();

        $rawTillToken = trim((string) $request->header('X-Till-Token'));
        $till = null;
        if ($installationReady && $rawTillToken !== '') {
            $till = PosTill::query()
                ->with(['company', 'branch'])
                ->where('registration_token', hash('sha256', $rawTillToken))
                ->where('is_registered', true)
                ->where('is_active', true)
                ->whereHas('company', fn ($query) => $query->where('status', 'active'))
                ->whereHas('branch', fn ($query) => $query
                    ->whereColumn('company_branches.company_id', 'pos_tills.company_id')
                    ->where('status', 'active'))
                ->first();
        }

        if ($till) {
            $seenAt = now();
            $till->recordPresence([
                'last_seen_at' => $seenAt,
                'last_ip_address' => $request->ip(),
                'last_user_agent' => $request->userAgent(),
            ], $seenAt->copy()->subSeconds(30));
        }

        $sellReady = $installationReady && $till !== null;

        return response()->json(['data' => array_filter([
            'paired' => $installationReady,
            'pairing_required' => false,
            'deployment_mode' => 'standalone',
            'local_only' => true,
            'synchronized' => false,
            'configuration_pending' => ! $installationReady,
            'operational_bootstrap_complete' => $installationReady,
            'till_setup_required' => $till === null,
            'sell_ready' => $sellReady,
            'status' => ! $installationReady
                ? 'local_configuration_required'
                : ($sellReady ? 'ready' : 'terminal_activation_required'),
            'authorization_source' => $till ? 'till_token' : null,
            'company_id' => $till ? (string) $till->company_id : null,
            'company_code' => $till ? (string) $till->company?->company_code : null,
            'company_name' => $till ? (string) $till->company?->company_name : null,
            'branch_id' => $till ? (string) $till->branch_id : null,
            'branch_code' => $till ? (string) $till->branch?->branch_code : null,
            'branch_name' => $till ? (string) $till->branch?->branch_name : null,
            'till_id' => $till ? (string) $till->id : null,
            'till_code' => $till ? (string) $till->till_code : null,
            'till_name' => $till ? (string) $till->till_name : null,
            'cloud_configured' => false,
            'cloud_reachable' => false,
            'pos_to_cloud' => $this->unknownDirection('LOCAL_ONLY'),
            'cloud_to_pos' => $this->unknownDirection('LOCAL_ONLY'),
        ], static fn ($value): bool => $value !== null)]);
    }

    /**
     * Redeem a local one-time capability and rotate the selected till's
     * browser credential atomically. No SyncLocalState, node secret, cloud
     * request or background worker participates in this standalone path.
     */
    private function redeemStandaloneTerminalActivation(
        Request $request,
        string $activationCode
    ): JsonResponse {
        if (! Schema::hasColumn('pos_tills', 'activation_code_expires_at')) {
            return response()->json([
                'message' => 'Run the local database migrations before activating a browser terminal.',
                'code' => 'STANDALONE_TERMINAL_ACTIVATION_NOT_CONFIGURED',
            ], 409);
        }

        $activatedAt = now();
        $rawCredential = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $codeDigest = substr(hash('sha256', strtoupper(trim($activationCode))), 0, 30);

        $terminal = DB::transaction(function () use (
            $activatedAt,
            $codeDigest,
            $rawCredential,
            $request
        ): ?PosTill {
            $till = PosTill::query()
                ->where('activation_code', $codeDigest)
                ->whereNotNull('activation_code_expires_at')
                ->where('activation_code_expires_at', '>', $activatedAt)
                ->where('is_active', true)
                ->whereHas('company', fn ($query) => $query->where('status', 'active'))
                ->whereHas('branch', fn ($query) => $query
                    ->whereColumn('company_branches.company_id', 'pos_tills.company_id')
                    ->where('status', 'active'))
                ->lockForUpdate()
                ->first();
            if (! $till) {
                return null;
            }

            $till->forceFill([
                'registration_token' => hash('sha256', $rawCredential),
                'activation_code' => null,
                'activation_code_expires_at' => null,
                'is_registered' => true,
                'registered_at' => $activatedAt,
                'last_seen_at' => $activatedAt,
                'last_ip_address' => $request->ip()
                    ? Str::limit((string) $request->ip(), 255, '')
                    : null,
                'last_user_agent' => $request->userAgent()
                    ? Str::limit((string) $request->userAgent(), 2000, '')
                    : null,
            ])->save();

            return $till->fresh(['company', 'branch']);
        }, 3);

        if (! $terminal) {
            return response()->json([
                'message' => 'The terminal activation code is invalid, expired, or already used.',
                'code' => 'TERMINAL_ACTIVATION_CODE_INVALID',
            ], 422);
        }

        return response()->json([
            'message' => 'Terminal activated on this standalone POS server.',
            'data' => [
                'terminal' => $terminal->only([
                    'id', 'company_id', 'branch_id', 'till_code', 'till_name',
                    'device_label', 'is_registered', 'is_active', 'registered_at',
                ]),
                'terminal_credential' => $rawCredential,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{pending: int, dead_letters: int, proof: array} */
    private function localOutboxState(SyncLocalState $state): array
    {
        return DB::transaction(function () use ($state): array {
            $locked = SyncLocalState::query()->whereKey($state->id)->lockForUpdate()->firstOrFail();
            $outbox = SyncOutboxEvent::query()
                ->where('source_node_id', $locked->node_id)
                ->where('company_id', $locked->company_id)
                ->where('branch_id', $locked->branch_id);
            $expectedCount = (clone $outbox)->count();
            $pending = (clone $outbox)
                ->whereNull('delivered_at')
                ->whereNotNull('available_at')
                ->count();
            $deadLetters = (clone $outbox)
                ->whereNull('delivered_at')
                ->whereNull('available_at')
                ->count();
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $proof = $metadata['outbox_event_set_proof'] ?? null;
            if ($this->eventSetProof->valid($proof)
                && (int) $proof['count'] === $expectedCount) {
                return [
                    'pending' => $pending,
                    'dead_letters' => $deadLetters,
                    'proof' => $proof,
                ];
            }

            // One-time upgrade/backfill. All subsequent status polls read the
            // constant-size accumulator instead of loading the full history.
            $proof = $this->eventSetProof->empty();
            foreach (SyncOutboxEvent::query()
                ->where('source_node_id', $locked->node_id)
                ->where('company_id', $locked->company_id)
                ->where('branch_id', $locked->branch_id)
                ->orderBy('id')
                ->cursor(['id', 'payload_hash']) as $event) {
                $proof = $this->eventSetProof->add(
                    $proof,
                    (string) $event->id,
                    (string) $event->payload_hash
                );
            }

            $locked->forceFill(['metadata' => [
                ...$metadata,
                'outbox_event_set_proof' => [
                    ...$proof,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]])->save();

            return [
                'pending' => $pending,
                'dead_letters' => $deadLetters,
                'proof' => $proof,
            ];
        }, 3);
    }

    private function downloadProofMatches(
        int $localCursor,
        ?array $cloud,
        ?array $observedHead,
        ?array $lastApplied
    ): bool {
        if (! is_array($cloud)
            || ! is_array($observedHead)
            || ! is_array($lastApplied)
            || ! ($cloud['acknowledgement_confirmed'] ?? false)
            || (int) ($cloud['head_cursor'] ?? -1) !== $localCursor
            || (int) ($cloud['acknowledged_cursor'] ?? -1) !== $localCursor
            || (int) ($observedHead['cursor'] ?? -1) !== $localCursor
            || (int) ($lastApplied['cursor'] ?? -1) !== $localCursor) {
            return false;
        }

        if ($localCursor === 0) {
            return true;
        }

        foreach ([
            'event_id' => ['head_event_id', 'acknowledged_event_id'],
            'payload_hash' => ['head_payload_hash', 'acknowledged_payload_hash'],
            'content_hash' => ['head_content_hash', 'acknowledged_content_hash'],
        ] as $localKey => $cloudKeys) {
            $localValue = strtolower((string) ($lastApplied[$localKey] ?? ''));
            $observedValue = strtolower((string) ($observedHead[$localKey] ?? ''));
            if ($localValue === '' || $observedValue === '' || ! hash_equals($localValue, $observedValue)) {
                return false;
            }
            foreach ($cloudKeys as $cloudKey) {
                $cloudValue = strtolower((string) ($cloud[$cloudKey] ?? ''));
                if ($cloudValue === '' || ! hash_equals($localValue, $cloudValue)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function undiscoveredSales(SyncLocalState $state): int
    {
        if (! Schema::hasTable('sales_transactions') || ! Schema::hasTable('sync_outbox_events')) {
            return 0;
        }

        return DB::table('sales_transactions as sales')
            ->where('sales.company_id', $state->company_id)
            ->where('sales.branch_id', $state->branch_id)
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($query) use ($state): void {
                $query->selectRaw('1')
                    ->from('sync_outbox_events as outbox')
                    ->whereColumn('outbox.aggregate_id', 'sales.id')
                    ->where('outbox.source_node_id', $state->node_id)
                    ->where('outbox.event_type', 'sale.completed');
            })
            ->count();
    }

    private function undiscoveredShiftCashups(SyncLocalState $state): int
    {
        if (! Schema::hasTable('pos_cashups')
            || ! Schema::hasTable('pos_user_shift_sessions')
            || ! Schema::hasTable('sync_outbox_events')) {
            return 0;
        }

        return DB::table('pos_cashups as cashups')
            ->join('pos_user_shift_sessions as shifts', function ($join): void {
                $join->on('shifts.id', '=', 'cashups.shift_session_id')
                    ->on('shifts.company_id', '=', 'cashups.company_id')
                    ->on('shifts.branch_id', '=', 'cashups.branch_id');
            })
            ->where('cashups.company_id', $state->company_id)
            ->where('cashups.branch_id', $state->branch_id)
            ->whereIn(DB::raw('LOWER(cashups.status)'), ['closed', 'approved'])
            ->where('shifts.status', 'closed')
            ->whereNotNull('shifts.closed_at')
            ->when(
                Schema::hasColumn('pos_cashups', 'deleted_at'),
                fn ($query) => $query->whereNull('cashups.deleted_at')
            )
            ->whereNotExists(function ($query) use ($state): void {
                $query->selectRaw('1')
                    ->from('sync_outbox_events as outbox')
                    ->whereColumn('outbox.aggregate_id', 'cashups.id')
                    ->where('outbox.source_node_id', $state->node_id)
                    ->where('outbox.event_type', 'shift.cashup.completed');
            })
            ->count();
    }

    /** @return array{source: string, till: PosTill|null}|null */
    private function localAuthorization(Request $request, SyncLocalState $state): ?array
    {
        $tillCredential = trim((string) $request->header('X-Till-Token'));
        if ($tillCredential !== '') {
            $till = PosTill::query()
                ->where('company_id', $state->company_id)
                ->where('branch_id', $state->branch_id)
                ->where('is_active', true)
                ->where('is_registered', true)
                ->where('registration_token', hash('sha256', $tillCredential))
                ->first();
            $assertedTillId = trim((string) $request->header('X-Till-ID'));
            if ($till && ($assertedTillId === '' || hash_equals((string) $till->id, $assertedTillId))) {
                app()->instance('till', $till);
                $request->attributes->set('resolved_till', $till);

                return ['source' => 'registered_till', 'till' => $till];
            }
        }

        $localCredential = trim((string) $request->header('X-Sync-Local-Control'));
        $storedLocalHash = trim((string) $state->local_control_token_hash);
        if ($localCredential !== '' && $storedLocalHash !== ''
            && hash_equals($storedLocalHash, hash('sha256', $localCredential))) {
            return ['source' => 'local_recovery', 'till' => $state->till];
        }

        return null;
    }

    private function unknownDirection(string $reason): array
    {
        return [
            'equal' => false,
            'comparison_known' => false,
            'fresh' => false,
            'running' => false,
            'reason' => $reason,
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function maskedCloudUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (! in_array($scheme, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, ['', '/'], true)
            || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        return $scheme.'://'.strtolower((string) $parts['host'])
            .($port !== null ? ':'.$port : '');
    }
}
