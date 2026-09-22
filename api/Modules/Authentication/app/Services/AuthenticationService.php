<?php

namespace Modules\Authentication\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\AuthenticationEvent;
use Modules\Authentication\Models\AuthSession;
use Modules\Authentication\Models\PasswordPolicy;
use Modules\Terminals\Terminal;

class AuthenticationService
{
    public function assertAvailable(User $user): void
    {
        if (! $user->active || $user->status !== 'active') {
            throw ValidationException::withMessages(['login' => ['This account is not active.']]);
        }

        if ($user->locked_until?->isFuture()) {
            throw ValidationException::withMessages([
                'login' => ['This account is temporarily locked. Try again later.'],
            ]);
        }
    }

    public function recordFailure(?User $user, PasswordPolicy $policy, Request $request, string $identifier, string $channel): void
    {
        if ($user !== null) {
            $attempts = $user->failed_login_attempts + 1;
            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= $policy->max_failed_attempts
                    ? now()->addMinutes($policy->lockout_minutes)
                    : null,
            ])->save();
        }

        $this->event($request, 'login.failed', $channel, $identifier, $user);
    }

    public function recordSuccess(User $user, Request $request, string $identifier, string $channel): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        $this->event($request, 'login.succeeded', $channel, $identifier, $user);
    }

    public function issueSession(
        User $user,
        Request $request,
        string $channel,
        PasswordPolicy $policy,
        ?Store $store = null,
        ?Terminal $terminal = null,
        array $abilities = ['*'],
    ): array {
        $minutes = $channel === 'pos'
            ? $policy->pos_session_minutes
            : $policy->back_office_session_minutes;
        $expiresAt = now()->addMinutes($minutes);
        $deviceName = (string) $request->input('device_name', $channel.' session');
        $token = $user->createToken($deviceName, $abilities, $expiresAt);

        $session = AuthSession::create([
            'user_id' => $user->id,
            'personal_access_token_id' => $token->accessToken->id,
            'store_id' => $store?->id,
            'terminal_id' => $terminal?->id,
            'channel' => $channel,
            'device_name' => $deviceName,
            'device_identifier_hash' => $request->filled('device_identifier')
                ? hash('sha256', (string) $request->input('device_identifier'))
                : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_activity_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'session_id' => $session->id,
        ];
    }

    public function revokeCurrentSession(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token === null) return;

        AuthSession::query()
            ->where('personal_access_token_id', $token->id)
            ->update(['revoked_at' => now()]);
        $token->delete();
    }

    public function credentialsMatch(?User $user, ?string $hash, string $credential): bool
    {
        return $user !== null && $hash !== null && Hash::check($credential, $hash);
    }

    private function event(Request $request, string $event, string $channel, string $identifier, ?User $user): void
    {
        AuthenticationEvent::create([
            'user_id' => $user?->id,
            'event' => $event,
            'channel' => $channel,
            'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
            'store_id' => $request->input('store_id'),
            'terminal_id' => $request->input('terminal_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
