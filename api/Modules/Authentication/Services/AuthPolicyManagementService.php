<?php

namespace Modules\Authentication\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Models\AuthPolicy;
use Modules\Authentication\Models\PosUser;

final class AuthPolicyManagementService
{
    public function __construct(
        private readonly EnterpriseScopeResolver $scopes,
        private readonly AuthPolicySettingsValidator $settingsValidator,
    ) {}

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function validateSettings(string $policyKey, array $settings): array
    {
        return $this->settingsValidator->validate($policyKey, $settings);
    }

    /** @param array<string, mixed> $settings */
    public function publish(
        string $policyKey,
        array $settings,
        EnterpriseScope $scopeType,
        ?string $targetId,
        ?PosUser $actor = null,
        int $priority = 0,
        ?CarbonInterface $effectiveFrom = null,
        ?CarbonInterface $effectiveUntil = null,
    ): AuthPolicy {
        $policyKey = trim($policyKey);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $policyKey) !== 1) {
            throw new InvalidArgumentException('The authentication policy key is invalid.');
        }
        $settings = $this->validateSettings($policyKey, $settings);
        if ($effectiveFrom !== null
            && $effectiveUntil !== null
            && $effectiveUntil->lessThanOrEqualTo($effectiveFrom)) {
            throw new InvalidArgumentException('The policy end must be later than its start.');
        }

        $scope = $this->scopes->resolve($scopeType, $targetId);
        $columns = $scope->databaseColumns($scopeType);

        return DB::transaction(function () use (
            $policyKey,
            $settings,
            $columns,
            $priority,
            $effectiveFrom,
            $effectiveUntil,
            $actor
        ): AuthPolicy {
            $latestVersion = (int) AuthPolicy::query()
                ->where('policy_key', $policyKey)
                ->where('scope_key', $columns['scope_key'])
                ->lockForUpdate()
                ->max('version');

            return AuthPolicy::query()->create([
                'policy_key' => $policyKey,
                ...$columns,
                'priority' => max(0, $priority),
                'version' => $latestVersion + 1,
                'settings' => $settings,
                'is_active' => true,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ]);
        });
    }

    public function retire(AuthPolicy $policy, ?PosUser $actor = null): AuthPolicy
    {
        $policy->forceFill([
            'is_active' => false,
            'updated_by' => $actor?->getKey(),
        ])->save();

        return $policy->refresh();
    }
}
