<?php

namespace App\Services;

use App\Services\Auth\SuperAdminService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves one optional tenant branch without trusting a user's primary
 * branch from a different company.
 *
 * Platform administrators keep one global identity while changing company
 * workspaces. Their pos_users.branch_id can therefore belong to the company
 * they visited previously and must only ever be used as a fallback after it
 * has been proven to belong to the currently resolved company.
 */
class CompanyBranchContextService
{
    /**
     * Route default that marks a top-level branch_id as the branch a record
     * belongs to (a till, shift configuration or printer managed from Back
     * Office), not as the active workspace branch. Such a branch is still
     * checked for company ownership and user access, but it does not have to
     * match the session branch. Opt in per route:
     *   ->defaults(CompanyBranchContextService::RECORD_BRANCH_ROUTE_FLAG, true)
     */
    public const RECORD_BRANCH_ROUTE_FLAG = 'branch_id_names_record';

    public function __construct(
        private readonly BusinessModeBranchScopeService $branchAccess,
        private readonly SuperAdminService $superAdmins
    ) {}

    public function resolve(Request $request, string $companyId, bool $required = false): ?string
    {
        $companyBranchIds = $this->companyBranchIds($request, $companyId);

        $assertedBranchIds = collect($this->assertedScalarBranchIds($request));

        if ($assertedBranchIds->count() > 1) {
            $this->fail(
                409,
                'The supplied branch contexts do not match.',
                'BRANCH_CONTEXT_MISMATCH'
            );
        }

        $user = $request->user();
        $accessibleBranchIds = $this->accessibleBranchIds(
            $request,
            $companyId,
            $companyBranchIds
        );
        $branchId = $assertedBranchIds->first();

        if ($branchId === null) {
            $primaryBranchId = is_scalar($user?->branch_id ?? null)
                ? trim((string) $user->branch_id)
                : '';
            if ($primaryBranchId !== '' && in_array($primaryBranchId, $companyBranchIds, true)) {
                $branchId = $primaryBranchId;
            } elseif (count($accessibleBranchIds) === 1) {
                $branchId = $accessibleBranchIds[0];
            }
        }

        if ($branchId === null || $branchId === '') {
            if ($required) {
                $this->fail(
                    422,
                    'Select a branch for the current company.',
                    'WORKSPACE_BRANCH_REQUIRED'
                );
            }

            return null;
        }

        if (! in_array($branchId, $companyBranchIds, true)) {
            $this->fail(
                422,
                'The selected branch does not belong to this company.',
                'WORKSPACE_BRANCH_INVALID',
                ['branch_id' => $branchId]
            );
        }

        if (! in_array($branchId, $accessibleBranchIds, true)) {
            $this->fail(
                403,
                'You are not allocated to the selected branch.',
                'BRANCH_ACCESS_DENIED',
                ['branch_id' => $branchId]
            );
        }

        return $branchId;
    }

    /**
     * Validate every branch asserted by the request before a controller can
     * use it in a tenant query or write. Multi-branch filters are allowed here;
     * resolve() separately enforces that single active-workspace claims agree.
     *
     * @return array<int, string>
     */
    public function assertRequestBranches(
        Request $request,
        string $companyId,
        bool $enforceAccess = true
    ): array {
        $scalarBranchIds = $this->assertedScalarBranchIds($request);
        if (count($scalarBranchIds) > 1) {
            $this->fail(
                409,
                'The supplied branch contexts do not match.',
                'BRANCH_CONTEXT_MISMATCH'
            );
        }

        $branchIds = collect($scalarBranchIds)
            ->merge($this->normalizeMany($request->input('branch_ids', [])))
            ->merge($this->bodyBranchNamesRecord($request) ? [$request->input('branch_id')] : [])
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();

        if ($branchIds === []) {
            return [];
        }

        $companyBranchIds = $this->companyBranchIds($request, $companyId);
        $foreign = array_values(array_diff($branchIds, $companyBranchIds));
        if ($foreign !== []) {
            $this->fail(
                422,
                'One or more selected branches do not belong to this company.',
                'WORKSPACE_BRANCH_INVALID',
                ['branch_ids' => $foreign]
            );
        }

        if ($enforceAccess) {
            $accessible = $this->accessibleBranchIds(
                $request,
                $companyId,
                $companyBranchIds
            );
            $inaccessible = array_values(array_diff($branchIds, $accessible));
            if ($inaccessible !== []) {
                $this->fail(
                    403,
                    'You are not allocated to one or more selected branches.',
                    'BRANCH_ACCESS_DENIED',
                    ['branch_ids' => $inaccessible]
                );
            }
        }

        return $branchIds;
    }

    /** @return array<int, string> */
    private function companyBranchIds(Request $request, string $companyId): array
    {
        $cacheKey = '_company_branch_context.company_ids.'.strtolower($companyId);
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $branchIds = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->when(
                Schema::hasColumn('company_branches', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        $request->attributes->set($cacheKey, $branchIds);

        return $branchIds;
    }

    /** @return array<int, mixed> */
    private function normalizeMany(mixed $values): array
    {
        if (is_string($values)) {
            return str_contains($values, ',') ? explode(',', $values) : [$values];
        }

        return is_array($values) ? $values : [];
    }

    private function bodyBranchNamesRecord(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route
            && ($route->defaults[self::RECORD_BRANCH_ROUTE_FLAG] ?? false) === true;
    }

    /** @return array<int, string> */
    private function assertedScalarBranchIds(Request $request): array
    {
        return collect([
            $request->header('X-Branch-ID'),
            $this->bodyBranchNamesRecord($request) ? null : $request->input('branch_id'),
            $request->attributes->get('company_branch_id'),
            $request->attributes->get('business_mode_branch_id'),
            app()->bound('till') ? app('till')?->branch_id : null,
        ])
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The legacy platform owner is an App\Models\User rather than a PosUser,
     * so the ordinary user/branch pivot resolver cannot recognise it. Its
     * server-verified back-office platform token grants the current company's
     * branches without ever trusting a stale tenant user branch.
     *
     * @param array<int, string> $companyBranchIds
     * @return array<int, string>
     */
    private function accessibleBranchIds(
        Request $request,
        string $companyId,
        array $companyBranchIds
    ): array {
        $cacheKey = '_company_branch_context.accessible_ids.'.strtolower($companyId);
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $user = $request->user();
        if ($this->superAdmins->hasPlatformSessionAccess($user)) {
            $accessible = array_values($companyBranchIds);
        } else {
            $accessible = $this->branchAccess->accessibleBranchIds(
                $user,
                $companyId,
                $companyBranchIds
            );
        }

        $request->attributes->set($cacheKey, $accessible);

        return $accessible;
    }

    private function fail(int $status, string $message, string $code, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            ...$extra,
        ], $status));
    }
}
