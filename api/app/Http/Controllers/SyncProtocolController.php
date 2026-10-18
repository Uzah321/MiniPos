<?php

namespace App\Http\Controllers;

use App\Models\SyncChange;
use App\Models\SyncInboxReceipt;
use App\Models\SyncNode;
use App\Models\SyncNodeCursor;
use App\Models\SyncTerminalActivation;
use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanySettingService;
use App\Services\License\LicenseAccessService;
use App\Services\License\MobileLicenseEntitlementException;
use App\Services\License\MobileLicenseEntitlementService;
use App\Services\Shifts\ShiftPolicyService;
use App\Services\Sync\LocalHubCompanyScopeService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncEventSetProof;
use App\Services\Sync\SyncFrontStockClosingService;
use App\Services\Sync\SyncPayloadHasher;
use App\Services\Sync\SyncSaleImportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Product\Http\Controllers\MenuRecipeDailyStockReconciliationController;
use Modules\Product\Http\Controllers\TradingDayEndController;
use RuntimeException;
use Throwable;

class SyncProtocolController extends Controller
{
    public function __construct(
        private readonly SyncPayloadHasher $hasher,
        private readonly SyncSaleImportService $saleImporter,
        private readonly SyncFrontStockClosingService $frontStockClosingImporter,
        private readonly SyncConfigurationService $configuration,
        private readonly SyncEventSetProof $eventSetProof,
        private readonly MobileLicenseEntitlementService $mobileLicenseEntitlements
    ) {}

    /** Consume a management-issued code; this endpoint never creates nodes. */
    public function pair(Request $request): JsonResponse
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud') {
            return response()->json([
                'message' => 'Node activation is only available on a cloud deployment.',
                'code' => 'SYNC_CLOUD_ENDPOINT_UNAVAILABLE',
            ], 409);
        }

        $validated = $request->validate([
            'company_code' => ['required', 'string', 'max:40'],
            'pairing_code' => ['required', 'string', 'max:255'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'target_platform' => ['nullable', 'string', 'in:edge,android'],
            'license_contract_version' => ['nullable', 'required_if:target_platform,android', 'integer', 'min:1', 'max:65535'],
            'supported_license_key_ids' => ['nullable', 'required_if:target_platform,android', 'array', 'min:1', 'max:16'],
            'supported_license_key_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'supported_license_algorithms' => ['nullable', 'required_if:target_platform,android', 'array', 'min:1', 'max:4'],
            'supported_license_algorithms.*' => ['required', 'string', 'max:32', 'distinct'],
            'supported_license_key_fingerprints' => ['nullable', 'required_if:target_platform,android', 'array', 'min:1', 'max:16'],
            'supported_license_key_fingerprints.*' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            // A stable application-installation identifier, not a hardware
            // serial. Only its digest is retained by the cloud.
            'device_fingerprint' => ['nullable', 'required_if:target_platform,android', 'string', 'min:16', 'max:255'],
            // An edge that is still carrying a revoked node identity may
            // replace it without discarding its same-scope local database.
            // The cloud remains authoritative: the named node must already
            // be revoked and the new code must bind the exact same tenant,
            // branch and till.
            'replace_node_id' => ['nullable', 'uuid'],
        ]);
        $targetPlatform = (string) ($validated['target_platform'] ?? 'edge');
        if ((bool) config('salexa_sync.local_hub', false) && $targetPlatform === 'android') {
            return response()->json([
                'message' => 'Android terminals must pair with Central; this local Back Office supports web edge nodes only.',
                'code' => 'SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE',
            ], 409);
        }
        if ((bool) config('salexa_sync.local_hub', false)
            && app(LocalHubCompanyScopeService::class)->provisionedCompanyId() === null) {
            return response()->json([
                'message' => 'This local Back Office is not provisioned for exactly one company.',
                'code' => 'LOCAL_HUB_COMPANY_UNAVAILABLE',
            ], 409);
        }
        $company = Company::query()
            ->where('company_code', strtoupper(trim($validated['company_code'])))
            ->where('status', 'active')
            ->first();
        if (! $company) {
            return response()->json(['message' => 'Company or pairing code is invalid.'], 401);
        }

        $pairingCode = trim($validated['pairing_code']);
        $separator = strrpos($pairingCode, '.');
        $nodeCode = $separator === false ? '' : substr($pairingCode, 0, $separator);
        $replacementNodeId = $validated['replace_node_id'] ?? null;
        $serverDerivedReplacement = false;
        if ($targetPlatform === 'android' && empty($replacementNodeId) && $nodeCode !== '') {
            // A rebuilt Android installation no longer has the revoked node
            // UUID that authorized its successor. Resolve that server-owned
            // grant before the transaction so the revoked row can still be
            // locked before the candidate, matching management issuance.
            $candidateGrant = SyncNode::query()
                ->where('company_id', $company->id)
                ->where('node_code', strtoupper($nodeCode))
                ->where('deployment_mode', 'android')
                ->where('status', 'pending')
                ->whereNull('revoked_at')
                ->whereNotNull('activation_code_hash')
                ->where('activation_expires_at', '>', now())
                ->first(['id', 'activation_code_hash', 'metadata']);
            $declaredReplacementId = (string) data_get(
                $candidateGrant?->metadata,
                'replaces_node_id',
                ''
            );
            if ($candidateGrant
                && Str::isUuid($declaredReplacementId)
                && (bool) data_get($candidateGrant->metadata, 'replacement_authorized', false)
                && Hash::check($pairingCode, (string) $candidateGrant->activation_code_hash)) {
                $replacementNodeId = $declaredReplacementId;
                $serverDerivedReplacement = true;
            }
        }
        try {
            $activation = DB::transaction(function () use (
                $company,
                $nodeCode,
                $pairingCode,
                $validated,
                $request,
                $targetPlatform,
                $replacementNodeId,
                $serverDerivedReplacement
            ): ?array {
                // The activation code is a one-time credential. Validate and
                // consume it while holding the node row lock so two concurrent
                // requests cannot both rotate the node secret successfully.
                $replacement = null;
                if (! empty($replacementNodeId)) {
                    // Use the revoked node as the common grant lock before the
                    // candidate lock; management issuance follows the same order.
                    $replacement = SyncNode::query()
                        ->where('company_id', $company->id)
                        ->lockForUpdate()
                        ->find($replacementNodeId);
                }
                $node = SyncNode::query()
                    ->where('company_id', $company->id)
                    ->where('node_code', strtoupper($nodeCode))
                    ->whereNotNull('activation_code_hash')
                    ->lockForUpdate()
                    ->first();
                $node?->load(['branch', 'till']);

                $fingerprintHash = $targetPlatform === 'android'
                    ? hash('sha256', trim((string) ($validated['device_fingerprint'] ?? '')))
                    : null;
                $storedFingerprintHash = (string) data_get(
                    $node?->metadata,
                    'device_fingerprint_hash',
                    ''
                );
                $activatedNodeName = trim((string) (
                    $targetPlatform === 'android'
                        ? $node?->node_name
                        : ($validated['node_name'] ?? $node?->node_name)
                ));
                $duplicateNodeName = $node && $activatedNodeName !== ''
                    ? SyncNode::query()
                        ->where('company_id', $company->id)
                        ->where('branch_id', $node->branch_id)
                        ->where('id', '!=', $node->id)
                        ->whereNull('revoked_at')
                        ->where('status', '!=', 'revoked')
                        ->whereRaw('LOWER(node_name) = ?', [strtolower($activatedNodeName)])
                        ->exists()
                    : true;
                $duplicateTillName = $node && $activatedNodeName !== ''
                    ? PosTill::query()
                        ->where('company_id', $company->id)
                        ->where('branch_id', $node->branch_id)
                        ->when($node->till_id, fn ($query) => $query->where('id', '!=', $node->till_id))
                        ->whereRaw('LOWER(till_name) = ?', [strtolower($activatedNodeName)])
                        ->exists()
                    : true;

                $recoveryAuthorized = $node
                    && in_array($node->status, ['active', 'suspended'], true)
                    && (bool) data_get($node->metadata, 'recovery_authorized', false);
                $replacementRecorded = $node
                    && $replacement
                    && hash_equals(
                        (string) $replacement->id,
                        (string) data_get($node->metadata, 'replaces_node_id', '')
                    );
                $reservedCandidateId = (string) data_get(
                    $replacement?->metadata,
                    'replacement_candidate_node_id',
                    ''
                );
                if ($node
                    && $replacement
                    && $reservedCandidateId === ''
                    && ! $serverDerivedReplacement
                    && $node->status === 'pending'
                    && $replacement->status === 'revoked'
                    && $replacement->revoked_at
                    && $replacementRecorded
                    && (string) $replacement->branch_id === (string) $node->branch_id
                    && (string) ($replacement->till_id ?: '') === (string) ($node->till_id ?: '')
                    && (bool) data_get($node->metadata, 'replacement_authorized', false)) {
                    // Compatibility for a grant issued immediately before the
                    // one-successor fence was deployed: adopt it only when it is
                    // the sole live candidate for this revoked node.
                    $liveSuccessors = SyncNode::query()
                        ->where('company_id', $company->id)
                        ->where('branch_id', $node->branch_id)
                        ->whereIn('status', ['pending', 'active'])
                        ->lockForUpdate()
                        ->get()
                        ->filter(fn (SyncNode $candidate): bool => hash_equals(
                            (string) $replacement->id,
                            (string) data_get($candidate->metadata, 'replaces_node_id', '')
                        ));
                    if ($liveSuccessors->count() === 1
                        && (string) $liveSuccessors->first()->id === (string) $node->id) {
                        $replacement->forceFill(['metadata' => [
                            ...($replacement->metadata ?? []),
                            'replacement_candidate_node_id' => (string) $node->id,
                            'replacement_grant_adopted_at' => now()->toIso8601String(),
                        ]])->save();
                        $reservedCandidateId = (string) $node->id;
                    }
                }
                $replacementGrantMatches = $node
                    && $replacement
                    && hash_equals(
                        (string) $node->id,
                        $reservedCandidateId
                    );
                $replacementAuthorized = $node
                    && $replacement
                    && (string) $replacement->id !== (string) $node->id
                    && $replacement->status === 'revoked'
                    && $replacement->revoked_at
                    && (string) $replacement->branch_id === (string) $node->branch_id
                    && (string) ($replacement->till_id ?: '') === (string) ($node->till_id ?: '')
                    && $replacementGrantMatches
                    && (($node->status === 'pending'
                            && $replacementRecorded
                            && (bool) data_get($node->metadata, 'replacement_authorized', false))
                        || ($recoveryAuthorized && $replacementRecorded));

                $declaredReplacementId = (string) data_get($node?->metadata, 'replaces_node_id', '');
                $pendingReplacementDeclared = $node
                    && $node->status === 'pending'
                    && $declaredReplacementId !== '';
                if (! $node
                    || $node->revoked_at
                    || ($pendingReplacementDeclared && empty($replacementNodeId))
                    || (! empty($replacementNodeId) && ! $replacementAuthorized)
                    || (empty($replacementNodeId)
                        && $node->status !== 'pending'
                        && ! $recoveryAuthorized)
                    || ! $node->activation_expires_at
                    || $node->activation_expires_at->isPast()
                    || ! Hash::check($pairingCode, (string) $node->activation_code_hash)
                    || ! $node->branch
                    || (string) $node->branch->company_id !== (string) $company->id
                    || (string) $node->deployment_mode !== $targetPlatform
                    || ($targetPlatform === 'android' && ! $node->till)
                    || ($targetPlatform === 'android'
                        && $storedFingerprintHash !== ''
                        && ! hash_equals($storedFingerprintHash, (string) $fingerprintHash))
                    || $duplicateNodeName
                    || $duplicateTillName) {
                    return null;
                }

                $licenseDelivery = null;
                if ($targetPlatform === 'android') {
                    // Preflight the signed offline entitlement after validating the
                    // one-time grant, but before clearing it or rotating either
                    // credential. Any exception rolls the whole activation back.
                    $licenseDelivery = $this->mobileLicenseEntitlements->issue($company);
                    $this->mobileLicenseEntitlements->assertClientCompatibility(
                        $licenseDelivery['license_entitlement'],
                        (int) $validated['license_contract_version'],
                        $validated['supported_license_key_ids'],
                        $validated['supported_license_algorithms'],
                        $validated['supported_license_key_fingerprints']
                    );
                }

                $credential = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                $tillToken = null;
                if ($targetPlatform === 'android') {
                    $tillToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                    $node->till->forceFill([
                        'registration_token' => hash('sha256', $tillToken),
                        'is_registered' => true,
                        'registered_at' => now(),
                        'last_seen_at' => now(),
                        'last_ip_address' => $request->ip(),
                        'last_user_agent' => $request->userAgent(),
                    ])->save();
                }
                $credentialVersion = $node->status === 'pending'
                    ? max(1, (int) $node->credential_version)
                    : ((int) $node->credential_version) + 1;
                $node->forceFill([
                    // Android terminal names are selected centrally and cannot
                    // be changed by the device consuming the one-time code.
                    'node_name' => $activatedNodeName,
                    'app_version' => $validated['app_version'] ?? $node->app_version,
                    'activation_code_hash' => null,
                    'activation_expires_at' => null,
                    'activated_at' => now(),
                    'credential_secret' => $credential,
                    'credential_hash' => hash('sha256', $credential),
                    'credential_version' => $credentialVersion,
                    'status' => 'active',
                    'last_seen_at' => now(),
                    'metadata' => [
                        ...($node->metadata ?? []),
                        'recovery_authorized' => false,
                        'replacement_authorized' => false,
                        'client_platform' => $targetPlatform === 'android' ? 'android' : 'web',
                        ...($fingerprintHash ? ['device_fingerprint_hash' => $fingerprintHash] : []),
                        ...($replacement ? [
                            'replaces_node_id' => (string) $replacement->id,
                            'replacement_authorized_at' => now()->toIso8601String(),
                        ] : []),
                        'paired_at' => now()->toIso8601String(),
                    ],
                ])->save();
                if ($replacement) {
                    $replacement->forceFill(['metadata' => [
                        ...($replacement->metadata ?? []),
                        'replacement_candidate_node_id' => (string) $node->id,
                        'replaced_by_node_id' => (string) $node->id,
                        'replacement_grant_consumed_at' => now()->toIso8601String(),
                    ]])->save();
                }

                return [
                    $node->fresh(['branch', 'shop.country', 'shop.brand', 'shop.complex', 'till']),
                    $credential,
                    $replacement ? (string) $replacement->id : null,
                    $tillToken,
                    $licenseDelivery,
                ];
            }, 3);
        } catch (MobileLicenseEntitlementException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'code' => $error->errorCode,
            ], $error->httpStatus)
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        }

        if (! $activation) {
            return response()->json(['message' => 'Company or pairing code is invalid.'], 401);
        }
        [$node, $credential, $replacedNodeId, $tillToken, $licenseDelivery] = $activation;

        return response()->json([
            'message' => 'Secure node connection established.',
            'data' => [
                'node' => [
                    'id' => (string) $node->id,
                    'node_code' => (string) $node->node_code,
                    'node_name' => (string) $node->node_name,
                    'credential_version' => (int) $node->credential_version,
                    'target_platform' => (string) $node->deployment_mode,
                ],
                'credential' => $credential,
                'till_token' => $tillToken,
                'replaced_node_id' => $replacedNodeId,
                'company' => $this->publicCompany($company),
                'branch' => $this->publicBranch($node->branch),
                'till' => $node->till ? $this->publicTill($node->till) : null,
                'shop_id' => $node->shop_id ? (string) $node->shop_id : null,
                'enterprise_hierarchy' => $this->publicEnterpriseHierarchy($node),
                'license_entitlement' => $licenseDelivery['license_entitlement'] ?? null,
                'license' => $licenseDelivery['license'] ?? null,
                'server_date' => $licenseDelivery['server_date'] ?? now()->toDateString(),
            ],
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * Deliver only the signed Android license projection. This node-authenticated
     * path deliberately has no cashier or license middleware so an expired,
     * logged-out terminal can install a renewal (or learn of a revocation)
     * without advancing its catalogue cursor or acknowledgement.
     */
    public function mobileLicense(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }
        if ((bool) config('salexa_sync.local_hub', false)) {
            return response()->json([
                'message' => 'Android license delivery is unavailable from a local Back Office. Connect this terminal to Central.',
                'code' => 'SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE',
            ], 409);
        }

        /** @var SyncNode $node */
        $node = app('sync.node');
        if ($node->deployment_mode !== 'android') {
            return response()->json([
                'message' => 'Signed mobile license refresh is available only to Android terminals.',
                'code' => 'SYNC_ANDROID_NODE_REQUIRED',
            ], 403);
        }

        $company = Company::query()->find($node->company_id);
        if (! $company) {
            return response()->json([
                'message' => 'The paired company is unavailable.',
                'code' => 'SYNC_COMPANY_UNAVAILABLE',
            ], 404);
        }

        try {
            $delivery = $this->mobileLicenseEntitlements->issue($company);
        } catch (MobileLicenseEntitlementException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'code' => $error->errorCode,
            ], $error->httpStatus)
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        }

        return response()->json([
            'data' => $delivery,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * Record lightweight presence without coupling it to a sales upload or a
     * configuration download. The request is HMAC-authenticated by sync.node.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:android,web'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'device_name' => ['required', 'string', 'max:255'],
            'battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pending_actions' => ['required', 'integer', 'min:0'],
            'last_pull_cursor' => ['required', 'integer', 'min:0'],
            'last_sync_at' => ['nullable', 'date'],
            'cashier_id' => ['nullable', 'uuid'],
            'terminals' => ['nullable', 'array', 'max:250'],
            'terminals.*.till_id' => ['required', 'uuid', 'distinct'],
            'terminals.*.last_seen_at' => ['nullable', 'date'],
            'terminals.*.device_name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var SyncNode $node */
        $node = app('sync.node');
        $expectedPlatform = $node->deployment_mode === 'android' ? 'android' : 'web';
        if ($validated['platform'] !== $expectedPlatform) {
            return response()->json([
                'message' => 'The heartbeat platform does not match this paired terminal.',
                'code' => 'SYNC_PLATFORM_MISMATCH',
            ], 403);
        }
        if ($expectedPlatform === 'android' && ! empty($validated['terminals'])) {
            return response()->json([
                'message' => 'An Android node may report only its own paired till.',
                'code' => 'SYNC_TERMINAL_SCOPE_INVALID',
            ], 403);
        }

        $shiftStatus = null;
        $shiftSalesCutoffAt = null;
        $shiftSalesBlockReason = null;
        if (! empty($validated['cashier_id'])) {
            if ($expectedPlatform !== 'android' || ! $node->till_id) {
                return response()->json([
                    'message' => 'Cashier shift presence is available only to a till-bound Android terminal.',
                    'code' => 'SYNC_CASHIER_SCOPE_INVALID',
                ], 403);
            }

            $cashier = PosUser::query()
                ->where('company_id', $node->company_id)
                ->where('is_active', true)
                ->find($validated['cashier_id']);
            $cashierBranchGranted = $cashier && (
                app(BusinessModeBranchScopeService::class)->isSuperAdmin($cashier)
                || (string) ($cashier->branch_id ?: '') === (string) $node->branch_id
                || DB::table('user_branches')
                    ->where('company_id', $node->company_id)
                    ->where('branch_id', $node->branch_id)
                    ->where('pos_user_id', $cashier->id)
                    ->exists()
            );
            if (! $cashier
                || ! $cashierBranchGranted
                || ! (bool) $cashier->can_login_pos
                || ! (bool) $cashier->can_access_front_office) {
                return response()->json([
                    'message' => 'The reported cashier is not active in this paired terminal workspace.',
                    'code' => 'SYNC_CASHIER_SCOPE_INVALID',
                ], 403);
            }

            $shift = DB::table('pos_user_shift_sessions')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('till_id', $node->till_id)
                ->where('pos_user_id', $cashier->id)
                ->where('status', 'open')
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();
            if (! $shift) {
                $shiftStatus = 'not_open';
                $shiftSalesBlockReason = 'No open cashier shift session exists for this terminal. Sign in again or ask a manager to open the assigned shift.';
            } else {
                $shiftStatus = 'open';
                $shiftPolicy = app(ShiftPolicyService::class);
                $cutoff = $shiftPolicy->salesCutoff(
                    (string) $node->company_id,
                    (string) $node->branch_id,
                    $shift
                );
                $shiftSalesCutoffAt = $cutoff
                    ? $cutoff['at']->toIso8601String()
                    : null;
                $shiftSalesBlockReason = $shiftPolicy->salesBlockReasonForCutoff($cutoff, now());
            }
        }

        $reportedTerminals = collect($validated['terminals'] ?? []);
        $scopedTerminals = collect();
        if ($expectedPlatform === 'web' && $reportedTerminals->isNotEmpty()) {
            $scopedTerminals = PosTill::query()
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->whereIn('id', $reportedTerminals->pluck('till_id'))
                ->get()
                ->keyBy(fn ($till): string => (string) $till->id);
            if ($scopedTerminals->count() !== $reportedTerminals->count()) {
                return response()->json([
                    'message' => 'One or more reported terminals are outside this node company or branch.',
                    'code' => 'SYNC_TERMINAL_SCOPE_INVALID',
                ], 403);
            }
        }

        $seenAt = now();
        $node->forceFill([
            'app_version' => $validated['app_version'] ?? $node->app_version,
            'last_seen_at' => $seenAt,
            'metadata' => [
                ...($node->metadata ?? []),
                'client_platform' => $expectedPlatform,
                'presence' => [
                    'platform' => $expectedPlatform,
                    'device_name' => trim((string) $validated['device_name']),
                    'battery_percent' => $validated['battery_percent'] ?? null,
                    'pending_actions' => (int) $validated['pending_actions'],
                    'last_pull_cursor' => (int) $validated['last_pull_cursor'],
                    'last_sync_at' => $validated['last_sync_at'] ?? null,
                    'cashier_id' => $validated['cashier_id'] ?? null,
                    'reported_terminals' => $reportedTerminals->count(),
                    'reported_at' => $seenAt->toIso8601String(),
                ],
            ],
        ])->saveQuietly();

        if ($node->till) {
            $node->till->recordPresence([
                'last_seen_at' => $seenAt,
                'last_ip_address' => $request->ip(),
                'last_user_agent' => $request->userAgent(),
            ], $seenAt);
        }
        foreach ($reportedTerminals as $reported) {
            $till = $scopedTerminals->get((string) $reported['till_id']);
            if (! $till || empty($reported['last_seen_at'])) {
                continue;
            }
            $terminalSeenAt = CarbonImmutable::parse((string) $reported['last_seen_at']);
            if ($terminalSeenAt->isFuture()) {
                $terminalSeenAt = $seenAt->toImmutable();
            }
            $till->recordPresence([
                'last_seen_at' => $terminalSeenAt,
                'last_user_agent' => $reported['device_name'] ?? $till->last_user_agent,
            ], $terminalSeenAt);
        }

        // Mutations such as currency-rate changes publish a new immutable
        // snapshot head. Heartbeats only compare cursors, which is cheap even
        // with many terminals; the Android client downloads the snapshot only
        // when this flag says its acknowledged configuration is behind.
        $snapshotType = $this->configuration->snapshotResourceType($node);
        $latestConfigurationCursor = (int) (SyncChange::query()
            ->where('company_id', $node->company_id)
            ->where(fn ($scope) => $scope
                ->whereNull('branch_id')
                ->orWhere('branch_id', $node->branch_id))
            ->whereIn('resource_type', [$snapshotType, 'configuration.dirty'])
            ->max('id') ?? 0);
        $configurationPending = $latestConfigurationCursor > (int) $validated['last_pull_cursor'];

        return response()->json([
            'data' => [
                'server_time' => $seenAt->toIso8601String(),
                'status' => 'online',
                'company_id' => (string) $node->company_id,
                'branch_id' => (string) $node->branch_id,
                'till_id' => $node->till_id ? (string) $node->till_id : null,
                'configuration_pending' => $configurationPending,
                'latest_configuration_cursor' => $latestConfigurationCursor,
                'shift_status' => $shiftStatus,
                'shift_sales_cutoff_at' => $shiftSalesCutoffAt,
                'shift_sales_block_reason' => $shiftSalesBlockReason,
            ],
        ]);
    }

    /**
     * Execute a Day End on the cloud authority on behalf of a paired branch
     * server. The edge has already authenticated the operator and drained its
     * durable outbox; Central repeats the company, branch, role and licence
     * checks before allowing the idempotent command to mutate the calendar.
     */
    public function dayEnd(
        Request $request,
        TradingDayEndController $dayEnd,
        MenuRecipeDailyStockReconciliationController $reconciliation
    ): JsonResponse {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        /** @var SyncNode $node */
        $node = app('sync.node');
        if ($node->deployment_mode !== 'edge') {
            return response()->json([
                'message' => 'Only a paired branch server may initiate this authoritative Day End command.',
                'code' => 'SYNC_EDGE_NODE_REQUIRED',
            ], 403);
        }
        if (strtolower((string) $node->branch?->status) !== 'active'
            || app(CompanySettingService::class)->businessMode(
                (string) $node->company_id,
                (string) $node->branch_id
            ) !== 'hospitality') {
            return response()->json([
                'message' => 'Day End is available only for an active Hospitality branch.',
                'code' => 'DAY_END_BRANCH_INVALID',
            ], 409);
        }

        $validated = $request->validate([
            'run_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'expected_trading_date' => ['required', 'date_format:Y-m-d'],
            'operator_user_id' => ['required', 'uuid'],
            'source_till_id' => ['nullable', 'uuid'],
            'operation_type' => ['required', 'in:standard'],
        ]);
        $operator = PosUser::query()
            ->with('role')
            ->where('company_id', $node->company_id)
            ->where('is_active', true)
            ->find($validated['operator_user_id']);
        if (! $operator
            || (! (bool) $operator->can_access_front_office
                && ! (bool) $operator->can_access_back_office)) {
            return response()->json([
                'message' => 'The initiating operator is not active in this company workspace.',
                'code' => 'DAY_END_OPERATOR_INVALID',
            ], 403);
        }

        $superAdmin = app(BusinessModeBranchScopeService::class)->isSuperAdmin($operator);
        $branchGranted = $superAdmin
            || (string) ($operator->branch_id ?: '') === (string) $node->branch_id
            || DB::table('user_branches')
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('pos_user_id', $operator->id)
                ->exists();
        if (! $branchGranted) {
            return response()->json([
                'message' => 'The initiating operator is not allocated to this paired branch.',
                'code' => 'DAY_END_OPERATOR_BRANCH_DENIED',
            ], 403);
        }

        if (! empty($validated['source_till_id'])
            && ! PosTill::query()
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('is_active', true)
                ->whereKey($validated['source_till_id'])
                ->exists()) {
            return response()->json([
                'message' => 'The initiating terminal is not active in this paired branch.',
                'code' => 'DAY_END_SOURCE_TILL_INVALID',
            ], 403);
        }

        $guard = Auth::guard();
        $previousUser = $guard->user();
        $previousUserResolver = $request->getUserResolver();
        $guard->setUser($operator);
        $request->setUserResolver(static fn () => $operator);

        try {
            $permission = hasPermission(
                $operator->role_id,
                'general_procedure',
                (string) $node->company_id
            );
            if ($permission !== true) {
                return $permission;
            }

            /** @var Company $company */
            $company = app('company');
            $licenseStatus = app(LicenseAccessService::class)->enforce($company, $operator);
            if (! $licenseStatus['valid']) {
                return response()->json(
                    app(LicenseAccessService::class)->responsePayload($licenseStatus),
                    423
                );
            }
            app()->instance('license.status', $licenseStatus);

            $request->merge([
                'run_key' => $validated['run_key'],
                'expected_trading_date' => $validated['expected_trading_date'],
                'branch_id' => (string) $node->branch_id,
            ]);
            $request->headers->set('X-Branch-ID', (string) $node->branch_id);
            $request->attributes->set('business_mode_branch_id', (string) $node->branch_id);
            $request->attributes->set('sync_initiated_day_end', true);
            $request->attributes->set('sync_initiated_till_id', $validated['source_till_id'] ?? null);

            return $dayEnd->runDayEnd($request, $reconciliation);
        } finally {
            $request->setUserResolver($previousUserResolver);
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    /**
     * Consume a terminal activation code under the authenticated node scope.
     * The row lock makes the capability strictly one-time, even if two edge
     * requests arrive concurrently.
     */
    public function consumeTerminalActivation(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'terminal_activation_code' => [
                'required',
                'string',
                'max:96',
                'regex:/^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/i',
            ],
        ]);
        /** @var SyncNode $node */
        $node = app('sync.node');
        if ($node->deployment_mode !== 'edge') {
            return response()->json([
                'message' => 'Browser terminal activation is available only through a paired branch server.',
                'code' => 'SYNC_EDGE_NODE_REQUIRED',
            ], 403);
        }
        $codeHash = hash(
            'sha256',
            strtoupper(trim((string) $validated['terminal_activation_code']))
        );
        $consumedAt = now();

        $terminal = DB::transaction(function () use ($node, $codeHash, $consumedAt): ?array {
            $activation = SyncTerminalActivation::query()
                ->where('sync_node_id', $node->id)
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();
            if (! $activation
                || $activation->consumed_at
                || $activation->revoked_at
                || ! $activation->expires_at
                || $activation->expires_at->lessThanOrEqualTo($consumedAt)
                || ! $node->branch
                || strtolower((string) $node->branch->status) !== 'active'
                || (string) $activation->company_id !== (string) $node->company_id
                || (string) $activation->branch_id !== (string) $node->branch_id) {
                return null;
            }

            $till = $activation->till()
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $till) {
                return null;
            }

            $activation->forceFill(['consumed_at' => $consumedAt])->save();

            return $this->publicTill($till);
        }, 3);

        if (! $terminal) {
            return response()->json([
                'message' => 'The terminal activation code is invalid, expired, or already used.',
                'code' => 'TERMINAL_ACTIVATION_CODE_INVALID',
            ], 422);
        }

        return response()->json([
            'message' => 'Terminal activation code accepted.',
            'data' => [
                'terminal' => $terminal,
                'consumed_at' => $consumedAt->toIso8601String(),
            ],
        ]);
    }

    public function push(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        /** @var SyncNode $node */
        $node = app('sync.node');
        if ($node->deployment_mode === 'android') {
            // Mobile sales must keep the authenticated cashier, shift, tax,
            // stock and fiscalization pipeline authoritative. The Android
            // offline queue replays the normal REST commands with the user's
            // bearer token and this till's X-Till-Token; it must not import an
            // edge server's privileged sale/cashup event bundle.
            return response()->json([
                'message' => 'Android terminals must upload operational actions through the authenticated POS API.',
                'code' => 'SYNC_ANDROID_REST_REQUIRED',
            ], 403);
        }

        $validated = $request->validate([
            'events' => ['required', 'array', 'max:'.max(1, (int) config('salexa_sync.batch.push_size', 200))],
            'events.*.id' => ['required', 'uuid'],
            'events.*.aggregate_type' => ['required', 'string', 'max:96'],
            'events.*.aggregate_id' => ['required', 'string', 'max:64'],
            'events.*.event_type' => ['required', 'string', 'max:64'],
            'events.*.schema_version' => ['required', 'integer', 'min:1'],
            'events.*.payload' => ['required', 'array'],
            'events.*.payload_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);
        [$results, $proof] = DB::transaction(function () use ($node, $validated): array {
            // Serialise imports and proof updates for one node. This prevents
            // a status backfill racing a push and counting an event twice.
            $lockedNode = SyncNode::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $results = [];
            foreach ($validated['events'] as $event) {
                $results[] = $this->acceptEvent($lockedNode, $event);
            }

            $proof = $this->updateCloudInboxProof($lockedNode, $validated['events'], $results);
            $lockedNode->forceFill([
                'last_push_at' => now(),
                'last_seen_at' => now(),
            ])->saveQuietly();

            return [$results, $proof];
        }, 3);

        return response()->json([
            'data' => [
                'results' => $results,
                'proof_algorithm' => SyncEventSetProof::ALGORITHM,
                'acknowledged_events' => (int) $proof['count'],
                'acknowledged_digest' => (string) $proof['digest'],
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function pull(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('salexa_sync.batch.pull_size', 500))],
            'ack_event_id' => ['nullable', 'uuid', 'required_with:ack_payload_hash'],
            'ack_payload_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/', 'required_with:ack_event_id'],
            'ack_content_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        /** @var SyncNode $node */
        $node = app('sync.node');
        $cursor = (int) ($validated['cursor'] ?? 0);
        $limit = min(
            max(1, (int) ($validated['limit'] ?? config('salexa_sync.batch.pull_size', 500))),
            max(1, (int) config('salexa_sync.batch.pull_size', 500))
        );

        $this->configuration->ensureSnapshotChange($node);
        $snapshotType = $this->configuration->snapshotResourceType($node);

        // Configuration entries are complete snapshots. A node that has not
        // synced yet must jump straight to the newest snapshot instead of
        // applying an older retained audit copy first.
        $head = $this->latestSnapshotHead($node, $snapshotType);
        $latestSnapshotId = $head?->id;
        $acknowledgement = $this->acknowledgeConfigurationCursor(
            $node,
            $cursor,
            $validated['ack_event_id'] ?? null,
            $validated['ack_payload_hash'] ?? null,
            $validated['ack_content_hash'] ?? null
        );

        $changesQuery = SyncChange::query()
            ->where('company_id', $node->company_id)
            ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $node->branch_id))
            ->where('id', '>', $cursor);
        if ($node->deployment_mode === 'android') {
            // A mobile till consumes only its reduced, node-private snapshot.
            // It never observes future branch event types that may be intended
            // for a full edge server, and cursor gaps remain valid because the
            // stream cursor is the global monotonically increasing change id.
            $changesQuery->where('resource_type', $snapshotType);
        } else {
            $changesQuery->where(fn ($visible) => $visible
                ->where(fn ($ordinary) => $ordinary
                    ->where('resource_type', 'not like', 'configuration.snapshot%')
                    ->where('resource_type', '!=', 'configuration.dirty'))
                ->orWhere('resource_type', $snapshotType));
        }
        if ($latestSnapshotId !== null) {
            $changesQuery->where(fn ($visible) => $visible
                ->where('resource_type', '!=', $snapshotType)
                ->orWhere('id', $latestSnapshotId));
        }
        // Page by id first, then read the rows by primary key. Ordering whole
        // rows would put multi-megabyte snapshot payloads in the sort buffer.
        $changeIds = $changesQuery
            ->orderBy('id')
            ->limit($limit + 1)
            ->pluck('id');
        $changes = SyncChange::query()
            ->whereKey($changeIds->all())
            ->get()
            ->sortBy('id')
            ->values();
        $hasMore = $changes->count() > $limit;
        $changes = $changes->take($limit)->values();
        $nextCursor = (int) ($changes->last()?->id ?? $cursor);

        $node->forceFill(['last_pull_at' => now(), 'last_seen_at' => now()])->saveQuietly();

        return response()->json([
            'data' => [
                'changes' => $changes->map(fn (SyncChange $change) => [
                    'cursor' => (int) $change->id,
                    'event_id' => (string) $change->event_id,
                    'resource_type' => (string) $change->resource_type,
                    'resource_id' => (string) $change->resource_id,
                    'operation' => (string) $change->operation,
                    'schema_version' => (int) $change->schema_version,
                    'payload' => $change->payload,
                    'payload_hash' => (string) $change->payload_hash,
                    'occurred_at' => $change->occurred_at?->toIso8601String(),
                ])->all(),
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
                'head_cursor' => (int) ($head?->id ?? 0),
                'head_event_id' => $head?->event_id ? (string) $head->event_id : null,
                'head_payload_hash' => $head?->payload_hash ? (string) $head->payload_hash : null,
                'head_content_hash' => $head ? (string) data_get($head->payload, 'content_hash', '') : null,
                'acknowledged_cursor' => (int) $acknowledgement->cursor,
                'acknowledgement_confirmed' => (bool) data_get(
                    $acknowledgement->metadata,
                    'acknowledgement_confirmed',
                    false
                ),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudEndpointUnavailable()) {
            return $unavailable;
        }

        /** @var SyncNode $node */
        $node = app('sync.node');

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'ack_event_id' => ['nullable', 'uuid', 'required_with:ack_payload_hash'],
            'ack_payload_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/', 'required_with:ack_event_id'],
            'ack_content_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $this->configuration->ensureSnapshotChange($node);
        $snapshotType = $this->configuration->snapshotResourceType($node);
        $head = $this->latestSnapshotHead($node, $snapshotType);
        $acknowledgement = $this->acknowledgeConfigurationCursor(
            $node,
            (int) ($validated['cursor'] ?? 0),
            $validated['ack_event_id'] ?? null,
            $validated['ack_payload_hash'] ?? null,
            $validated['ack_content_hash'] ?? null
        );
        $processedProof = $this->cloudInboxProof($node);
        $latestReceipt = SyncInboxReceipt::query()
            ->where('sync_node_id', $node->id)
            ->where('status', 'processed')
            ->latest('processed_at')
            ->first(['event_id', 'processed_at']);
        $failedReceipts = SyncInboxReceipt::query()
            ->where('sync_node_id', $node->id)
            ->where('status', 'failed')
            ->count();
        $processingReceipts = SyncInboxReceipt::query()
            ->where('sync_node_id', $node->id)
            ->whereIn('status', ['received', 'processing'])
            ->count();
        $acknowledgedCursor = (int) $acknowledgement->cursor;
        $acknowledgementConfirmed = (bool) data_get(
            $acknowledgement->metadata,
            'acknowledgement_confirmed',
            false
        );
        $headCursor = (int) ($head?->id ?? 0);

        return response()->json([
            'data' => [
                'paired' => true,
                'node_id' => (string) $node->id,
                'node_code' => (string) $node->node_code,
                'company_id' => (string) $node->company_id,
                'branch_id' => (string) $node->branch_id,
                'till_id' => $node->till_id ? (string) $node->till_id : null,
                'status' => (string) $node->status,
                'credential_version' => (int) $node->credential_version,
                'pos_to_cloud' => [
                    'proof_algorithm' => (string) $processedProof['algorithm'],
                    'acknowledged_events' => (int) $processedProof['count'],
                    'acknowledged_digest' => (string) $processedProof['digest'],
                    'processing_events' => $processingReceipts,
                    'failed_events' => $failedReceipts,
                    'last_acknowledged_event_id' => $latestReceipt?->event_id
                        ? (string) $latestReceipt->event_id
                        : null,
                    'last_acknowledged_at' => $latestReceipt?->processed_at?->toIso8601String(),
                ],
                'cloud_to_pos' => [
                    'acknowledged_cursor' => $acknowledgedCursor,
                    'acknowledgement_confirmed' => $acknowledgementConfirmed,
                    'acknowledged_event_id' => $acknowledgement->last_event_id
                        ? (string) $acknowledgement->last_event_id
                        : null,
                    'acknowledged_payload_hash' => (string) data_get(
                        $acknowledgement->metadata,
                        'acknowledged_payload_hash',
                        ''
                    ) ?: null,
                    'acknowledged_content_hash' => (string) data_get(
                        $acknowledgement->metadata,
                        'acknowledged_content_hash',
                        ''
                    ) ?: null,
                    'acknowledged_at' => $acknowledgement->synced_at?->toIso8601String(),
                    'head_cursor' => $headCursor,
                    'head_event_id' => $head?->event_id ? (string) $head->event_id : null,
                    'head_payload_hash' => $head?->payload_hash ? (string) $head->payload_hash : null,
                    'head_content_hash' => $head ? (string) data_get($head->payload, 'content_hash', '') : null,
                    'pending_changes' => $headCursor > $acknowledgedCursor ? 1 : 0,
                ],
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    private function cloudInboxProof(SyncNode $node): array
    {
        return DB::transaction(function () use ($node): array {
            $lockedNode = SyncNode::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $proof = data_get($lockedNode->metadata, 'inbox_event_set_proof');
            if ($this->eventSetProof->valid($proof)) {
                return $proof;
            }

            return $this->updateCloudInboxProof($lockedNode, [], []);
        }, 3);
    }

    private function updateCloudInboxProof(SyncNode $node, array $events, array $results): array
    {
        $metadata = is_array($node->metadata) ? $node->metadata : [];
        $proof = $metadata['inbox_event_set_proof'] ?? null;
        if ($this->eventSetProof->valid($proof)) {
            foreach ($results as $index => $result) {
                if (($result['status'] ?? null) !== 'accepted' || ! isset($events[$index])) {
                    continue;
                }
                $proof = $this->eventSetProof->add(
                    $proof,
                    (string) $events[$index]['id'],
                    strtolower((string) $events[$index]['payload_hash'])
                );
            }
        } else {
            // One-time upgrade/backfill only. Later pushes update this
            // constant-size proof while holding the node row lock.
            $proof = $this->eventSetProof->empty();
            foreach (SyncInboxReceipt::query()
                ->where('sync_node_id', $node->id)
                ->where('status', 'processed')
                ->orderBy('event_id')
                ->cursor(['event_id', 'payload_hash']) as $receipt) {
                $proof = $this->eventSetProof->add(
                    $proof,
                    (string) $receipt->event_id,
                    (string) $receipt->payload_hash
                );
            }
        }

        $node->forceFill(['metadata' => [
            ...$metadata,
            'inbox_event_set_proof' => [
                ...$proof,
                'updated_at' => now()->toIso8601String(),
            ],
        ]])->saveQuietly();

        return $proof;
    }

    /**
     * Newest configuration snapshot visible to the node. Its id is resolved
     * first and the row is then read by primary key: ordering whole rows over
     * the company-wide-or-branch scope needs a filesort, and MySQL 8 cannot
     * fit a full BOM catalogue's JSON payload in its sort buffer (error 1038).
     */
    private function latestSnapshotHead(SyncNode $node, string $snapshotType): ?SyncChange
    {
        $headId = SyncChange::query()
            ->where('company_id', $node->company_id)
            ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $node->branch_id))
            ->where('resource_type', $snapshotType)
            ->max('id');

        return $headId === null ? null : SyncChange::query()->find($headId);
    }

    private function acknowledgeConfigurationCursor(
        SyncNode $node,
        int $cursor,
        ?string $eventId,
        ?string $payloadHash,
        ?string $contentHash
    ): SyncNodeCursor {
        $stream = (string) config('salexa_sync.streams.changes', 'changes');
        $record = SyncNodeCursor::query()->firstOrNew([
            'sync_node_id' => $node->id,
            'stream' => $stream,
        ]);
        $currentMetadata = is_array($record->metadata) ? $record->metadata : [];
        $currentConfirmed = (bool) data_get($currentMetadata, 'acknowledgement_confirmed', false);
        $confirmed = $cursor === 0;
        $change = null;

        if ($cursor > 0 && $eventId && $payloadHash) {
            $change = SyncChange::query()
                ->where('id', $cursor)
                ->where('company_id', $node->company_id)
                ->where(fn ($scope) => $scope
                    ->whereNull('branch_id')
                    ->orWhere('branch_id', $node->branch_id))
                ->where('resource_type', $this->configuration->snapshotResourceType($node))
                ->first();
            $confirmed = $change
                && hash_equals((string) $change->event_id, $eventId)
                && hash_equals((string) $change->payload_hash, strtolower($payloadHash));
            if ($confirmed && $contentHash) {
                $confirmed = hash_equals(
                    (string) data_get($change->payload, 'content_hash', ''),
                    strtolower($contentHash)
                );
            }
        }

        $canAdvance = $confirmed
            && (! $currentConfirmed || $cursor >= (int) ($record->cursor ?? 0));
        $metadata = [
            ...$currentMetadata,
            'last_claimed_cursor' => $cursor,
            'last_claimed_at' => now()->toIso8601String(),
            'last_claim_valid' => $confirmed,
        ];

        if ($canAdvance) {
            $metadata = [
                ...$metadata,
                'acknowledgement_confirmed' => true,
                'acknowledged_payload_hash' => $change?->payload_hash,
                'acknowledged_content_hash' => $change
                    ? data_get($change->payload, 'content_hash')
                    : null,
            ];
            $record->forceFill([
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'cursor' => $cursor,
                'last_event_id' => $change?->event_id,
                'synced_at' => now(),
                'metadata' => $metadata,
            ])->save();
        } else {
            $record->forceFill([
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'cursor' => (int) ($record->cursor ?? 0),
                'metadata' => $metadata,
            ])->save();
        }

        return $record->fresh();
    }

    private function acceptEvent(SyncNode $node, array $event): array
    {
        $eventId = (string) $event['id'];
        $payloadHash = $this->hasher->hash($event['payload']);
        if (! hash_equals(strtolower((string) $event['payload_hash']), $payloadHash)) {
            return $this->eventResult($eventId, 'rejected', 'SYNC_PAYLOAD_HASH_INVALID', false);
        }
        $eventType = (string) $event['event_type'];
        if (! in_array($eventType, [
            'sale.completed',
            'shift.cashup.completed',
            'front_stock.closing.completed',
        ], true)
            || (int) $event['schema_version'] !== 1) {
            return $this->eventResult($eventId, 'rejected', 'SYNC_EVENT_SCHEMA_UNSUPPORTED', false);
        }
        $aggregateMatches = match ($eventType) {
            'sale.completed' => (string) $event['aggregate_type'] === 'sale'
                && (string) $event['aggregate_id'] === (string) data_get($event, 'payload.sale.id'),
            'shift.cashup.completed' => (string) $event['aggregate_type'] === 'cashup'
                && (string) $event['aggregate_id'] === (string) data_get($event, 'payload.cashup.id'),
            'front_stock.closing.completed' => (string) $event['aggregate_type'] === 'front_stock_closing'
                && (string) $event['aggregate_id'] === (string) data_get($event, 'payload.closing.id'),
        };
        if (! $aggregateMatches) {
            return $this->eventResult($eventId, 'rejected', 'SYNC_AGGREGATE_ID_MISMATCH', false);
        }

        $existing = SyncInboxReceipt::query()
            ->where('sync_node_id', $node->id)
            ->where('event_id', $eventId)
            ->first();
        if ($existing) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                return $this->eventResult($eventId, 'conflict', 'SYNC_EVENT_ID_REUSED', false);
            }
            if ($existing->status === 'processed') {
                return $this->eventResult($eventId, 'duplicate', null, false);
            }
        }

        try {
            $result = DB::transaction(function () use ($node, $event, $eventId, $payloadHash) {
                DB::table('sync_inbox_receipts')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'event_id' => $eventId,
                    'sync_node_id' => $node->id,
                    'company_id' => $node->company_id,
                    'branch_id' => $node->branch_id,
                    'payload_hash' => $payloadHash,
                    'status' => 'processing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $receipt = SyncInboxReceipt::query()
                    ->where('sync_node_id', $node->id)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! hash_equals((string) $receipt->payload_hash, $payloadHash)) {
                    throw new InvalidArgumentException('SYNC_EVENT_ID_REUSED');
                }
                if ($receipt->status === 'processed') {
                    return ['duplicate_receipt' => true];
                }
                $receipt->forceFill(['status' => 'processing', 'error' => null])->save();
                $import = match ((string) $event['event_type']) {
                    'sale.completed' => $this->saleImporter->import($node, $event['payload']),
                    'shift.cashup.completed' => $this->saleImporter->importShiftCashup($node, $event['payload']),
                    'front_stock.closing.completed' => $this->frontStockClosingImporter->import(
                        $node,
                        $event['payload']
                    ),
                };
                $receipt->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();

                return ['duplicate_receipt' => false, 'import' => $import];
            });

            if ($result['duplicate_receipt']) {
                return $this->eventResult($eventId, 'duplicate', null, false);
            }

            return [
                'event_id' => $eventId,
                'status' => 'accepted',
                'retryable' => false,
                'result' => $result['import'],
            ];
        } catch (InvalidArgumentException $exception) {
            $this->recordFailure($node, $eventId, $payloadHash, $exception);

            return $this->eventResult($eventId, 'rejected', $exception->getMessage(), false);
        } catch (RuntimeException $exception) {
            $this->recordFailure($node, $eventId, $payloadHash, $exception);

            return $this->eventResult($eventId, 'deferred', $exception->getMessage(), true);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($node, $eventId, $payloadHash, $exception);

            return $this->eventResult($eventId, 'deferred', 'SYNC_EVENT_PROCESSING_FAILED', true);
        }
    }

    private function cloudEndpointUnavailable(): ?JsonResponse
    {
        if (config('salexa_sync.deployment_mode') === 'cloud') {
            return null;
        }

        return response()->json([
            'message' => 'Signed sync protocol endpoints are only available on a cloud deployment.',
            'code' => 'SYNC_CLOUD_ENDPOINT_UNAVAILABLE',
        ], 409);
    }

    private function recordFailure(SyncNode $node, string $eventId, string $payloadHash, Throwable $error): void
    {
        DB::transaction(function () use ($node, $eventId, $payloadHash, $error): void {
            DB::table('sync_inbox_receipts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_id' => $eventId,
                'sync_node_id' => $node->id,
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'payload_hash' => $payloadHash,
                'status' => 'failed',
                'processed_at' => null,
                'error' => Str::limit($error->getMessage(), 2000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // A concurrent request may already be processing or may have
            // completed this event. Never downgrade either durable state.
            SyncInboxReceipt::query()
                ->where('sync_node_id', $node->id)
                ->where('event_id', $eventId)
                ->whereNotIn('status', ['processing', 'processed'])
                ->update([
                    'payload_hash' => $payloadHash,
                    'status' => 'failed',
                    'processed_at' => null,
                    'error' => Str::limit($error->getMessage(), 2000),
                    'updated_at' => now(),
                ]);
        });
    }

    private function eventResult(string $eventId, string $status, ?string $error, bool $retryable): array
    {
        return array_filter([
            'event_id' => $eventId,
            'status' => $status,
            'retryable' => $retryable,
            'error' => $error,
        ], static fn ($value) => $value !== null);
    }

    private function publicCompany(Company $company): array
    {
        return $company->only([
            'id', 'country_id', 'company_code', 'company_name', 'location_address', 'city', 'country',
            'domain', 'host_ip', 'license_support_phone', 'logo', 'status',
        ]);
    }

    private function publicBranch($branch): array
    {
        return $branch->only([
            'id', 'company_id', 'branch_name', 'branch_code', 'branch_address', 'branch_city',
            'branch_country', 'level', 'is_main_branch', 'status',
        ]);
    }

    private function publicTill($till): array
    {
        return $till->only([
            'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name',
            'device_label', 'configuration_version', 'is_active',
        ]);
    }

    /** @return array<string, array<string, mixed>|null> */
    private function publicEnterpriseHierarchy(SyncNode $node): array
    {
        if (! Schema::hasTable('shops')) {
            return [
                'country' => null,
                'company' => $node->company ? $this->publicCompany($node->company) : null,
                'brand' => null,
                'complex' => null,
                'shop' => null,
                'till' => $node->till ? $this->publicTill($node->till) : null,
            ];
        }

        $shop = $node->shop;
        if (! $shop && $node->branch_id) {
            $shop = Shop::query()
                ->with(['country', 'brand', 'complex'])
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->first();
        }

        return [
            'country' => $shop?->country?->only([
                'id', 'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code',
                'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active',
            ]),
            'company' => $node->company ? $this->publicCompany($node->company) : null,
            'brand' => $shop?->brand ? [
                ...$shop->brand->only([
                    'id', 'company_id', 'country_id', 'code', 'name', 'description',
                    'logo_path', 'logo_url', 'status', 'metadata',
                ]),
                'logo' => $this->publicAssetReference(
                    $shop->brand->logo_url,
                    $shop->brand->logo_path
                ),
            ] : null,
            'complex' => $shop?->complex?->only([
                'id', 'company_id', 'country_id', 'code', 'name',
                'address', 'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata',
            ]),
            'shop' => $shop?->only([
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id',
                'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode',
                'configuration_version', 'status', 'metadata',
            ]),
            'till' => $node->till ? $this->publicTill($node->till) : null,
        ];
    }

    private function publicAssetReference(mixed $explicitUrl, mixed $storedPath): ?string
    {
        $explicitUrl = trim((string) $explicitUrl);
        if ($explicitUrl !== '') {
            return $explicitUrl;
        }

        $storedPath = trim((string) $storedPath);
        if ($storedPath === '') {
            return null;
        }
        if (Str::startsWith(strtolower($storedPath), ['http://', 'https://', 'data:'])) {
            return $storedPath;
        }

        $path = '/'.ltrim(str_replace('\\', '/', $storedPath), '/');
        if (! Str::startsWith($path, ['/storage/', '/api/assets/', '/assets/'])) {
            $path = '/storage'.$path;
        }

        return url($path);
    }
}
