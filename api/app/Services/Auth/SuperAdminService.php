<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Authentication\Models\PosUser;

class SuperAdminService
{
    private const NAMES = ['super_admin', 'super_administrator'];

    public function isSuperAdmin(mixed $user): bool
    {
        if (! $user instanceof PosUser) {
            return false;
        }

        $user->loadMissing('role');

        return in_array($this->normalise($user->user_type), self::NAMES, true)
            || in_array($this->normalise($user->role?->name), self::NAMES, true);
    }

    public function isPlatformAdministrator(mixed $user): bool
    {
        if (config('salexa_sync.deployment_mode') === 'edge'
            || (bool) config('salexa_sync.local_hub', false)) {
            return false;
        }

        if ($user instanceof PosUser) {
            if (! $this->isSuperAdmin($user)) {
                return false;
            }

            $userId = strtolower(trim((string) $user->getKey()));
            if ($userId === '') {
                return false;
            }

            return in_array($userId, $this->platformAdministratorIds(), true);
        }

        if (! $user instanceof User || ! $user instanceof Authenticatable) {
            return false;
        }

        $allowlist = collect(config('license.legacy_platform_admin_emails', []))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter();

        $emailAllowed = $allowlist->contains(strtolower(trim((string) $user->email)));
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenAllowed = $token && method_exists($token, 'can') && $token->can('platform:admin');

        return $emailAllowed && $tokenAllowed;
    }

    /** @return list<string> */
    public function platformAdministratorIds(): array
    {
        if (config('salexa_sync.deployment_mode') === 'edge'
            || (bool) config('salexa_sync.local_hub', false)) {
            return [];
        }

        return collect(config('license.platform_admin_pos_user_ids', []))
            ->map(fn ($id) => strtolower(trim((string) $id)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function tokenAbilitiesForWorkspace(mixed $user, string $workspace): array
    {
        $abilities = ['workspace:'.$workspace];

        if ($workspace === 'back-office' && $this->isPlatformAdministrator($user)) {
            $abilities[] = 'platform:admin';
        }

        return $abilities;
    }

    /** @param  list<string>|null  $issuedAbilities */
    public function hasPlatformSessionAccess(mixed $user, ?array $issuedAbilities = null): bool
    {
        if (! $this->isPlatformAdministrator($user)) {
            return false;
        }

        if ($issuedAbilities !== null) {
            return in_array('platform:admin', $issuedAbilities, true)
                && in_array('workspace:back-office', $issuedAbilities, true);
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token
            && method_exists($token, 'can')
            && $token->can('platform:admin')
            && $token->can('workspace:back-office');
    }

    private function normalise(mixed $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
    }
}
