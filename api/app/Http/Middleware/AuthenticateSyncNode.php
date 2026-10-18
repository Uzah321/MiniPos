<?php

namespace App\Http\Middleware;

use App\Models\SyncNode;
use App\Models\SyncReplayNonce;
use App\Services\Sync\LocalHubCompanyScopeService;
use App\Services\Sync\SyncRequestSigner;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Modules\Company\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSyncNode
{
    public function __construct(private readonly SyncRequestSigner $signer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('salexa_sync.deployment_mode') !== 'cloud') {
            return $this->deny(
                'SYNC_CLOUD_ENDPOINT_UNAVAILABLE',
                'Signed sync protocol endpoints are only available on a cloud deployment.',
                409
            );
        }

        $nodeId = trim((string) $request->header('X-Sync-Node'));
        $timestamp = trim((string) $request->header('X-Sync-Timestamp'));
        $nonce = trim((string) $request->header('X-Sync-Nonce'));
        $signature = strtolower(trim((string) $request->header('X-Sync-Signature')));

        if (! $nodeId || ! $timestamp || ! $nonce || ! $signature) {
            return $this->deny('SYNC_SIGNATURE_REQUIRED', 'Signed sync-node headers are required.', 401);
        }
        if (! ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > max(30, (int) config('salexa_sync.security.clock_skew_seconds', 300))) {
            return $this->deny('SYNC_TIMESTAMP_INVALID', 'The sync request timestamp is outside the allowed window.', 401);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            return $this->deny('SYNC_NONCE_INVALID', 'The sync request nonce is invalid.', 401);
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->deny('SYNC_SIGNATURE_INVALID', 'The sync request signature is invalid.', 401);
        }

        $node = SyncNode::query()
            ->with(['branch', 'till'])
            ->whereKey($nodeId)
            ->first();

        // A revoked credential has deliberately been erased, so it cannot
        // authenticate far enough to discover its own state. Return a narrow,
        // machine-readable result for the already-presented unguessable UUID;
        // the edge can then stop claiming it is paired and enter the explicit
        // same-scope replacement flow.
        if ($node && ($node->status === 'revoked' || $node->revoked_at)) {
            return $this->deny(
                'SYNC_NODE_REVOKED',
                'The sync node has been revoked. Use an administrator-issued replacement pairing code.',
                401
            );
        }
        if ($node && $node->status === 'suspended') {
            return $this->deny(
                'SYNC_NODE_SUSPENDED',
                'The sync node has been suspended by an administrator.',
                403
            );
        }

        if (! $node
            || $node->status !== 'active'
            || ! $node->activated_at
            || ! $node->credential_hash
            || ! $node->branch) {
            return $this->deny('SYNC_NODE_INVALID', 'The sync node is inactive or incomplete.', 401);
        }
        if ((string) $node->branch->company_id !== (string) $node->company_id
            || ($node->till && ((string) $node->till->company_id !== (string) $node->company_id
                || (string) $node->till->branch_id !== (string) $node->branch_id))) {
            return $this->deny('SYNC_NODE_SCOPE_INVALID', 'The sync node company/branch binding is invalid.', 403);
        }

        try {
            $credential = (string) $node->credential_secret;
        } catch (\Throwable) {
            return $this->deny(
                'SYNC_NODE_CREDENTIAL_INVALID',
                'The sync node credential cannot be verified. Pair the node again.',
                401
            );
        }
        if ($credential === '' || ! hash_equals((string) $node->credential_hash, hash('sha256', $credential))) {
            return $this->deny(
                'SYNC_NODE_CREDENTIAL_INVALID',
                'The sync node credential cannot be verified. Pair the node again.',
                401
            );
        }

        $expected = $this->signer->signature(
            $request->method(),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            $request->getContent(),
            $credential,
            $nodeId
        );
        if (! hash_equals($expected, $signature)) {
            return $this->deny('SYNC_SIGNATURE_INVALID', 'The sync request signature is invalid.', 401);
        }
        if (! app(LocalHubCompanyScopeService::class)->allowsCompany((string) $node->company_id)) {
            return $this->deny(
                'LOCAL_HUB_COMPANY_UNAVAILABLE',
                'This local Back Office is not provisioned for this company.',
                409
            );
        }

        try {
            SyncReplayNonce::query()
                ->where('sync_node_id', $node->id)
                ->where('expires_at', '<', now())
                ->delete();
            SyncReplayNonce::query()->create([
                'sync_node_id' => $node->id,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds(max(
                    60,
                    (int) config('salexa_sync.security.replay_nonce_ttl_seconds', 900)
                )),
            ]);
        } catch (QueryException) {
            return $this->deny('SYNC_REPLAY_DETECTED', 'This signed sync request has already been used.', 409);
        }

        $company = Company::with(['branches', 'activeLicense'])->find($node->company_id);
        if (! $company || strtolower((string) $company->status) !== 'active') {
            return $this->deny('SYNC_COMPANY_INACTIVE', 'The paired company is unavailable.', 403);
        }
        if ((bool) config('salexa_sync.local_hub', false) && $node->deployment_mode === 'android') {
            return $this->deny(
                'SYNC_ANDROID_LOCAL_HUB_UNAVAILABLE',
                'Android terminals must synchronize directly with Central.',
                409
            );
        }

        $node->forceFill(['last_seen_at' => now()])->saveQuietly();
        app()->instance('sync.node', $node);
        app()->instance('company', $company);
        app()->instance('sync.branch', $node->branch);
        if ($node->till) {
            app()->instance('till', $node->till);
        }
        $request->attributes->set('sync_node', $node);
        $request->attributes->set('sync_branch_id', (string) $node->branch_id);

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
