<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Auth\SuperAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Services\AccessAssignmentService;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\RolesAndPermission\Models\Role;

final class AccessAssignmentController extends Controller
{
    public function __construct(
        private readonly AccessAssignmentService $assignments,
        private readonly EnterpriseScopeResolver $scopes,
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly SuperAdminService $superAdmins,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = app('company');
        $validated = $request->validate([
            'user_id' => ['nullable', 'string'],
            'user_q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                UserAccessAssignment::STATUS_ACTIVE,
                UserAccessAssignment::STATUS_SUSPENDED,
                UserAccessAssignment::STATUS_REVOKED,
            ])],
        ]);

        $query = UserAccessAssignment::query()
            ->with(['role:id,name,company_id', 'user:id,name,username,company_id'])
            ->whereHas('user', fn ($query) => $query->where('company_id', $company->id))
            ->when(isset($validated['user_id']), fn ($query) => $query
                ->where('pos_user_id', $validated['user_id']))
            ->when(isset($validated['status']), fn ($query) => $query
                ->where('status', $validated['status']))
            ->latest();

        $actor = $request->user();
        if ($actor instanceof PosUser && $this->authorization->usesScopedAssignments($actor)) {
            // Scope-management lists are small administrative datasets. Apply
            // the same target-scope authorization to every returned row so a
            // shop administrator cannot enumerate another shop's grants.
            $rows = $query->get()->filter(fn (UserAccessAssignment $assignment): bool => $this->authorization->can(
                $actor,
                'manage_access_assignments',
                $assignment->enterpriseScope()
            ))->values();
        } else {
            $rows = $query->paginate(min(100, max(1, (int) $request->input('per_page', 25))));
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                // Keep the administration screen on this already-authorized
                // endpoint; it must not depend on broader legacy user/role
                // listing permissions.
                'users' => $this->scopeSafeUserLookup($request, $company),
                'roles' => Role::query()
                    ->where('company_id', $company->id)
                    ->when(
                        ! $this->superAdmins->hasPlatformSessionAccess($request->user()),
                        fn ($roles) => $roles->whereNotIn('name', [
                            'Super Admin',
                            'Super Administrator',
                            'super admin',
                            'super administrator',
                            'super_admin',
                            'super_administrator',
                            'super-admin',
                            'super-administrator',
                        ])
                    )
                    ->orderBy('name')
                    ->get(['id', 'name', 'company_id']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = app('company');
        $validated = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('pos_users', 'id')->where('company_id', $company->id),
            ],
            'role_id' => ['required', 'string'],
            'scope_type' => ['required', Rule::enum(EnterpriseScope::class)],
            'target_id' => ['nullable', 'string'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $scopeType = EnterpriseScope::from($validated['scope_type']);
        $targetId = $scopeType === EnterpriseScope::Global
            ? null
            : trim((string) ($validated['target_id'] ?? ''));
        if ($scopeType !== EnterpriseScope::Global && $targetId === '') {
            return response()->json([
                'message' => 'A target is required for this enterprise scope.',
                'errors' => ['target_id' => ['Select the scope target.']],
            ], 422);
        }

        $this->assertScopeCanBeManaged($request, $scopeType, $targetId);
        $role = Role::query()
            ->with('permissions:id,name')
            ->whereKey($validated['role_id'])
            ->where(function ($query) use ($company, $scopeType): void {
                $query->where('company_id', $company->id);
                if ($scopeType === EnterpriseScope::Global
                    && $this->superAdmins->hasPlatformSessionAccess(request()->user())) {
                    $query->orWhereNull('company_id');
                }
            })
            ->firstOrFail();
        $user = PosUser::query()->where('company_id', $company->id)->findOrFail($validated['user_id']);
        $scope = $this->scopes->resolve($scopeType, $targetId);
        $this->assertRoleCanBeGranted($request, $role, $scope);
        abort_unless(
            $this->canManageAssignmentTarget($request->user(), $user, (string) $company->id),
            403,
            'The selected user is outside your access-assignment scope.'
        );

        $assignment = $this->assignments->grant(
            $user,
            $role,
            $scopeType,
            $targetId,
            $request->user(),
            $validated['reason'] ?? null,
            isset($validated['valid_from']) ? CarbonImmutable::parse($validated['valid_from']) : null,
            isset($validated['valid_until']) ? CarbonImmutable::parse($validated['valid_until']) : null,
        );

        return response()->json([
            'message' => 'Enterprise access assignment saved.',
            'data' => $assignment->load(['role', 'user']),
        ], 201);
    }

    public function revoke(Request $request, string $assignmentId): JsonResponse
    {
        $company = app('company');
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $assignment = UserAccessAssignment::query()
            ->whereKey($assignmentId)
            ->whereHas('user', fn ($query) => $query->where('company_id', $company->id))
            ->firstOrFail();
        $this->assertScopeCanBeManaged(
            $request,
            EnterpriseScope::from($assignment->scope_type),
            $assignment->scope_type === EnterpriseScope::Global->value
                ? null
                : $assignment->{EnterpriseScope::from($assignment->scope_type)->column()},
        );

        return response()->json([
            'message' => 'Enterprise access assignment revoked.',
            'data' => $this->assignments->revoke(
                $assignment,
                $request->user(),
                $validated['reason'] ?? null,
            ),
        ]);
    }

    private function assertScopeCanBeManaged(
        Request $request,
        EnterpriseScope $scopeType,
        ?string $targetId,
    ): void {
        if (in_array($scopeType, [EnterpriseScope::Global, EnterpriseScope::Country], true)) {
            abort_unless(
                $this->superAdmins->hasPlatformSessionAccess($request->user()),
                403,
                'Only the platform administrator may manage global or country access.'
            );

            return;
        }

        $scope = $this->scopes->resolve($scopeType, $targetId);
        abort_unless(
            (string) $scope->companyId === (string) app('company')->id,
            403,
            'The access target is outside the resolved company.'
        );
        $actor = $request->user();
        if ($actor instanceof PosUser && $this->authorization->usesScopedAssignments($actor)) {
            abort_unless(
                $this->authorization->can($actor, 'manage_access_assignments', $scope),
                403,
                'You may not manage access at a broader enterprise scope.'
            );
        }
    }

    private function scopeSafeUserLookup(Request $request, mixed $company)
    {
        $actor = $request->user();
        $query = PosUser::query()
            ->where('company_id', $company->id)
            ->when($request->filled('user_q'), function (Builder $users) use ($request): void {
                $needle = addcslashes(trim((string) $request->input('user_q')), '\\%_');
                $users->where(function (Builder $search) use ($needle): void {
                    $search->where('name', 'like', "%{$needle}%")
                        ->orWhere('username', 'like', "%{$needle}%")
                        ->orWhere('employee_code', 'like', "%{$needle}%");
                });
            })
            ->with([
                'branches:id',
                'accessAssignments' => fn ($assignments) => $assignments->effectiveAt(now()),
            ])
            ->orderBy('name');

        $scopedActor = $actor instanceof PosUser
            && ! $this->superAdmins->hasPlatformSessionAccess($actor)
            && $this->authorization->usesScopedAssignments($actor);
        $allowedBranchIds = $scopedActor
            ? $this->authorization->accessibleBranchIds($actor, (string) $company->id)
            : null;
        if ($scopedActor) {
            $query->where(function (Builder $scope) use ($allowedBranchIds): void {
                $scope->whereIn('branch_id', $allowedBranchIds)
                    ->orWhereHas('branches', fn (Builder $branches) => $branches
                        ->whereIn('company_branches.id', $allowedBranchIds));
            });
        }

        $users = $query
            ->limit(100)
            ->get(['id', 'name', 'username', 'branch_id', 'company_id']);
        if ($scopedActor) {
            $users = $users->filter(fn (PosUser $user): bool => $this->canManageAssignmentTarget(
                $actor,
                $user,
                (string) $company->id,
                $allowedBranchIds
            ));
        }

        return $users
            ->map(fn (PosUser $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'username' => $user->username,
                'branch_id' => $user->branch_id,
            ])
            ->values();
    }

    /** @param list<string>|null $allowedBranchIds */
    private function canManageAssignmentTarget(
        mixed $actor,
        PosUser $user,
        string $companyId,
        ?array $allowedBranchIds = null
    ): bool {
        if (! $actor instanceof PosUser
            || $this->superAdmins->hasPlatformSessionAccess($actor)
            || ! $this->authorization->usesScopedAssignments($actor)) {
            return true;
        }

        $allowedBranchIds ??= $this->authorization->accessibleBranchIds($actor, $companyId);
        $user->loadMissing(['branches:id', 'accessAssignments']);
        $userBranchIds = collect([$user->branch_id])
            ->merge($user->branches->pluck('id'))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique();
        if ($userBranchIds->isEmpty()
            || $userBranchIds->diff($allowedBranchIds)->isNotEmpty()) {
            return false;
        }

        return $user->accessAssignments
            ->filter(fn (UserAccessAssignment $assignment): bool => $assignment->status === UserAccessAssignment::STATUS_ACTIVE
                && $assignment->revoked_at === null
                && ($assignment->valid_from === null || $assignment->valid_from->lte(now()))
                && ($assignment->valid_until === null || $assignment->valid_until->gt(now())))
            ->every(fn (UserAccessAssignment $assignment): bool => $this->authorization->can(
                $actor,
                'manage_access_assignments',
                $assignment->enterpriseScope()
            ));
    }

    private function assertRoleCanBeGranted(
        Request $request,
        Role $role,
        AuthorizationScope $scope
    ): void {
        $actor = $request->user();
        if ($this->superAdmins->hasPlatformSessionAccess($actor)) {
            return;
        }

        $normalise = static fn ($value): string => strtolower(str_replace(
            [' ', '-'],
            '_',
            trim((string) $value)
        ));
        abort_if(
            in_array($normalise($role->name), ['super_admin', 'super_administrator'], true),
            403,
            'Only the platform administrator may grant a Super Admin role.'
        );

        if (! $actor instanceof PosUser) {
            abort(403, 'A POS administrator identity is required.');
        }

        $permissionNames = $role->permissions->pluck('name')->filter()->unique()->values();
        if ($this->authorization->usesScopedAssignments($actor)) {
            $ungrantable = $permissionNames->reject(
                fn (string $permission): bool => $this->authorization->can($actor, $permission, $scope)
            );
        } else {
            $actor->loadMissing('role.permissions');
            $legacyPermissions = $actor->role?->permissions?->pluck('name') ?? collect();
            $ungrantable = $permissionNames->diff($legacyPermissions);
        }

        abort_if(
            $ungrantable->isNotEmpty(),
            403,
            'You cannot grant a role containing permissions you do not hold at this scope.'
        );
    }
}
