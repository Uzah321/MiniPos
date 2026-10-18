<?php

namespace App\Services;

use App\Services\Auth\SuperAdminService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;

class BusinessModeBranchScopeService
{
    public function __construct(
        private readonly CompanySettingService $settings,
        private readonly ?SuperAdminService $superAdmins = null,
        private readonly ?EnterpriseAuthorizationService $enterpriseAuthorization = null,
    ) {}

    /**
     * Resolve the branch ids a mode-aware workspace/report may read.
     *
     * X-Branch-ID is deliberately the first mode anchor: branch_id and
     * branch_ids are report filters inside the active workspace, not a way to
     * switch that workspace to another business mode.
     *
     * @param  array<int, mixed>  $requestedBranchIds
     * @return array{business_mode: string, context_branch_id: string, branch_ids: array<int, string>, accessible_branch_ids: array<int, string>}
     */
    public function resolve(Request $request, string $companyId, array $requestedBranchIds = []): array
    {
        $user = $request->user();
        if (! $user) {
            $this->fail(401, 'Authentication is required for this report.', 'UNAUTHENTICATED');
        }

        $companyBranchIds = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $accessibleBranchIds = $this->accessibleBranchIds($user, $companyId, $companyBranchIds);
        if ($accessibleBranchIds === []) {
            $this->fail(403, 'No branches are allocated to this user.', 'BRANCH_ACCESS_DENIED');
        }

        $requestedBranchIds = $this->normalizeIds($requestedBranchIds);
        $inaccessibleRequestedIds = array_values(array_diff($requestedBranchIds, $accessibleBranchIds));
        if ($inaccessibleRequestedIds !== []) {
            $this->fail(
                403,
                'You are not allocated to one or more selected branches.',
                'BRANCH_ACCESS_DENIED',
                ['branch_ids' => $inaccessibleRequestedIds]
            );
        }

        $contextBranchId = $this->firstScalar([
            $request->header('X-Branch-ID'),
            $request->input('branch_id'),
            count($requestedBranchIds) === 1 ? $requestedBranchIds[0] : null,
            $request->attributes->get('company_branch_id'),
            $request->attributes->get('business_mode_branch_id'),
            app()->bound('till') ? app('till')?->branch_id : null,
            in_array((string) ($user->branch_id ?? ''), $companyBranchIds, true)
                ? $user->branch_id
                : null,
            count($accessibleBranchIds) === 1 ? $accessibleBranchIds[0] : null,
        ]);

        if ($contextBranchId !== null && ! in_array($contextBranchId, $companyBranchIds, true)) {
            $this->fail(
                422,
                'The active workspace branch does not belong to this company.',
                'WORKSPACE_BRANCH_INVALID',
                ['branch_id' => $contextBranchId]
            );
        }

        if ($contextBranchId !== null && ! in_array($contextBranchId, $accessibleBranchIds, true)) {
            $this->fail(
                403,
                'You are not allocated to the active workspace branch.',
                'BRANCH_ACCESS_DENIED',
                ['branch_id' => $contextBranchId]
            );
        }

        if ($contextBranchId === null) {
            $modes = collect($accessibleBranchIds)
                ->map(fn ($branchId) => $this->settings->businessMode($companyId, $branchId))
                ->unique()
                ->values();

            if ($modes->count() !== 1) {
                $this->fail(
                    422,
                    'Select an active workspace branch before using an all-branches report.',
                    'WORKSPACE_BRANCH_REQUIRED'
                );
            }

            $contextBranchId = $accessibleBranchIds[0];
        }

        $businessMode = $this->settings->businessMode($companyId, $contextBranchId);
        $modeBranchIds = $this->settings->branchIdsForMode(
            $companyId,
            $businessMode,
            $accessibleBranchIds
        );

        $oppositeModeBranchIds = array_values(array_diff($requestedBranchIds, $modeBranchIds));
        if ($oppositeModeBranchIds !== []) {
            $this->fail(
                409,
                sprintf(
                    'The selected branch is not available in the active %s workspace.',
                    $businessMode
                ),
                'BUSINESS_MODE_MISMATCH',
                [
                    'current_mode' => $businessMode,
                    'context_branch_id' => $contextBranchId,
                    'branch_ids' => $oppositeModeBranchIds,
                ]
            );
        }

        $branchIds = $requestedBranchIds !== [] ? $requestedBranchIds : $modeBranchIds;
        if ($branchIds === []) {
            $this->fail(
                403,
                sprintf('No accessible %s branches are available to this user.', $businessMode),
                'BUSINESS_MODE_BRANCH_SCOPE_EMPTY'
            );
        }

        return [
            'business_mode' => $businessMode,
            'context_branch_id' => $contextBranchId,
            'branch_ids' => array_values($branchIds),
            'accessible_branch_ids' => array_values($accessibleBranchIds),
        ];
    }

    /**
     * @param  array<int, string>  $companyBranchIds
     * @return array<int, string>
     */
    public function accessibleBranchIds(mixed $user, string $companyId, array $companyBranchIds = []): array
    {
        if (! $user) {
            return [];
        }

        if ($companyBranchIds === []) {
            $companyBranchIds = DB::table('company_branches')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        }

        $enterpriseAuthorization = $this->enterpriseAuthorization
            ?? app(EnterpriseAuthorizationService::class);
        if ($user instanceof PosUser
            && $enterpriseAuthorization->usesScopedAssignments($user)) {
            return $enterpriseAuthorization->accessibleBranchIds(
                $user,
                $companyId,
                $companyBranchIds
            );
        }

        if ($this->isSuperAdmin($user)) {
            return array_values($companyBranchIds);
        }

        $branchIds = collect();
        if (method_exists($user, 'branches')) {
            $branchIds = $user->branches()
                ->where('company_branches.company_id', $companyId)
                ->pluck('company_branches.id');
        }

        if ($user->branch_id ?? null) {
            $branchIds->push((string) $user->branch_id);
        }

        return $branchIds
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => in_array($id, $companyBranchIds, true))
            ->unique()
            ->values()
            ->all();
    }

    public function isSuperAdmin(mixed $user): bool
    {
        $normalize = static fn ($value) => strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
        $names = ['super_admin', 'super_administrator'];

        // Legacy platform-owner sessions use App\Models\User rather than a
        // tenant PosUser. The signed platform token is the authoritative
        // signal that this identity may select branches in any company.
        $platformOwner = ($this->superAdmins ?? app(SuperAdminService::class))
            ->hasPlatformSessionAccess($user);

        return $platformOwner
            || (bool) ($user->is_super_admin ?? false)
            || in_array($normalize($user->user_type ?? ''), $names, true)
            || in_array($normalize(data_get($user, 'role.name')), $names, true);
    }

    /** @param array<int, mixed> $values */
    private function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $values */
    private function normalizeIds(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
