<?php

namespace App\Http\Middleware;

use App\Services\Auth\SuperAdminService;
use App\Services\CompanySettingService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Symfony\Component\HttpFoundation\Response;

class RequireBusinessMode
{
    public function __construct(
        private readonly CompanySettingService $settings,
        private readonly ?SuperAdminService $superAdmins = null,
        private readonly ?EnterpriseAuthorizationService $enterpriseAuthorization = null,
    ) {}

    public function handle(Request $request, Closure $next, string ...$allowedModes): Response
    {
        $allowedModes = collect($allowedModes)
            ->map(fn ($mode) => strtolower(trim($mode)))
            ->filter(fn ($mode) => in_array($mode, ['retail', 'hospitality'], true))
            ->unique()
            ->values()
            ->all();

        if ($allowedModes === []) {
            return response()->json([
                'message' => 'This route does not have a valid business mode configured.',
                'code' => 'BUSINESS_MODE_CONFIGURATION_ERROR',
            ], 500);
        }

        if (! app()->bound('company')) {
            return response()->json([
                'message' => 'The company context must be resolved before checking business mode.',
                'code' => 'BUSINESS_MODE_COMPANY_REQUIRED',
            ], 500);
        }

        $company = app('company');
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Authentication is required for this operation.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $platformSession = ($this->superAdmins ?? app(SuperAdminService::class))
            ->hasPlatformSessionAccess($user);

        if (! $platformSession
            && (string) ($user->company_id ?? '') !== (string) ($company->id ?? '')) {
            return response()->json([
                'message' => 'This user does not belong to the resolved company.',
                'code' => 'COMPANY_ACCESS_DENIED',
            ], 403);
        }

        $this->loadBusinessModeRelations($company, $user);

        $companyBranches = collect($company->branches ?? []);
        $authorization = $this->enterpriseAuthorization
            ?? app(EnterpriseAuthorizationService::class);
        $usesScopedAssignments = $user instanceof PosUser
            && $authorization->usesScopedAssignments($user);
        $accessibleBranchIds = $usesScopedAssignments
            ? collect($authorization->accessibleBranchIds(
                $user,
                (string) $company->id,
                $companyBranches->pluck('id')->map(fn ($id): string => (string) $id)->all()
            ))
            : collect($user->branches ?? [])
                ->pluck('id')
                ->push($user->branch_id ?? null)
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();
        // A stale legacy role label must not expand the branch boundary once
        // canonical assignments exist.
        $isSuperAdmin = $platformSession
            || (! $usesScopedAssignments && $this->isSuperAdmin($user));

        $selectedBranchIds = $this->selectedBranchIds($request);
        if ($selectedBranchIds === null) {
            return response()->json([
                'message' => 'The selected branch filters are invalid.',
                'code' => 'BUSINESS_MODE_BRANCH_INVALID',
            ], 422);
        }

        $branchId = $this->workspaceAnchorBranchId(
            $request,
            $user,
            $accessibleBranchIds,
            $companyBranches,
            $selectedBranchIds,
            $isSuperAdmin,
            $platformSession,
        );

        if (! $branchId) {
            return response()->json([
                'message' => 'Select a branch before using a mode-specific operation.',
                'code' => 'BUSINESS_MODE_BRANCH_REQUIRED',
            ], 422);
        }

        $branch = $companyBranches->first(
            fn ($candidate) => (string) ($candidate->id ?? '') === (string) $branchId
        );

        if (! $branch) {
            return response()->json([
                'message' => 'The selected branch does not belong to this company.',
                'code' => 'BUSINESS_MODE_BRANCH_INVALID',
            ], 422);
        }

        if (! $isSuperAdmin && ! $accessibleBranchIds->contains((string) $branchId)) {
            return response()->json([
                'message' => 'You are not allocated to the selected branch.',
                'code' => 'BRANCH_ACCESS_DENIED',
            ], 403);
        }

        $currentMode = $this->settings->isHospitalityMode(
            (string) $company->id,
            (string) $branchId
        ) ? 'hospitality' : 'retail';

        foreach ($selectedBranchIds as $selectedBranchId) {
            $selectedBranch = $companyBranches->first(
                fn ($candidate) => (string) ($candidate->id ?? '') === (string) $selectedBranchId
            );

            if (! $selectedBranch) {
                return response()->json([
                    'message' => 'A selected branch does not belong to this company.',
                    'code' => 'BUSINESS_MODE_BRANCH_INVALID',
                    'branch_id' => (string) $selectedBranchId,
                ], 422);
            }

            if (! $isSuperAdmin && ! $accessibleBranchIds->contains((string) $selectedBranchId)) {
                return response()->json([
                    'message' => 'You are not allocated to a selected branch.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                    'branch_id' => (string) $selectedBranchId,
                ], 403);
            }

            $selectedMode = (string) $selectedBranchId === (string) $branchId
                ? $currentMode
                : ($this->settings->isHospitalityMode(
                    (string) $company->id,
                    (string) $selectedBranchId
                ) ? 'hospitality' : 'retail');

            if ($selectedMode !== $currentMode) {
                return response()->json([
                    'message' => 'Selected branches must operate in the same mode as the active workspace branch.',
                    'code' => 'BUSINESS_MODE_MISMATCH',
                    'required_modes' => [$currentMode],
                    'current_mode' => $currentMode,
                    'branch_id' => (string) $branchId,
                    'conflicting_branch_id' => (string) $selectedBranchId,
                    'conflicting_branch_mode' => $selectedMode,
                ], 409);
            }
        }

        if (! in_array($currentMode, $allowedModes, true)) {
            return response()->json([
                'message' => sprintf(
                    'This operation is only available in %s mode; the active workspace branch is operating in %s mode.',
                    implode(' or ', $allowedModes),
                    $currentMode
                ),
                'code' => 'BUSINESS_MODE_MISMATCH',
                'required_modes' => $allowedModes,
                'current_mode' => $currentMode,
                'branch_id' => (string) $branchId,
            ], 409);
        }

        $request->attributes->set('business_mode', $currentMode);
        $request->attributes->set('business_mode_branch_id', (string) $branchId);

        return $next($request);
    }

    private function loadBusinessModeRelations(mixed $company, mixed $user): void
    {
        if ($company instanceof Model && ! $company->relationLoaded('branches')) {
            $company->loadMissing('branches');
        }

        if ($user instanceof Model) {
            $relations = [];
            if (! $user->relationLoaded('role')) {
                $relations[] = 'role';
            }
            if (! $user->relationLoaded('branches')) {
                $relations[] = 'branches';
            }
            if ($relations !== []) {
                $user->loadMissing($relations);
            }
        }
    }

    private function workspaceAnchorBranchId(
        Request $request,
        mixed $user,
        mixed $accessibleBranchIds,
        mixed $companyBranches,
        array $selectedBranchIds,
        bool $isSuperAdmin,
        bool $platformSession,
    ): ?string {
        $headerBranchId = $this->scalarId($request->header('X-Branch-ID'));
        $tillBranchId = app()->bound('till')
            ? $this->scalarId(app('till')?->branch_id)
            : null;
        $userBranchId = $platformSession
            ? null
            : $this->scalarId($user->branch_id ?? null);
        $explicitBranchId = count($selectedBranchIds) === 1
            ? $selectedBranchIds[0]
            : null;

        $branchId = $headerBranchId
            ?: $tillBranchId
            ?: $userBranchId
            ?: $explicitBranchId;

        if (! $branchId && $accessibleBranchIds->count() === 1) {
            $branchId = (string) $accessibleBranchIds->first();
        }

        if (! $branchId && $isSuperAdmin && $companyBranches->count() === 1) {
            $branchId = (string) ($companyBranches->first()->id ?? '');
        }

        return $branchId ?: null;
    }

    private function selectedBranchIds(Request $request): ?array
    {
        $branchIds = [];
        $branchIdInput = $request->input('branch_id');

        if ($branchIdInput !== null && $branchIdInput !== '') {
            $branchId = $this->scalarId($branchIdInput);
            if (! $branchId) {
                return null;
            }
            $branchIds[] = $branchId;
        }

        $branchIdsInput = $request->input('branch_ids', []);
        if (is_string($branchIdsInput)) {
            $branchIdsInput = explode(',', $branchIdsInput);
        }
        if ($branchIdsInput === null) {
            $branchIdsInput = [];
        }
        if (! is_array($branchIdsInput)) {
            return null;
        }

        foreach ($branchIdsInput as $branchIdInput) {
            $branchId = $this->scalarId($branchIdInput);
            if (! $branchId) {
                return null;
            }
            $branchIds[] = $branchId;
        }

        return collect($branchIds)->unique()->values()->all();
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isSuperAdmin(mixed $user): bool
    {
        $normalise = static fn ($value) => strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
        $superAdminNames = ['super_admin', 'super_administrator'];

        return (bool) ($user->is_super_admin ?? false)
            || in_array($normalise($user->user_type ?? ''), $superAdminNames, true)
            || in_array($normalise($user->role?->name ?? ''), $superAdminNames, true);
    }
}
