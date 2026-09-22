<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\PasswordPolicy;
use Modules\Authentication\Models\UserPasswordHistory;

class CredentialPolicyService
{
    public function resolve(?AccessContext $context = null): PasswordPolicy
    {
        $context ??= AccessContext::global();

        $policies = PasswordPolicy::query()
            ->where('active', true)
            ->where(function ($query) use ($context): void {
                $query->where('scope_type', 'global');
                if ($context->countryId) {
                    $query->orWhere(fn ($scope) => $scope
                        ->where('scope_type', 'country')->where('country_id', $context->countryId));
                }
                if ($context->brandId) {
                    $query->orWhere(fn ($scope) => $scope
                        ->where('scope_type', 'brand')->where('brand_id', $context->brandId));
                }
                if ($context->complexId) {
                    $query->orWhere(fn ($scope) => $scope
                        ->where('scope_type', 'complex')->where('complex_id', $context->complexId));
                }
                if ($context->storeId) {
                    $query->orWhere(fn ($scope) => $scope
                        ->where('scope_type', 'store')->where('store_id', $context->storeId));
                }
            })
            ->get();

        $rank = ['global' => 0, 'country' => 1, 'brand' => 2, 'complex' => 3, 'store' => 4];

        return $policies
            ->sortByDesc(fn (PasswordPolicy $policy) => (($rank[$policy->scope_type] ?? 0) * 10000) + $policy->priority)
            ->first() ?? $this->defaults();
    }

    public function passwordRules(PasswordPolicy $policy): array
    {
        $rules = [Password::min($policy->password_min_length)];
        if ($policy->password_require_uppercase) $rules[] = 'regex:/[A-Z]/';
        if ($policy->password_require_lowercase) $rules[] = 'regex:/[a-z]/';
        if ($policy->password_require_number) $rules[] = 'regex:/[0-9]/';
        if ($policy->password_require_symbol) $rules[] = 'regex:/[^A-Za-z0-9]/';

        return $rules;
    }

    public function assertPasswordNotReused(User $user, string $password, PasswordPolicy $policy): void
    {
        $reused = UserPasswordHistory::query()
            ->where('user_id', $user->id)
            ->where('credential_type', 'password')
            ->latest()
            ->limit($policy->password_history_count)
            ->get()
            ->contains(fn (UserPasswordHistory $history) => Hash::check($password, $history->credential_hash));

        if ($reused || ($user->password && Hash::check($password, $user->password))) {
            throw ValidationException::withMessages(['password' => ['This password was used recently.']]);
        }
    }

    public function assertValidPin(User $user, string $pin, PasswordPolicy $policy): void
    {
        $errors = [];
        if (! ctype_digit($pin) || strlen($pin) !== (int) $policy->pin_length) {
            $errors[] = "The PIN must contain exactly {$policy->pin_length} digits.";
        }
        if ($policy->pin_disallow_repeated && preg_match('/^(\d)\1+$/', $pin)) {
            $errors[] = 'The PIN cannot contain one repeated digit.';
        }
        if ($policy->pin_disallow_sequential && $this->isSequential($pin)) {
            $errors[] = 'The PIN cannot be an ascending or descending sequence.';
        }

        $reused = UserPasswordHistory::query()
            ->where('user_id', $user->id)
            ->where('credential_type', 'pin')
            ->latest()
            ->limit($policy->pin_history_count)
            ->get()
            ->contains(fn (UserPasswordHistory $history) => Hash::check($pin, $history->credential_hash));

        if ($reused || ($user->pin_hash && Hash::check($pin, $user->pin_hash))) {
            $errors[] = 'This PIN was used recently.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['pin' => $errors]);
        }
    }

    public function recordPrevious(User $user, string $type, ?string $hash): void
    {
        if ($hash !== null) {
            UserPasswordHistory::create([
                'user_id' => $user->id,
                'credential_type' => $type,
                'credential_hash' => $hash,
            ]);
        }
    }

    private function isSequential(string $pin): bool
    {
        $ascending = $descending = true;
        for ($index = 1; $index < strlen($pin); $index++) {
            $previous = (int) $pin[$index - 1];
            $current = (int) $pin[$index];
            $ascending = $ascending && $current === (($previous + 1) % 10);
            $descending = $descending && $current === (($previous + 9) % 10);
        }

        return $ascending || $descending;
    }

    private function defaults(): PasswordPolicy
    {
        return new PasswordPolicy([
            'name' => 'Secure default', 'scope_type' => 'global', 'priority' => 0,
            'password_min_length' => 12, 'password_require_uppercase' => true,
            'password_require_lowercase' => true, 'password_require_number' => true,
            'password_require_symbol' => true, 'password_expiry_days' => 90,
            'password_history_count' => 5, 'pin_length' => 6,
            'pin_disallow_sequential' => true, 'pin_disallow_repeated' => true,
            'pin_expiry_days' => 90, 'pin_history_count' => 5,
            'max_failed_attempts' => 5, 'lockout_minutes' => 15,
            'back_office_session_minutes' => 480, 'pos_session_minutes' => 720,
            'require_mfa_for_back_office' => true, 'active' => true,
        ]);
    }
}
