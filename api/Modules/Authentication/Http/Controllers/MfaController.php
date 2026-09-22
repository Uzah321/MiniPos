<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\MfaChallenge;
use Modules\Authentication\Models\UserMfaMethod;
use Modules\Authentication\Services\AuthenticationService;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Authentication\Services\TotpService;

class MfaController extends Controller
{
    public function __construct(
        private TotpService $totp,
        private AuthenticationService $authentication,
        private CredentialPolicyService $policies,
    ) {}

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_identifier' => ['nullable', 'string', 'max:200'],
        ]);

        $challenge = MfaChallenge::query()
            ->where('token_hash', hash('sha256', $data['challenge']))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->with('user.store')
            ->first();

        if (! $challenge || $challenge->attempts >= 5) {
            throw ValidationException::withMessages(['code' => ['The MFA challenge is invalid or expired.']]);
        }
        if ($challenge->ip_address !== null && $challenge->ip_address !== $request->ip()) {
            throw ValidationException::withMessages(['code' => ['The MFA challenge is invalid or expired.']]);
        }

        $challenge->increment('attempts');
        $method = UserMfaMethod::query()
            ->where('user_id', $challenge->user_id)
            ->whereNotNull('confirmed_at')
            ->firstOrFail();

        if (! $this->validCode($method, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['The authentication code is invalid.']]);
        }

        $challenge->update(['used_at' => now()]);
        $method->update(['last_used_at' => now()]);
        $user = $challenge->user;
        $policy = $this->policies->resolve(
            $user->store ? AccessContext::forStore($user->store) : AccessContext::global()
        );

        return response()->json([
            ...$this->authentication->issueSession(
                $user, $request, 'back_office', $policy, $user->store, abilities: ['back-office:*']
            ),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('mfa:setup') || $request->user()->tokenCan('back-office:*'), 403);
        $user = $request->user();
        $secret = $this->totp->generateSecret();
        UserMfaMethod::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'totp'],
            ['secret' => $secret, 'confirmed_at' => null, 'recovery_code_hashes' => null]
        );

        return response()->json([
            'secret' => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, (string) $user->email),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('mfa:setup'), 403);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        $method = UserMfaMethod::query()
            ->where('user_id', $user->id)->where('type', 'totp')->firstOrFail();

        if (! $this->totp->verify($method->secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['The authentication code is invalid.']]);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => strtoupper(bin2hex(random_bytes(5))));
        $method->update([
            'confirmed_at' => now(),
            'recovery_code_hashes' => $recoveryCodes->map(fn (string $code) => Hash::make($code))->all(),
        ]);

        $this->authentication->revokeCurrentSession($user);
        $policy = $this->policies->resolve(
            $user->store ? AccessContext::forStore($user->store) : AccessContext::global()
        );

        return response()->json([
            ...$this->authentication->issueSession(
                $user, $request, 'back_office', $policy, $user->store, abilities: ['back-office:*']
            ),
            'recovery_codes' => $recoveryCodes->all(),
        ]);
    }

    private function validCode(UserMfaMethod $method, string $code): bool
    {
        if ($this->totp->verify($method->secret, $code)) return true;

        foreach ($method->recovery_code_hashes ?? [] as $index => $hash) {
            if (Hash::check(strtoupper($code), $hash)) {
                $codes = $method->recovery_code_hashes;
                unset($codes[$index]);
                $method->update(['recovery_code_hashes' => array_values($codes)]);

                return true;
            }
        }

        return false;
    }
}
