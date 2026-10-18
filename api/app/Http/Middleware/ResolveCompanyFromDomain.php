<?php

namespace App\Http\Middleware;

use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Services\Auth\SuperAdminService;
use App\Services\CompanyBranchContextService;
use App\Services\Sync\LocalHubCompanyScopeService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\TransientToken;
use Modules\Authentication\Http\Controllers\PosUserController;
use Modules\Authentication\Models\AuthSession;
use Modules\Company\Http\Controllers\CompanyController;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Models\PosTill;

/**
 * Resolve tenant context without requiring a per-company domain.
 *
 * The historical class name remains so existing route declarations keep
 * working. Precedence is: registered till credential, authenticated tenant
 * user, human company code, then legacy domain/host. Every asserted context
 * must identify the same company.
 */
class ResolveCompanyFromDomain
{
    public function __construct(
        private readonly SuperAdminService $superAdmins,
        private readonly ?CompanyBranchContextService $branchContexts = null,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $localHubScope = app(LocalHubCompanyScopeService::class);
        $localHubCompanyId = null;
        if ($localHubScope->isLocalHub()) {
            $localHubCompanyId = $localHubScope->provisionedCompanyId();
            if ($localHubCompanyId === null) {
                return $this->localHubCompanyUnavailable();
            }
        }

        $claims = [];
        $assertedTillToken = trim((string) $request->header('X-Till-Token'));
        $resolvedTill = $this->resolveTill($request);
        if ($assertedTillToken !== '' && ! $resolvedTill) {
            return response()->json([
                'message' => 'The terminal activation is invalid or inactive.',
                'code' => 'INVALID_TILL_TOKEN',
            ], 403);
        }
        if ($resolvedTill) {
            $claims['till'] = (string) $resolvedTill->company_id;
        }

        $edgeState = $this->pairedEdgeState();
        if ($edgeState) {
            $claims['edge_node'] = (string) $edgeState->company_id;
            if ($resolvedTill && (string) $resolvedTill->branch_id !== (string) $edgeState->branch_id) {
                return response()->json([
                    'message' => 'This terminal does not belong to the branch paired to this edge installation.',
                    'code' => 'EDGE_BRANCH_CONTEXT_MISMATCH',
                ], 403);
            }

            $assertedBranchId = $request->header('X-Branch-ID') ?: $request->input('branch_id');
            if (is_scalar($assertedBranchId)
                && trim((string) $assertedBranchId) !== ''
                && (string) $assertedBranchId !== (string) $edgeState->branch_id) {
                return response()->json([
                    'message' => 'This edge installation is paired to a different branch.',
                    'code' => 'EDGE_BRANCH_CONTEXT_MISMATCH',
                ], 403);
            }
        }

        $user = $this->requestUser($request);
        $platformSession = $user
            && $this->superAdmins->hasPlatformSessionAccess($user);
        if ($user && ($workspaceError = $this->deploymentWorkspaceError($request, $resolvedTill))) {
            return $workspaceError;
        }
        if ($user && ! $platformSession && isset($user->company_id)) {
            $claims['authenticated_user'] = (string) $user->company_id;
        }

        $selectedPlatformBranchId = null;
        if ($platformSession) {
            $selectedPlatformScope = $this->platformSessionScope($request);
            if ($selectedPlatformScope['company_id'] !== null) {
                $claims['platform_session'] = $selectedPlatformScope['company_id'];
                $selectedPlatformBranchId = $selectedPlatformScope['branch_id'];
            }
        }

        $companyCode = $this->companyCode($request);
        if ($companyCode !== null
            && ($this->isBackOfficeLoginRequest($request) || $platformSession)) {
            return response()->json([
                'message' => 'Company codes are not accepted for Back Office authentication.',
                'code' => 'COMPANY_CODE_NOT_ACCEPTED',
            ], 422);
        }
        if ($companyCode !== null) {
            $companyByCode = Company::query()->where('company_code', $companyCode)->first();
            if (! $companyByCode) {
                return $this->unknownCompany('COMPANY_CODE_UNKNOWN');
            }
            $claims['company_code'] = (string) $companyByCode->id;
        }

        $legacyCompany = null;
        $legacyDomainAllowed = config('salexa_sync.deployment_mode') !== 'cloud'
            || (bool) config('salexa_sync.allow_legacy_domain_resolution', false);
        if ($legacyDomainAllowed
            && ($this->hasLegacyDomainAssertion($request) || $claims === [])) {
            $legacyCompany = $this->resolveLegacyDomain($request);
            if ($legacyCompany) {
                $claims['legacy_domain'] = (string) $legacyCompany->id;
            }
        }

        $companyIds = array_values(array_unique(array_filter($claims)));
        if (count($companyIds) > 1) {
            return response()->json([
                'message' => 'The supplied company, user and device contexts do not match.',
                'code' => 'COMPANY_CONTEXT_MISMATCH',
            ], 403);
        }

        $companyId = $companyIds[0] ?? null;
        if (! $companyId && $this->isLocalHubBackOfficeLogin($request)) {
            // A local hub has exactly one Central-provisioned tenant. An
            // installer may therefore omit the company code at Back Office
            // login, but an empty, ambiguous or manually populated database
            // must never guess which company to authenticate against.
            $companyId = $localHubCompanyId;
        }
        if ($localHubCompanyId !== null && $companyId !== null
            && ! hash_equals($localHubCompanyId, (string) $companyId)) {
            return response()->json([
                'message' => 'This company is not provisioned on this local Back Office.',
                'code' => 'LOCAL_HUB_COMPANY_MISMATCH',
            ], 403);
        }
        if (! $companyId) {
            if ($this->allowsCompanylessPlatformLogin($request)) {
                $request->attributes->set('platform_context_required', true);

                return $next($request);
            }

            if ($platformSession && $this->allowsCompanylessPlatformIdentityRequest($request)) {
                $request->attributes->set('platform_context_required', true);

                return $next($request);
            }

            $code = config('salexa_sync.deployment_mode') === 'cloud'
                ? 'COMPANY_CODE_REQUIRED'
                : 'COMPANY_CONTEXT_REQUIRED';

            return response()->json([
                'message' => 'Enter the company code to continue.',
                'code' => $code,
            ], 422);
        }

        $company = ($legacyCompany && (string) $legacyCompany->id === $companyId)
            ? $legacyCompany
            : Company::query()->with(['branches', 'activeLicense'])->find($companyId);
        if (! $company) {
            return $this->unknownCompany();
        }

        if ($user && isset($user->company_id)
            && ! $platformSession
            && (string) $user->company_id !== (string) $company->id) {
            return response()->json([
                'message' => 'Authenticated user does not belong to this company.',
                'code' => 'COMPANY_CONTEXT_MISMATCH',
            ], 403);
        }

        $assertedCompanyId = $request->input('company_id');
        if (is_scalar($assertedCompanyId)
            && trim((string) $assertedCompanyId) !== ''
            && (string) $assertedCompanyId !== (string) $company->id) {
            return response()->json([
                'message' => 'The request company id does not match the authenticated company context.',
                'code' => 'COMPANY_CONTEXT_MISMATCH',
            ], 403);
        }

        app()->instance('company', $company);

        if ($resolvedTill) {
            $request->attributes->set('company_branch_id', (string) $resolvedTill->branch_id);
        } elseif ($selectedPlatformBranchId !== null) {
            $request->attributes->set('company_branch_id', $selectedPlatformBranchId);
        }

        $branchContexts = $this->branchContexts ?? app(CompanyBranchContextService::class);
        $assertedBranchIds = $branchContexts->assertRequestBranches(
            $request,
            (string) $company->id,
            $user !== null
        );

        if ($user) {
            $activeBranchId = $branchContexts->resolve($request, (string) $company->id);
            if ($activeBranchId !== null) {
                $request->attributes->set('company_branch_id', $activeBranchId);
            }
        } elseif (count($assertedBranchIds) === 1) {
            // Login and bootstrap requests do not have an authenticated user
            // yet. Ownership was still checked above, so downstream code can
            // safely consume the one asserted branch without trusting it as
            // an authorization decision.
            $request->attributes->set('company_branch_id', $assertedBranchIds[0]);
        }

        if ($resolvedTill) {
            $seenAt = now();
            $presenceCutoff = $seenAt->copy()->subSeconds(30);
            if (! $resolvedTill->last_seen_at
                || $resolvedTill->last_seen_at->lt($presenceCutoff)) {
                $resolvedTill->recordPresence([
                    'last_seen_at' => $seenAt,
                    'last_ip_address' => $request->ip(),
                    'last_user_agent' => $request->userAgent(),
                ], $presenceCutoff);
            }
            app()->instance('till', $resolvedTill);
            $request->attributes->set('resolved_till', $resolvedTill);
        }

        foreach (($request->route()?->parameters() ?? []) as $parameter) {
            if ($parameter instanceof Model
                && array_key_exists('company_id', $parameter->getAttributes())
                && (string) $parameter->getAttribute('company_id') !== (string) $company->id) {
                return response()->json([
                    'message' => 'Resource not found for this company.',
                    'code' => 'TENANT_RESOURCE_NOT_FOUND',
                ], 404);
            }
        }

        return $next($request);
    }

    /**
     * Central Back Office authentication starts before a tenant is selected.
     * Authentication is completed by PosUserController; this exception only
     * permits its credential-derived company or platform selection flow to run.
     * It is deliberately unavailable to Front Office and edge installations.
     */
    private function allowsCompanylessPlatformLogin(Request $request): bool
    {
        return config('salexa_sync.deployment_mode') === 'cloud'
            && ! (bool) config('salexa_sync.local_hub', false)
            && $request->isMethod('post')
            && $this->isBackOfficeLoginRequest($request);
    }

    /**
     * The current-company endpoint intentionally remains available before
     * Front Office login, so it cannot use the mandatory auth:sanctum
     * middleware. When a client explicitly supplies a bearer token, resolve
     * only that endpoint through Sanctum before deriving its tenant. This lets
     * a selected Back Office platform session use its server-side auth_session
     * scope without accepting a browser-supplied company code.
     */
    private function requestUser(Request $request): mixed
    {
        $user = $request->user();
        if ($user
            || ! $this->isCurrentCompanyRequest($request)
            || trim((string) $request->bearerToken()) === '') {
            return $user;
        }

        $user = $request->user('sanctum');
        if (! $user) {
            return null;
        }

        // Downstream tenant/branch services call request->user() without a
        // guard. Preserve explicit guard resolution while making this verified
        // Sanctum identity the default for the remainder of this request.
        $originalResolver = $request->getUserResolver();
        $request->setUserResolver(
            static fn ($guard = null) => $guard === null
                ? $user
                : $originalResolver($guard)
        );

        return $user;
    }

    private function isCurrentCompanyRequest(Request $request): bool
    {
        return $request->isMethod('get')
            && $request->route()?->getActionName() === CompanyController::class.'@currentCompany';
    }

    private function isLocalHubBackOfficeLogin(Request $request): bool
    {
        return config('salexa_sync.deployment_mode') === 'cloud'
            && (bool) config('salexa_sync.local_hub', false)
            && $request->isMethod('post')
            && $this->isBackOfficeLoginRequest($request);
    }

    private function isBackOfficeLoginRequest(Request $request): bool
    {
        $action = $request->route()?->getActionName();

        return $action === PosUserController::class.'@loginBackOffice'
            || ($action === PosUserController::class.'@login'
                && $request->input('workspace') === 'back-office');
    }

    /** @return array{company_id: ?string, branch_id: ?string} */
    private function platformSessionScope(Request $request): array
    {
        if (! Schema::hasTable('auth_sessions')) {
            return ['company_id' => null, 'branch_id' => null];
        }

        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $token instanceof Model || $token instanceof TransientToken) {
            return ['company_id' => null, 'branch_id' => null];
        }

        $scope = AuthSession::query()
            ->where('personal_access_token_id', $token->getKey())
            ->where('pos_user_id', $user->getKey())
            ->where('workspace', 'back-office')
            ->whereNull('revoked_at')
            ->first(['company_id', 'branch_id']);
        $companyId = trim((string) $scope?->company_id);
        $branchId = trim((string) $scope?->branch_id);

        return [
            'company_id' => $companyId === '' ? null : $companyId,
            'branch_id' => $branchId === '' ? null : $branchId,
        ];
    }

    private function localHubCompanyUnavailable()
    {
        return response()->json([
            'message' => 'This local Back Office must be provisioned for exactly one company before sign-in.',
            'code' => 'LOCAL_HUB_COMPANY_UNAVAILABLE',
        ], 409);
    }

    /**
     * A platform-only selection session may inspect or revoke its own identity.
     * Tenant routes remain unavailable until the dedicated selection endpoint
     * rotates that bootstrap token into a server-bound company session.
     */
    private function allowsCompanylessPlatformIdentityRequest(Request $request): bool
    {
        $action = $request->route()?->getActionName();

        return ($request->isMethod('get') && $action === PosUserController::class.'@me')
            || ($request->isMethod('post') && in_array($action, [
                PosUserController::class.'@logout',
                PosUserController::class.'@logoutAll',
            ], true));
    }

    private function resolveTill(Request $request): ?PosTill
    {
        $rawToken = trim((string) $request->header('X-Till-Token'));
        if ($rawToken === '') {
            return null;
        }

        return PosTill::query()
            ->with('branch')
            ->where('registration_token', hash('sha256', $rawToken))
            ->where('is_registered', true)
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->whereNotNull('branch_id')
            ->whereHas('branch', function ($query): void {
                $query->whereColumn('company_branches.company_id', 'pos_tills.company_id')
                    ->where('company_branches.status', 'active');
            })
            ->first();
    }

    private function companyCode(Request $request): ?string
    {
        $value = $request->header('X-Company-Code');
        if (! is_scalar($value) || trim((string) $value) === '') {
            $value = $request->input('company_code');
        }
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return strtoupper(trim((string) $value));
    }

    private function resolveLegacyDomain(Request $request): ?Company
    {
        $rawHost = $request->header('X-Company-Domain') ?: $request->getHost();
        if (! is_scalar($rawHost) || trim((string) $rawHost) === '') {
            return null;
        }

        $host = strtolower(trim((string) $rawHost));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return Company::query()
            ->where(function ($query) use ($host) {
                $query->whereRaw('LOWER(domain) = ?', [$host])
                    ->orWhere('host_ip', $host);
            })
            ->with(['branches', 'activeLicense'])
            ->first();
    }

    private function hasLegacyDomainAssertion(Request $request): bool
    {
        return trim((string) $request->header('X-Company-Domain')) !== '';
    }

    private function deploymentWorkspaceError(Request $request, ?PosTill $resolvedTill = null)
    {
        $mode = (string) config('salexa_sync.deployment_mode', 'standalone');
        if (! in_array($mode, ['cloud', 'edge'], true)) {
            return null;
        }

        $token = $request->user()?->currentAccessToken();
        $required = $mode === 'cloud' ? 'workspace:back-office' : 'workspace:front-office';
        if ($token
            && ! $token instanceof TransientToken
            && method_exists($token, 'can')
            && $token->can($required)) {
            return null;
        }

        // A mobile FOH talks directly to the shared cloud, but only after a
        // centrally provisioned Android node has issued this till's one-time
        // credential. Ordinary browser Front Office tokens remain blocked.
        if ($mode === 'cloud'
            && $resolvedTill
            && $token
            && ! $token instanceof TransientToken
            && method_exists($token, 'can')
            && $token->can('workspace:front-office')
            && SyncNode::query()
                ->where('company_id', $resolvedTill->company_id)
                ->where('branch_id', $resolvedTill->branch_id)
                ->where('till_id', $resolvedTill->id)
                ->where('deployment_mode', 'android')
                ->where('status', 'active')
                ->whereNotNull('activated_at')
                ->whereNull('revoked_at')
                ->exists()) {
            return null;
        }

        return response()->json([
            'message' => $mode === 'cloud'
                ? 'Front Office sessions are not permitted on the shared cloud deployment.'
                : 'Back Office sessions are not permitted on this dedicated edge deployment.',
            'code' => 'DEPLOYMENT_WORKSPACE_DENIED',
        ], 403);
    }

    private function pairedEdgeState(): ?SyncLocalState
    {
        if (config('salexa_sync.deployment_mode') !== 'edge'
            || ! Schema::hasTable('sync_local_states')) {
            return null;
        }

        return SyncLocalState::query()
            ->whereNotNull('node_id')
            ->whereNotNull('company_id')
            ->whereNotNull('branch_id')
            ->whereIn('status', ['paired', 'error'])
            ->latest()
            ->first();
    }

    private function unknownCompany(string $code = 'COMPANY_UNKNOWN')
    {
        return response()->json(['message' => 'Unknown company.', 'code' => $code], 404);
    }
}
