<?php

namespace Modules\Authentication\Services;

use App\Services\Auth\SuperAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\UserAccessAssignment;

final class EnterpriseAuthorizationService
{
    private const SUPER_ADMIN_ROLE_NAMES = [
        'Super Admin',
        'Super Administrator',
        'super admin',
        'super administrator',
        'super_admin',
        'super_administrator',
        'super-admin',
        'super-administrator',
    ];

    public function __construct(private readonly SuperAdminService $superAdmins) {}

    /**
     * Once a user has any canonical assignment, even a revoked or future one,
     * callers must stop consulting legacy role/user_branches data. Otherwise a
     * revoked scoped grant could silently regain access through stale pivots.
     */
    public function usesScopedAssignments(PosUser $user): bool
    {
        return Schema::hasTable('user_access_assignments')
            && UserAccessAssignment::query()
                ->where('pos_user_id', $user->getKey())
                ->exists();
    }

    public function can(
        PosUser $user,
        string $permission,
        AuthorizationScope $scope
    ): bool {
        $permission = trim($permission);
        if ($permission === ''
            || ! (bool) $user->is_active
            || (method_exists($user, 'trashed') && $user->trashed())) {
            return false;
        }

        // Only the separately allowlisted cloud owner gets a cross-tenant
        // bypass. A tenant role merely named "super admin" is not sufficient.
        if ($this->superAdmins->isPlatformAdministrator($user)) {
            return true;
        }

        if (! Schema::hasTable('user_access_assignments')) {
            return false;
        }

        return $this->effectiveAssignments($user, $scope)
            ->where('pos_user_id', $user->getKey())
            ->whereHas('role', function (Builder $role) use ($scope, $permission): void {
                $this->constrainRoleCompany($role, $scope->companyId)
                    ->where(function (Builder $entitlement) use ($permission): void {
                        $entitlement
                            ->whereIn('name', self::SUPER_ADMIN_ROLE_NAMES)
                            ->orWhereHas('permissions', function (Builder $permissions) use ($permission): void {
                                $permissions->where('permissions.name', $permission);
                            });
                    });
            })
            ->exists();
    }

    /** @param list<string> $permissions */
    public function canAny(
        PosUser $user,
        array $permissions,
        AuthorizationScope $scope
    ): bool {
        foreach (array_unique(array_filter(array_map('trim', $permissions))) as $permission) {
            if ($this->can($user, $permission, $scope)) {
                return true;
            }
        }

        return false;
    }

    public function hasScopeAccess(PosUser $user, AuthorizationScope $scope): bool
    {
        if (! (bool) $user->is_active
            || (method_exists($user, 'trashed') && $user->trashed())) {
            return false;
        }

        if ($this->superAdmins->isPlatformAdministrator($user)) {
            return true;
        }

        if (! Schema::hasTable('user_access_assignments')) {
            return false;
        }

        return $this->effectiveAssignments($user, $scope)
            ->whereHas('role', function (Builder $role) use ($scope): void {
                $this->constrainRoleCompany($role, $scope->companyId);
            })
            ->exists();
    }

    /** @return list<string> */
    public function effectivePermissionNames(PosUser $user, AuthorizationScope $scope): array
    {
        if (! (bool) $user->is_active
            || (method_exists($user, 'trashed') && $user->trashed())) {
            return [];
        }

        if (! $this->usesScopedAssignments($user)) {
            $user->loadMissing('role.permissions');
            if ($this->superAdmins->isPlatformAdministrator($user)
                || $this->roleIsSuperAdmin($user->role?->name)) {
                return $this->allPermissionNames();
            }

            return $user->role?->permissions?->pluck('name')
                ->filter()
                ->map(fn ($name): string => (string) $name)
                ->unique()
                ->sort()
                ->values()
                ->all() ?? [];
        }

        $assignments = $this->effectiveAssignments($user, $scope)
            ->whereHas('role', function (Builder $role) use ($scope): void {
                $this->constrainRoleCompany($role, $scope->companyId);
            })
            ->with('role.permissions')
            ->get();

        if ($assignments->contains(fn (UserAccessAssignment $assignment): bool => $this->roleIsSuperAdmin(
            $assignment->role?->name
        ))) {
            return $this->allPermissionNames();
        }

        return $assignments
            ->flatMap(fn (UserAccessAssignment $assignment) => $assignment->role?->permissions?->pluck('name') ?? [])
            ->filter()
            ->map(fn ($name): string => (string) $name)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function effectiveRoleNames(PosUser $user, AuthorizationScope $scope): array
    {
        if (! $this->usesScopedAssignments($user)) {
            return array_values(array_filter([(string) $user->role?->name]));
        }

        return $this->effectiveAssignments($user, $scope)
            ->whereHas('role', function (Builder $role) use ($scope): void {
                $this->constrainRoleCompany($role, $scope->companyId);
            })
            ->with('role:id,name')
            ->get()
            ->pluck('role.name')
            ->filter()
            ->map(fn ($name): string => (string) $name)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function isEffectiveSuperAdmin(PosUser $user, AuthorizationScope $scope): bool
    {
        if (! $this->usesScopedAssignments($user)) {
            return $this->superAdmins->isSuperAdmin($user);
        }

        return $this->effectiveAssignments($user, $scope)
            ->whereHas('role', function (Builder $role) use ($scope): void {
                $this->constrainRoleCompany($role, $scope->companyId)
                    ->whereIn('name', self::SUPER_ADMIN_ROLE_NAMES);
            })
            ->exists();
    }

    /**
     * Resolve branch access from active assignments for branch selectors and
     * Back Office logins. An empty result is authoritative when the user has
     * canonical assignments; callers may use legacy pivots only when
     * usesScopedAssignments() is false.
     *
     * @param  list<string>  $companyBranchIds
     * @return list<string>
     */
    public function accessibleBranchIds(
        PosUser $user,
        string $companyId,
        array $companyBranchIds = []
    ): array {
        if ($companyBranchIds === []) {
            $companyBranchIds = Schema::hasTable('company_branches')
                ? DB::table('company_branches')
                    ->where('company_id', $companyId)
                    ->when(
                        Schema::hasColumn('company_branches', 'deleted_at'),
                        fn ($query) => $query->whereNull('deleted_at')
                    )
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->all()
                : [];
        }
        $companyBranchIds = array_values(array_unique(array_map('strval', $companyBranchIds)));

        if ($this->superAdmins->isPlatformAdministrator($user)) {
            return $companyBranchIds;
        }
        if (! $this->usesScopedAssignments($user)) {
            return [];
        }

        $assignments = UserAccessAssignment::query()
            ->where('pos_user_id', $user->getKey())
            ->effectiveAt(now())
            ->whereHas('role', function (Builder $role) use ($companyId): void {
                $this->constrainRoleCompany($role, $companyId);
            })
            ->get([
                'scope_type', 'country_id', 'company_id', 'brand_id',
                'complex_id', 'shop_id', 'till_id',
            ]);

        $companyCountryId = Schema::hasTable('companies')
            ? trim((string) DB::table('companies')->where('id', $companyId)->value('country_id'))
            : '';
        $allBranchesGranted = $assignments->contains(function (UserAccessAssignment $assignment) use (
            $companyId,
            $companyCountryId
        ): bool {
            return $assignment->scope_type === 'global'
                || ($assignment->scope_type === 'country'
                    && $companyCountryId !== ''
                    && (string) $assignment->country_id === $companyCountryId)
                || ($assignment->scope_type === 'company'
                    && (string) $assignment->company_id === $companyId);
        });
        if ($allBranchesGranted) {
            return $companyBranchIds;
        }

        $branchIds = collect();
        if (Schema::hasTable('shops')) {
            $brandIds = $assignments->where('scope_type', 'brand')->pluck('brand_id')->filter();
            $complexIds = $assignments->where('scope_type', 'complex')->pluck('complex_id')->filter();
            $shopIds = $assignments->where('scope_type', 'shop')->pluck('shop_id')->filter();
            if ($brandIds->isNotEmpty() || $complexIds->isNotEmpty() || $shopIds->isNotEmpty()) {
                $branchIds = DB::table('shops')
                    ->where('company_id', $companyId)
                    ->where(function ($scope) use ($brandIds, $complexIds, $shopIds): void {
                        if ($brandIds->isNotEmpty()) {
                            $scope->orWhereIn('brand_id', $brandIds->all());
                        }
                        if ($complexIds->isNotEmpty()) {
                            $scope->orWhereIn('complex_id', $complexIds->all());
                        }
                        if ($shopIds->isNotEmpty()) {
                            $scope->orWhereIn('id', $shopIds->all());
                        }
                    })
                    ->pluck('branch_id');
            }
        } else {
            // During a rolling hierarchy migration, branch IDs are the
            // one-to-one shop compatibility IDs.
            $branchIds = $assignments
                ->where('scope_type', 'shop')
                ->pluck('shop_id');
        }

        $tillIds = $assignments->where('scope_type', 'till')->pluck('till_id')->filter();
        if ($tillIds->isNotEmpty() && Schema::hasTable('pos_tills')) {
            $branchIds = $branchIds->merge(
                DB::table('pos_tills')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $tillIds->all())
                    ->pluck('branch_id')
            );
        }

        return $branchIds
            ->map(fn ($id): string => (string) $id)
            ->filter(fn (string $id): bool => in_array($id, $companyBranchIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @throws AuthorizationException */
    public function authorize(
        PosUser $user,
        string $permission,
        AuthorizationScope $scope
    ): void {
        if (! $this->can($user, $permission, $scope)) {
            throw new AuthorizationException('This action is not authorized for the current enterprise scope.');
        }
    }

    private function effectiveAssignments(
        PosUser $user,
        AuthorizationScope $scope
    ): Builder {
        return UserAccessAssignment::query()
            ->where('pos_user_id', $user->getKey())
            ->whereIn('scope_key', $scope->lineageKeys())
            ->effectiveAt(now());
    }

    private function constrainRoleCompany(Builder $role, ?string $companyId): Builder
    {
        return $role->where(function (Builder $tenant) use ($companyId): void {
            $tenant->whereNull('company_id');
            if ($companyId !== null) {
                $tenant->orWhere('company_id', $companyId);
            }
        });
    }

    private function roleIsSuperAdmin(mixed $name): bool
    {
        $normalised = strtolower(str_replace([' ', '-'], '_', trim((string) $name)));

        return in_array($normalised, ['super_admin', 'super_administrator'], true);
    }

    /** @return list<string> */
    private function allPermissionNames(): array
    {
        return Schema::hasTable('permissions')
            ? DB::table('permissions')->orderBy('name')->pluck('name')
                ->map(fn ($name): string => (string) $name)
                ->all()
            : [];
    }
}
