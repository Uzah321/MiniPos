<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Data\EffectiveAuthPolicy;
use Modules\Authentication\Models\CredentialHistory;
use Modules\Authentication\Models\PosUser;

final class CredentialPolicyService
{
    public function __construct(private readonly AuthPolicyResolver $policies) {}

    public function effectivePolicy(AuthorizationScope $scope): EffectiveAuthPolicy
    {
        return $this->policies->resolve('credential', $scope);
    }

    /** @return list<string> */
    public function passwordViolations(string $password, AuthorizationScope $scope): array
    {
        $policy = $this->effectivePolicy($scope);
        $minimum = max(6, (int) $policy->get('password.minimum_length', 6));
        $maximum = min(1024, max($minimum, (int) $policy->get('password.maximum_length', 128)));
        $length = mb_strlen($password);
        $violations = [];

        if ($length < $minimum) {
            $violations[] = "The password must contain at least {$minimum} characters.";
        }
        if ($length > $maximum) {
            $violations[] = "The password may not contain more than {$maximum} characters.";
        }
        if ($policy->get('password.require_uppercase', true) && preg_match('/[A-Z]/u', $password) !== 1) {
            $violations[] = 'The password must contain an uppercase letter.';
        }
        if ($policy->get('password.require_lowercase', true) && preg_match('/[a-z]/u', $password) !== 1) {
            $violations[] = 'The password must contain a lowercase letter.';
        }
        if ($policy->get('password.require_number', true) && preg_match('/\d/u', $password) !== 1) {
            $violations[] = 'The password must contain a number.';
        }
        if ($policy->get('password.require_symbol', true)
            && preg_match('/[^\pL\pN]/u', $password) !== 1) {
            $violations[] = 'The password must contain a symbol.';
        }

        return $violations;
    }

    /** @return list<string> */
    public function loginCodeViolations(string $loginCode, AuthorizationScope $scope): array
    {
        $policy = $this->effectivePolicy($scope);
        $violations = $this->loginCodeFormatViolations($loginCode, $scope, $policy);
        if ($policy->get('login_code.reject_repeated_digits', true)
            && preg_match('/^(.)\1+$/D', $loginCode) === 1) {
            $violations[] = 'The login code may not repeat one digit throughout.';
        }
        if ($policy->get('login_code.reject_sequences', true) && $this->isSequence($loginCode)) {
            $violations[] = 'The login code may not be a simple ascending or descending sequence.';
        }

        return array_values(array_unique($violations));
    }

    /**
     * Public, non-secret constraints a POS client needs before login.
     *
     * @return array{minimum_length: int, maximum_length: int, numeric_only: bool, reject_repeated_digits: bool, reject_sequences: bool}
     */
    public function loginCodeRequirements(AuthorizationScope $scope): array
    {
        return $this->loginCodeRequirementsFromPolicy($this->effectivePolicy($scope));
    }

    /** @return list<string> */
    public function loginCodeFormatViolations(
        string $loginCode,
        AuthorizationScope $scope,
        ?EffectiveAuthPolicy $policy = null,
    ): array {
        $requirements = $this->loginCodeRequirementsFromPolicy(
            $policy ?? $this->effectivePolicy($scope)
        );
        $length = strlen($loginCode);
        $violations = [];

        if ($requirements['numeric_only'] && preg_match('/^\d+$/D', $loginCode) !== 1) {
            $violations[] = 'The login code must contain digits only.';
        }
        if ($length < $requirements['minimum_length'] || $length > $requirements['maximum_length']) {
            $violations[] = "The login code must contain between {$requirements['minimum_length']} and {$requirements['maximum_length']} digits.";
        }

        return $violations;
    }

    public function assertPassword(string $password, AuthorizationScope $scope): void
    {
        $violations = $this->passwordViolations($password, $scope);
        if ($violations !== []) {
            throw ValidationException::withMessages(['password' => $violations]);
        }
    }

    public function assertLoginCode(string $loginCode, AuthorizationScope $scope): void
    {
        $violations = $this->loginCodeViolations($loginCode, $scope);
        if ($violations !== []) {
            throw ValidationException::withMessages(['login_code' => $violations]);
        }
    }

    public function passwordWasRecentlyUsed(
        PosUser $user,
        string $plainPassword,
        AuthorizationScope $scope
    ): bool {
        $historyCount = max(0, min(24, (int) $this->effectivePolicy($scope)
            ->get('password.history_count', 5)));

        $hashes = collect([(string) $user->password])
            ->merge(CredentialHistory::query()
                ->where('pos_user_id', $user->getKey())
                ->where('credential_type', 'password')
                ->latest('credential_version')
                ->limit($historyCount)
                ->pluck('credential_hash'))
            ->filter();

        return $hashes->contains(fn (string $hash): bool => Hash::check($plainPassword, $hash));
    }

    private function isSequence(string $loginCode): bool
    {
        if (strlen($loginCode) < 3 || preg_match('/^\d+$/D', $loginCode) !== 1) {
            return false;
        }

        return str_contains('01234567890123456789', $loginCode)
            || str_contains('98765432109876543210', $loginCode);
    }

    /**
     * @return array{minimum_length: int, maximum_length: int, numeric_only: bool, reject_repeated_digits: bool, reject_sequences: bool}
     */
    private function loginCodeRequirementsFromPolicy(EffectiveAuthPolicy $policy): array
    {
        $minimum = max(4, (int) $policy->get('login_code.minimum_length', 4));
        $maximum = min(12, max($minimum, (int) $policy->get('login_code.maximum_length', 8)));

        return [
            'minimum_length' => $minimum,
            'maximum_length' => $maximum,
            'numeric_only' => (bool) $policy->get('login_code.numeric_only', true),
            'reject_repeated_digits' => (bool) $policy->get('login_code.reject_repeated_digits', true),
            'reject_sequences' => (bool) $policy->get('login_code.reject_sequences', true),
        ];
    }
}
