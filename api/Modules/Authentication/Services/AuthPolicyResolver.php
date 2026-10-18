<?php

namespace Modules\Authentication\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Data\EffectiveAuthPolicy;
use Modules\Authentication\Models\AuthPolicy;
use Illuminate\Support\Facades\Schema;

final class AuthPolicyResolver
{
    public function __construct(private readonly AuthPolicyMerger $merger) {}

    public function resolve(
        string $policyKey,
        AuthorizationScope $scope,
        ?CarbonInterface $at = null,
        bool $forUpdate = false,
        array $defaultOverrides = [],
    ): EffectiveAuthPolicy {
        $policyKey = trim($policyKey);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $policyKey) !== 1) {
            throw new InvalidArgumentException('The authentication policy key is invalid.');
        }

        $at ??= now();
        $defaults = config("authentication.security.policy_defaults.{$policyKey}", []);
        if (! is_array($defaults)) {
            $defaults = [];
        }
        $defaults = array_replace_recursive($defaults, $defaultOverrides);

        $query = AuthPolicy::query()
            ->where('policy_key', $policyKey)
            ->where('is_active', true)
            ->whereIn('scope_key', $scope->lineageKeys())
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
            });

        // Older edge installations can receive the application code before
        // the additive auth migration. Defaults keep those nodes fail-closed
        // until their next configuration migration/snapshot completes.
        $layers = Schema::hasTable('auth_policies')
            ? ($forUpdate ? $query->lockForUpdate() : $query)->get()
            : collect();

        return $this->merger->merge($policyKey, $defaults, $layers);
    }
}
