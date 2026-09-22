<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\AuthSession;
use Modules\Authentication\Services\CredentialPolicyService;

class CredentialController extends Controller
{
    public function __construct(private CredentialPolicyService $policies) {}

    public function password(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('password:update') || $request->user()->tokenCan('back-office:*'), 403);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ]);
        $user = $request->user();
        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $policy = $this->policies->resolve($user->store ? AccessContext::forStore($user->store) : null);
        Validator::make($data, ['password' => $this->policies->passwordRules($policy)])->validate();
        $this->policies->assertPasswordNotReused($user, $data['password'], $policy);
        $this->policies->recordPrevious($user, 'password', $user->password);
        $user->update(['password' => Hash::make($data['password']), 'password_changed_at' => now(), 'must_change_password' => false]);
        AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated. Sign in again to continue.']);
    }

    public function pin(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('pin:update') || $request->user()->tokenCan('pos:*'), 403);
        $data = $request->validate(['current_pin' => ['required', 'string'], 'pin' => ['required', 'string', 'confirmed']]);
        $user = $request->user();
        if (! $user->pin_hash || ! Hash::check($data['current_pin'], $user->pin_hash)) {
            throw ValidationException::withMessages(['current_pin' => ['The current PIN is incorrect.']]);
        }
        $policy = $this->policies->resolve($user->store ? AccessContext::forStore($user->store) : null);
        $this->policies->assertValidPin($user, $data['pin'], $policy);
        $this->policies->recordPrevious($user, 'pin', $user->pin_hash);
        $user->update(['pin_hash' => Hash::make($data['pin']), 'pin_changed_at' => now(), 'must_change_pin' => false]);
        AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->tokens()->delete();

        return response()->json(['message' => 'PIN updated. Sign in again to continue.']);
    }
}
