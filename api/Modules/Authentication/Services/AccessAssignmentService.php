<?php

namespace Modules\Authentication\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\RolesAndPermission\Models\Role;

final class AccessAssignmentService
{
    public function __construct(
        private readonly EnterpriseScopeResolver $scopes,
        private readonly ?AuthSessionService $sessions = null,
    ) {}

    public function grant(
        PosUser $user,
        Role $role,
        EnterpriseScope $scopeType,
        ?string $targetId,
        ?PosUser $grantedBy = null,
        ?string $reason = null,
        ?CarbonInterface $validFrom = null,
        ?CarbonInterface $validUntil = null,
    ): UserAccessAssignment {
        if ($validFrom !== null && $validUntil !== null && $validUntil->lessThanOrEqualTo($validFrom)) {
            throw ValidationException::withMessages([
                'valid_until' => ['The assignment end must be later than its start.'],
            ]);
        }

        $scope = $this->scopes->resolve($scopeType, $targetId);
        $roleCompanyId = trim((string) $role->company_id);
        if ($roleCompanyId !== '' && $roleCompanyId !== (string) $scope->companyId) {
            throw ValidationException::withMessages([
                'role_id' => ['The role does not belong to the assignment company.'],
            ]);
        }
        if ($scopeType === EnterpriseScope::Global && $roleCompanyId !== '') {
            throw ValidationException::withMessages([
                'role_id' => ['A company-owned role cannot be assigned globally.'],
            ]);
        }

        $columns = $scope->databaseColumns($scopeType);

        $assignment = DB::transaction(function () use (
            $user,
            $role,
            $columns,
            $grantedBy,
            $reason,
            $validFrom,
            $validUntil
        ): UserAccessAssignment {
            $assignment = UserAccessAssignment::query()
                ->where('pos_user_id', $user->getKey())
                ->where('role_id', $role->getKey())
                ->where('scope_key', $columns['scope_key'])
                ->lockForUpdate()
                ->first() ?? new UserAccessAssignment;

            $assignment->forceFill([
                'pos_user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                ...$columns,
                'status' => UserAccessAssignment::STATUS_ACTIVE,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'granted_by' => $grantedBy?->getKey(),
                'grant_reason' => $reason,
                'revoked_by' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
            ])->save();

            return $assignment->refresh();
        });

        $this->sessionService()->revokeAllForUser($user, 'access_assignment_changed');

        return $assignment;
    }

    public function revoke(
        UserAccessAssignment $assignment,
        ?PosUser $revokedBy = null,
        ?string $reason = null,
    ): UserAccessAssignment {
        $assignment->forceFill([
            'status' => UserAccessAssignment::STATUS_REVOKED,
            'revoked_by' => $revokedBy?->getKey(),
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $assignment = $assignment->refresh();
        $user = $assignment->user;
        if ($user instanceof PosUser) {
            $this->sessionService()->revokeAllForUser($user, 'access_assignment_revoked');
        }

        return $assignment;
    }

    private function sessionService(): AuthSessionService
    {
        return $this->sessions ?? app(AuthSessionService::class);
    }
}
