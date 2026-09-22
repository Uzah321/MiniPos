<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\MfaChallenge;
use Modules\Authentication\Services\AuthenticationService;
use Modules\Authentication\Services\CredentialPolicyService;

class BackOfficeAuthController extends Controller
{
    public function __construct(
        private AuthenticationService $authentication,
        private CredentialPolicyService $policies,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_identifier' => ['nullable', 'string', 'max:200'],
        ]);

        $email = mb_strtolower($data['email']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $context = $user?->store ? AccessContext::forStore($user->store) : AccessContext::global();
        $policy = $this->policies->resolve($context);

        if (! $user || ! $user->supportsBackOfficeLogin()
            || ! $this->authentication->credentialsMatch($user, $user->password, $data['password'])) {
            $this->authentication->recordFailure($user, $policy, $request, $email, 'back_office');
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $this->authentication->assertAvailable($user);
        $this->authentication->recordSuccess($user, $request, $email, 'back_office');

        $passwordExpired = $user->must_change_password
            || ($user->password_changed_at !== null && $policy->password_expiry_days > 0
                && $user->password_changed_at->addDays($policy->password_expiry_days)->isPast());
        if ($passwordExpired) {
            return response()->json([
                ...$this->authentication->issueSession(
                    $user, $request, 'back_office', $policy, $user->store, abilities: ['password:update']
                ),
                'password_change_required' => true,
            ], 202);
        }

        if ($policy->require_mfa_for_back_office) {
            $method = $user->mfaMethods()->whereNotNull('confirmed_at')->first();

            if ($method !== null) {
                $plainChallenge = bin2hex(random_bytes(32));
                MfaChallenge::create([
                    'user_id' => $user->id,
                    'token_hash' => hash('sha256', $plainChallenge),
                    'expires_at' => now()->addMinutes(5),
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'mfa_required' => true,
                    'challenge' => $plainChallenge,
                    'expires_in_seconds' => 300,
                ], 202);
            }

            return response()->json([
                ...$this->authentication->issueSession(
                    $user, $request, 'back_office', $policy, $user->store, abilities: ['mfa:setup']
                ),
                'mfa_setup_required' => true,
            ], 202);
        }

        return response()->json([
            ...$this->authentication->issueSession(
                $user, $request, 'back_office', $policy, $user->store, abilities: ['back-office:*']
            ),
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user()->load('roles'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->revokeCurrentSession($request->user());

        return response()->json(['message' => 'Logged out.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_code' => $user->employee_code,
            'authentication_mode' => $user->authentication_mode,
            'default_store_id' => $user->store_id,
            'roles' => $user->getRoleNames(),
        ];
    }
}
