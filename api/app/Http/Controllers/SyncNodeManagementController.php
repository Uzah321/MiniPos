<?php

namespace App\Http\Controllers;

use App\Models\SyncNode;
use App\Models\SyncTerminalActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\GeneralSettings\Models\PosTill;

class SyncNodeManagementController extends Controller
{
    public function platformIndex(Request $request, Company $company): JsonResponse
    {
        app()->instance('company', $company);

        return $this->index($request);
    }

    public function platformCreatePairingCode(Request $request, Company $company): JsonResponse
    {
        app()->instance('company', $company);

        return $this->createPairingCode($request);
    }

    public function platformCreateTerminalActivationCode(
        Request $request,
        Company $company,
        string $node
    ): JsonResponse {
        app()->instance('company', $company);

        return $this->createTerminalActivationCode($request, $node);
    }

    public function platformSuspend(Request $request, Company $company, string $node): JsonResponse
    {
        app()->instance('company', $company);

        return $this->suspend($request, $node);
    }

    public function platformResume(Request $request, Company $company, string $node): JsonResponse
    {
        app()->instance('company', $company);

        return $this->resume($request, $node);
    }

    public function platformRotate(Request $request, Company $company, string $node): JsonResponse
    {
        app()->instance('company', $company);

        return $this->rotate($request, $node);
    }

    public function platformRevoke(Request $request, Company $company, string $node): JsonResponse
    {
        app()->instance('company', $company);

        return $this->revoke($request, $node);
    }

    public function index(Request $request): JsonResponse
    {
        $company = app('company');
        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'suspended', 'revoked'])],
        ]);

        $nodes = SyncNode::query()
            ->with(['branch:id,company_id,branch_name,branch_code', 'till:id,company_id,branch_id,till_code,till_name'])
            ->where('company_id', $company->id)
            ->when($validated['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        $onlineCutoff = now()->subSeconds(max(
            30,
            (int) config('salexa_sync.presence_stale_seconds', 180)
        ));

        $presenceNodes = SyncNode::query()
            ->where('company_id', $company->id)
            ->when($validated['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->whereNull('revoked_at')
            ->where('status', '!=', 'revoked')
            ->get(['id', 'branch_id', 'till_id', 'deployment_mode', 'status']);
        $androidNodesByTill = $presenceNodes
            ->where('deployment_mode', 'android')
            ->whereNotNull('till_id')
            ->keyBy(fn (SyncNode $node): string => (string) $node->till_id);
        $edgeNodesByBranch = $presenceNodes
            ->where('deployment_mode', 'edge')
            ->keyBy(fn (SyncNode $node): string => (string) $node->branch_id);

        $terminals = PosTill::query()
            ->with('branch:id,company_id,branch_name,branch_code')
            ->where('company_id', $company->id)
            ->when($validated['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->orderBy('till_name')
            ->get()
            ->each(function (PosTill $till) use (
                $androidNodesByTill,
                $edgeNodesByBranch,
                $onlineCutoff
            ): void {
                $androidNode = $androidNodesByTill->get((string) $till->id);
                $node = $androidNode ?: $edgeNodesByBranch->get((string) $till->branch_id);
                $till->setAttribute('client_platform', $androidNode ? 'android' : 'web');
                $till->setAttribute('sync_node_id', $node ? (string) $node->id : null);
                $till->setAttribute(
                    'is_online',
                    $till->is_active
                        && $node?->status === 'active'
                        && $till->last_seen_at?->greaterThanOrEqualTo($onlineCutoff) === true
                );
            });

        $nodes->each(function (SyncNode $node) use ($onlineCutoff): void {
            $node->setAttribute(
                'client_platform',
                $node->deployment_mode === 'android' ? 'android' : 'web'
            );
            $node->setAttribute(
                'is_online',
                $node->status === 'active'
                    && $node->revoked_at === null
                    && $node->last_seen_at?->greaterThanOrEqualTo($onlineCutoff) === true
            );
        });

        $nodes->each(function (SyncNode $node) use ($terminals): void {
            $node->setAttribute('terminals', $terminals
                ->filter(fn (PosTill $till): bool => $node->deployment_mode === 'android'
                    ? (string) $till->id === (string) $node->till_id
                    : ((string) $till->branch_id === (string) $node->branch_id
                        && $till->client_platform === 'web'))
                ->values()
                ->all());
        });

        return response()->json([
            'data' => $nodes,
            'terminals' => $terminals,
            'presence_stale_seconds' => max(
                30,
                (int) config('salexa_sync.presence_stale_seconds', 180)
            ),
        ]);
    }

    /**
     * Pre-provision a node and issue a short-lived, one-time pairing code.
     * The public pair endpoint can only activate a record created here.
     */
    public function createPairingCode(Request $request): JsonResponse
    {
        if ($unavailable = $this->cloudMutationUnavailable()) {
            return $unavailable;
        }
        $company = app('company');
        $validated = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'till_id' => ['nullable', 'uuid'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'node_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'target_platform' => ['nullable', Rule::in(['edge', 'android'])],
            'recovery_node_id' => ['nullable', 'uuid'],
            'recovery_authorized' => ['nullable', 'boolean'],
            'replacement_node_id' => ['nullable', 'uuid'],
            'replacement_authorized' => ['nullable', 'boolean'],
        ]);
        if ((bool) config('salexa_sync.local_hub', false)
            && ($validated['target_platform'] ?? 'edge') === 'android') {
            return response()->json([
                'message' => 'Android terminals must pair with Central; this local Back Office supports web edge nodes only.',
                'code' => 'SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE',
            ], 409);
        }
        if (! empty($validated['recovery_node_id']) && ! empty($validated['replacement_node_id'])) {
            return response()->json([
                'message' => 'Recovery and replacement are separate administrator actions.',
                'code' => 'SYNC_PAIRING_ACTION_CONFLICT',
            ], 422);
        }
        if (! empty($validated['replacement_node_id'])
            && ! ($validated['replacement_authorized'] ?? false)) {
            return response()->json([
                'message' => 'Explicit administrator confirmation is required to replace a revoked node.',
                'code' => 'SYNC_REPLACEMENT_AUTHORIZATION_REQUIRED',
            ], 409);
        }

        return DB::transaction(
            fn (): JsonResponse => $this->createPairingCodeLocked($request, $company, $validated),
            3
        );
    }

    private function createPairingCodeLocked(Request $request, Company $company, array $validated): JsonResponse
    {
        $targetPlatform = (string) ($validated['target_platform'] ?? 'edge');

        $branch = CompanyBranch::query()
            ->where('company_id', $company->id)
            // A branch row fences name checks (including web nodes) even when
            // the request has no till. Lock it before reading any node state.
            ->lockForUpdate()
            ->find($validated['branch_id']);
        if (! $branch) {
            return response()->json(['message' => 'Branch not found for this company.'], 422);
        }

        $till = null;
        if (! empty($validated['till_id'])) {
            $till = PosTill::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->find($validated['till_id']);
            if (! $till) {
                return response()->json(['message' => 'Till not found for this company branch.'], 422);
            }
        }
        if ($targetPlatform === 'android' && ! $till) {
            return response()->json([
                'message' => 'An Android terminal must be paired to one specific till.',
                'code' => 'ANDROID_TILL_REQUIRED',
            ], 422);
        }

        $node = null;
        $replacementNode = null;
        if (! empty($validated['recovery_node_id'])) {
            if (! ($validated['recovery_authorized'] ?? false)) {
                return response()->json([
                    'message' => 'Explicit recovery authorization is required to re-pair an existing node.',
                    'code' => 'SYNC_RECOVERY_AUTHORIZATION_REQUIRED',
                ], 409);
            }
            $node = SyncNode::query()
                ->where('company_id', $company->id)
                ->find($validated['recovery_node_id']);
            if (! $node) {
                return response()->json(['message' => 'Sync node not found for this company.'], 404);
            }
            if (! in_array($node->status, ['active', 'suspended'], true)) {
                return response()->json([
                    'message' => 'Only an active or suspended node can receive a recovery pairing code.',
                    'code' => 'SYNC_RECOVERY_NODE_INVALID',
                ], 409);
            }
            if ((string) $node->branch_id !== (string) $branch->id
                || (string) ($node->till_id ?: '') !== (string) ($till?->id ?: '')) {
                return response()->json([
                    'message' => 'Recovery pairing cannot change the existing node branch or till binding.',
                    'code' => 'SYNC_RECOVERY_SCOPE_MISMATCH',
                ], 409);
            }
            if ((string) $node->deployment_mode !== $targetPlatform) {
                return response()->json([
                    'message' => 'Recovery pairing cannot change the terminal platform.',
                    'code' => 'SYNC_RECOVERY_PLATFORM_MISMATCH',
                ], 409);
            }
        }
        if (! empty($validated['replacement_node_id'])) {
            $replacementNode = SyncNode::query()
                ->where('company_id', $company->id)
                ->find($validated['replacement_node_id']);
            if (! $replacementNode) {
                return response()->json(['message' => 'Sync node not found for this company.'], 404);
            }
            if ($replacementNode->status !== 'revoked' || ! $replacementNode->revoked_at) {
                return response()->json([
                    'message' => 'Only a revoked node can be replaced by a newly provisioned node.',
                    'code' => 'SYNC_REPLACEMENT_NODE_INVALID',
                ], 409);
            }
            if ((string) $replacementNode->branch_id !== (string) $branch->id
                || (string) ($replacementNode->till_id ?: '') !== (string) ($till?->id ?: '')) {
                return response()->json([
                    'message' => 'A replacement node must keep the revoked node company, branch and till binding.',
                    'code' => 'SYNC_REPLACEMENT_SCOPE_MISMATCH',
                ], 409);
            }
            if ((string) $replacementNode->deployment_mode !== $targetPlatform) {
                return response()->json([
                    'message' => 'A replacement node must keep the revoked node platform.',
                    'code' => 'SYNC_REPLACEMENT_PLATFORM_MISMATCH',
                ], 409);
            }
        }

        $nodeName = trim((string) ($validated['node_name'] ?? (
            $targetPlatform === 'android'
                ? $till?->till_name
                : $branch->branch_name.' Edge'
        )));
        if ($nodeName === '') {
            return response()->json([
                'message' => 'Enter a unique terminal name.',
                'code' => 'TERMINAL_NAME_REQUIRED',
            ], 422);
        }

        $duplicateName = SyncNode::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('revoked_at')
            ->where('status', '!=', 'revoked')
            ->whereRaw('LOWER(node_name) = ?', [strtolower($nodeName)])
            ->when($node, fn ($query) => $query->where('id', '!=', $node->id))
            ->exists();
        $duplicateTillName = PosTill::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereRaw('LOWER(till_name) = ?', [strtolower($nodeName)])
            ->when($till, fn ($query) => $query->where('id', '!=', $till->id))
            ->exists();
        if ($duplicateName || $duplicateTillName) {
            return response()->json([
                'message' => 'A terminal with this name already exists at the selected branch.',
                'code' => 'TERMINAL_NAME_ALREADY_USED',
            ], 422);
        }

        if ($targetPlatform === 'android' && ! $node) {
            $duplicateTill = SyncNode::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->where('till_id', $till->id)
                ->where('deployment_mode', 'android')
                ->whereNull('revoked_at')
                ->whereIn('status', ['pending', 'active', 'suspended'])
                ->exists();
            if ($duplicateTill) {
                return response()->json([
                    'message' => 'This till is already assigned to an Android terminal. Revoke or recover that terminal first.',
                    'code' => 'ANDROID_TILL_ALREADY_PAIRED',
                ], 409);
            }
        }

        $nodeCode = $node?->node_code
            ?: strtoupper($validated['node_code'] ?? $this->newNodeCode($company, $branch));
        if (! $node && SyncNode::query()->where('node_code', $nodeCode)->exists()) {
            return response()->json(['message' => 'The node code is already in use.'], 422);
        }

        $secret = Str::upper(Str::random(12)).'-'.Str::upper(Str::random(12));
        $pairingCode = $nodeCode.'.'.$secret;
        $expiresAt = now()->addMinutes(max(5, (int) config('salexa_sync.security.activation_ttl_minutes', 30)));
        $createdBy = $request->user()?->getAuthIdentifier();
        $createdBy = is_string($createdBy) && Str::isUuid($createdBy) ? $createdBy : null;

        $recovery = $node !== null;
        $node ??= new SyncNode;
        $shopId = Schema::hasTable('shops')
            ? DB::table('shops')
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->value('id')
            : null;
        $nodeValues = [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'till_id' => $till?->id,
            'node_code' => $nodeCode,
            'node_name' => $nodeName,
            'deployment_mode' => $targetPlatform,
            'activation_code_hash' => Hash::make($pairingCode),
            'activation_expires_at' => $expiresAt,
            'activated_at' => $recovery ? $node->activated_at : null,
            'status' => $recovery ? $node->status : 'pending',
            'created_by' => $createdBy,
            'revoked_at' => $recovery ? $node->revoked_at : null,
            'metadata' => [
                ...($node->metadata ?? []),
                'recovery_authorized' => (bool) ($validated['recovery_authorized'] ?? false),
                ...($replacementNode ? [
                    'replacement_authorized' => true,
                    'replaces_node_id' => (string) $replacementNode->id,
                    'replacement_issued_at' => now()->toIso8601String(),
                ] : []),
                'pairing_issued_at' => now()->toIso8601String(),
                'client_platform' => $targetPlatform === 'android' ? 'android' : 'web',
            ],
        ];
        // Older installations and focused activation fixtures may not have
        // applied the enterprise hierarchy migration yet. Keep pairing
        // backward compatible while new schemas persist the canonical shop.
        if (Schema::hasColumn('sync_nodes', 'shop_id')) {
            $nodeValues['shop_id'] = $shopId;
        }
        if (! $recovery) {
            $nodeValues['credential_hash'] = null;
            $nodeValues['credential_secret'] = null;
        }
        if ($replacementNode) {
            $replacementResult = DB::transaction(function () use (
                $company,
                $branch,
                $till,
                $replacementNode,
                $node,
                $nodeValues,
                $createdBy
            ): array {
                // The revoked row is the one-successor grant fence. Holding
                // it prevents two administrators from issuing different
                // replacement nodes concurrently for the same installation.
                $lockedReplacement = SyncNode::query()
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->find($replacementNode->id);
                if (! $lockedReplacement
                    || $lockedReplacement->status !== 'revoked'
                    || ! $lockedReplacement->revoked_at
                    || (string) $lockedReplacement->branch_id !== (string) $branch->id
                    || (string) ($lockedReplacement->till_id ?: '') !== (string) ($till?->id ?: '')) {
                    return ['error' => 'invalid'];
                }

                $successors = SyncNode::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->whereIn('status', ['pending', 'active'])
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (SyncNode $candidate): bool => hash_equals(
                        (string) $lockedReplacement->id,
                        (string) data_get($candidate->metadata, 'replaces_node_id', '')
                    ));
                foreach ($successors as $successor) {
                    $pendingExpired = $successor->status === 'pending'
                        && $successor->activation_expires_at
                        && $successor->activation_expires_at->isPast();
                    if (! $pendingExpired) {
                        return ['error' => 'outstanding', 'successor' => $successor];
                    }
                    $successor->forceFill([
                        'status' => 'revoked',
                        'revoked_at' => now(),
                        'activation_code_hash' => null,
                        'activation_expires_at' => null,
                    ])->save();
                }

                $node->forceFill($nodeValues)->save();
                $lockedReplacement->forceFill(['metadata' => [
                    ...($lockedReplacement->metadata ?? []),
                    'replacement_candidate_node_id' => (string) $node->id,
                    'replacement_grant_issued_at' => now()->toIso8601String(),
                    'replacement_grant_issued_by' => $createdBy,
                ]])->save();

                return ['node' => $node];
            }, 3);
            if (($replacementResult['error'] ?? null) === 'invalid') {
                return response()->json([
                    'message' => 'The revoked node changed before replacement authorization completed.',
                    'code' => 'SYNC_REPLACEMENT_NODE_INVALID',
                ], 409);
            }
            if (($replacementResult['error'] ?? null) === 'outstanding') {
                /** @var SyncNode $successor */
                $successor = $replacementResult['successor'];

                return response()->json([
                    'message' => 'This revoked node already has a pending or active successor. Re-pair that successor instead of creating another node.',
                    'code' => 'SYNC_REPLACEMENT_ALREADY_ISSUED',
                    'data' => ['successor_node_id' => (string) $successor->id],
                ], 409);
            }
            $node = $replacementResult['node'];
        } else {
            $node->forceFill($nodeValues)->save();
        }

        return response()->json([
            'message' => 'Pairing code created. It is shown only once.',
            'data' => [
                'company' => $company->only(['id', 'company_code', 'company_name']),
                'node' => $node->fresh(['branch', 'till']),
                'pairing_code' => $pairingCode,
                'replacement_node_id' => $replacementNode ? (string) $replacementNode->id : null,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ], $node->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Issue a short-lived capability for one existing till at this node's
     * branch. The raw code is returned once and only its digest is stored.
     */
    public function createTerminalActivationCode(Request $request, string $node): JsonResponse
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud') {
            return response()->json([
                'message' => 'Terminal activation codes can only be issued by the cloud service.',
                'code' => 'SYNC_CLOUD_ENDPOINT_UNAVAILABLE',
            ], 409);
        }

        $company = app('company');
        $validated = $request->validate([
            'till_id' => ['required', 'uuid'],
        ]);
        $createdBy = $request->user()?->getAuthIdentifier();
        $createdBy = is_string($createdBy) && Str::isUuid($createdBy) ? $createdBy : null;

        $result = DB::transaction(function () use (
            $company,
            $node,
            $validated,
            $createdBy
        ): array {
            // Serializing issuance on the node prevents two administrators
            // from leaving two simultaneously valid codes for the same till.
            $syncNode = SyncNode::query()
                ->where('company_id', $company->id)
                ->where('deployment_mode', 'edge')
                ->whereHas('branch', fn ($query) => $query
                    ->where('company_id', $company->id)
                    ->where('status', 'active'))
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->whereNotNull('activated_at')
                ->lockForUpdate()
                ->find($node);
            if (! $syncNode || ! $syncNode->branch_id) {
                return ['error' => 'node'];
            }

            $till = PosTill::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $syncNode->branch_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($validated['till_id']);
            if (! $till) {
                return ['error' => 'till'];
            }

            SyncTerminalActivation::query()
                ->where('sync_node_id', $syncNode->id)
                ->where('till_id', $till->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            do {
                $rawCode = $this->newTerminalActivationCode();
                $codeHash = $this->terminalActivationCodeHash($rawCode);
            } while (SyncTerminalActivation::query()->where('code_hash', $codeHash)->exists());

            $ttlMinutes = max(
                1,
                min(30, (int) config('salexa_sync.security.terminal_activation_ttl_minutes', 10))
            );
            $expiresAt = now()->addMinutes($ttlMinutes);
            $activation = SyncTerminalActivation::query()->create([
                'sync_node_id' => $syncNode->id,
                'company_id' => $company->id,
                'branch_id' => $syncNode->branch_id,
                'till_id' => $till->id,
                'code_hash' => $codeHash,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
            ]);

            return compact('syncNode', 'till', 'activation', 'rawCode', 'expiresAt');
        }, 3);

        if (($result['error'] ?? null) === 'node') {
            return response()->json([
                'message' => 'Active sync node not found for this company.',
                'code' => 'SYNC_NODE_NOT_FOUND',
            ], 404);
        }
        if (($result['error'] ?? null) === 'till') {
            return response()->json([
                'message' => 'The selected terminal is not active at the sync node branch.',
                'code' => 'TERMINAL_BRANCH_MISMATCH',
            ], 422);
        }

        /** @var SyncNode $syncNode */
        $syncNode = $result['syncNode'];
        /** @var PosTill $till */
        $till = $result['till'];

        return response()->json([
            'message' => 'Terminal activation code created. It is shown only once.',
            'data' => [
                'terminal_activation_code' => $result['rawCode'],
                'expires_at' => $result['expiresAt']->toIso8601String(),
                'node' => $syncNode->only(['id', 'node_code', 'node_name', 'branch_id']),
                'terminal' => $till->only([
                    'id', 'company_id', 'branch_id', 'till_code', 'till_name', 'device_label', 'is_active',
                ]),
            ],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function suspend(Request $request, string $node): JsonResponse
    {
        if ($unavailable = $this->cloudMutationUnavailable()) {
            return $unavailable;
        }

        return $this->changeStatus($request, $node, 'suspended');
    }

    public function resume(Request $request, string $node): JsonResponse
    {
        if ($unavailable = $this->cloudMutationUnavailable()) {
            return $unavailable;
        }

        return $this->changeStatus($request, $node, 'active');
    }

    public function revoke(Request $request, string $node): JsonResponse
    {
        if ($unavailable = $this->cloudMutationUnavailable()) {
            return $unavailable;
        }
        $syncNode = DB::transaction(function () use ($node): ?SyncNode {
            $record = SyncNode::query()
                ->where('company_id', app('company')->id)
                ->lockForUpdate()
                ->find($node);
            if (! $record) {
                return null;
            }

            if ($record->status !== 'revoked' || ! $record->revoked_at) {
                $record->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'activation_code_hash' => null,
                    'activation_expires_at' => null,
                    'credential_secret' => null,
                    'credential_hash' => null,
                    'credential_version' => ((int) $record->credential_version) + 1,
                ])->save();
            }

            if (Schema::hasTable('sync_terminal_activations')) {
                SyncTerminalActivation::query()
                    ->where('sync_node_id', $record->id)
                    ->whereNull('consumed_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }

            return $record->fresh();
        }, 3);
        if (! $syncNode) {
            return response()->json(['message' => 'Sync node not found.'], 404);
        }

        return response()->json(['message' => 'Sync node revoked.', 'data' => $syncNode]);
    }

    public function rotate(Request $request, string $node): JsonResponse
    {
        if ($unavailable = $this->cloudMutationUnavailable()) {
            return $unavailable;
        }
        $syncNode = $this->companyNode($request, $node);
        if (! $syncNode || $syncNode->status !== 'active') {
            return response()->json(['message' => 'Active sync node not found.'], 404);
        }

        return response()->json([
            'message' => 'In-place credential rotation is disabled because it can strand an offline edge. Issue an explicitly authorized recovery pairing code instead.',
            'code' => 'SYNC_CREDENTIAL_ROTATION_REQUIRES_REPAIR',
            'data' => ['node_id' => (string) $syncNode->id],
        ], 409);
    }

    private function changeStatus(Request $request, string $node, string $status): JsonResponse
    {
        $syncNode = $this->companyNode($request, $node);
        if (! $syncNode || $syncNode->status === 'revoked') {
            return response()->json(['message' => 'Sync node not found.'], 404);
        }
        if ($status === 'active' && ! $syncNode->activated_at) {
            return response()->json(['message' => 'A node must be paired before it can be resumed.'], 409);
        }
        $syncNode->forceFill(['status' => $status])->save();

        return response()->json(['message' => "Sync node {$status}.", 'data' => $syncNode]);
    }

    private function companyNode(Request $request, string $id): ?SyncNode
    {
        return SyncNode::query()->where('company_id', app('company')->id)->find($id);
    }

    private function newNodeCode(Company $company, CompanyBranch $branch): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($company->company_code ?: 'NODE')));
        $branchCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($branch->branch_code ?: 'BR')));

        do {
            $code = substr($prefix, 0, 18).'-'.substr($branchCode, 0, 12).'-'.Str::upper(Str::random(8));
        } while (SyncNode::query()->where('node_code', $code)->exists());

        return $code;
    }

    private function newTerminalActivationCode(): string
    {
        return 'TERM-'.implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
    }

    private function terminalActivationCodeHash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    private function cloudMutationUnavailable(): ?JsonResponse
    {
        if (config('salexa_sync.deployment_mode') === 'cloud') {
            return null;
        }

        return response()->json([
            'message' => 'Sync-node management mutations are only available on a cloud deployment.',
            'code' => 'SYNC_CLOUD_ENDPOINT_UNAVAILABLE',
        ], 409);
    }
}
