<?php

namespace Modules\Authentication\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\TransientToken;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\AuthSession;
use Modules\Authentication\Models\PosUser;
use Throwable;

final class AuthSessionService
{
    private const ACTIVITY_TOUCH_SECONDS = 60;

    public function __construct(private readonly AuthPolicyResolver $policies) {}

    /**
     * @return array{channel: string, lifetime_minutes: int, idle_timeout_minutes: int, fingerprint: string, policy_snapshot: array<string, mixed>}
     */
    public function requirements(string $workspace, AuthorizationScope $scope): array
    {
        $channel = $workspace === 'front-office' ? 'pos' : 'back_office';
        $policy = $this->policies->resolve('session', $scope);
        $lifetime = max(5, min(
            10080,
            (int) $policy->get("{$channel}.lifetime_minutes", $channel === 'pos' ? 720 : 480)
        ));
        $idle = max(1, min(
            $lifetime,
            1440,
            (int) $policy->get("{$channel}.idle_timeout_minutes", 30)
        ));

        return [
            'channel' => $channel,
            'lifetime_minutes' => $lifetime,
            'idle_timeout_minutes' => $idle,
            'fingerprint' => $policy->fingerprint,
            'policy_snapshot' => [
                'channel' => $channel,
                'lifetime_minutes' => $lifetime,
                'idle_timeout_minutes' => $idle,
                'applied_policy_ids' => $policy->appliedPolicyIds,
            ],
        ];
    }

    public function issue(
        PosUser $user,
        NewAccessToken $token,
        string $workspace,
        string $authenticationMethod,
        AuthorizationScope $scope,
        Request $request,
    ): ?AuthSession {
        if (! Schema::hasTable('auth_sessions')) {
            return null;
        }

        $requirements = $this->requirements($workspace, $scope);
        $issuedAt = now();
        $deviceIdentifier = trim((string) $request->input('device_name'));

        try {
            return AuthSession::query()->create([
                'pos_user_id' => $user->getKey(),
                'personal_access_token_id' => $token->accessToken->getKey(),
                'channel' => $requirements['channel'],
                'workspace' => $workspace,
                'authentication_method' => $authenticationMethod,
                ...$scope->toArray(),
                'credential_version' => max(1, (int) ($user->credential_version ?: 1)),
                'policy_fingerprint' => $requirements['fingerprint'],
                'policy_snapshot' => $requirements['policy_snapshot'],
                'device_identifier_hash' => $deviceIdentifier === ''
                    ? null
                    : hash('sha256', $deviceIdentifier),
                'ip_address' => substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent' => $request->userAgent(),
                'issued_at' => $issuedAt,
                'last_activity_at' => $issuedAt,
                'expires_at' => $issuedAt->copy()->addMinutes($requirements['lifetime_minutes']),
            ]);
        } catch (Throwable $exception) {
            // Never return an unmanaged bearer token if durable session
            // registration fails after Sanctum issued it.
            $token->accessToken->delete();
            throw $exception;
        }
    }

    /**
     * Return a stable public failure payload, or null when the request may
     * continue. A pre-migration node has no auth_sessions table and retains
     * legacy behaviour until its additive migration runs. Once the table is
     * present, however, a bearer token without a durable session is rejected;
     * deployment therefore safely forces legacy tokens to sign in once.
     *
     * @return array{message: string, code: string}|null
     */
    public function violation(Request $request): ?array
    {
        $user = $request->user();
        $token = $user && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if (! $user instanceof PosUser
            || ! $token instanceof Model
            || $token instanceof TransientToken) {
            return null;
        }

        try {
            // One indexed query is cheaper than an information-schema probe
            // plus the row lookup on every authenticated request.
            $session = AuthSession::query()
                ->where('personal_access_token_id', $token->getKey())
                ->where('pos_user_id', $user->getKey())
                ->first();
        } catch (QueryException $exception) {
            if ($this->isMissingAuthSessionsTable($exception)) {
                return null;
            }

            throw $exception;
        }
        if (! $session) {
            $token->delete();

            return $this->failure(
                'SESSION_NOT_REGISTERED',
                'This session is not registered. Sign in again.'
            );
        }

        if ($session->revoked_at !== null) {
            $token->delete();

            return $this->failure('SESSION_REVOKED', 'This session has been revoked.');
        }

        if (array_key_exists('credential_version', $user->getAttributes())
            && (int) $session->credential_version !== max(1, (int) $user->credential_version)) {
            $this->revokeSession($session, $token, 'credential_version_changed');

            return $this->failure(
                'SESSION_CREDENTIAL_CHANGED',
                'Your credentials changed. Sign in again.'
            );
        }

        $now = now();
        if ($session->expires_at !== null && $session->expires_at->lessThanOrEqualTo($now)) {
            $this->revokeSession($session, $token, 'absolute_lifetime_expired');

            return $this->failure('SESSION_EXPIRED', 'Your session expired. Sign in again.');
        }

        $snapshot = is_array($session->policy_snapshot) ? $session->policy_snapshot : [];
        $idleMinutes = max(1, min(
            1440,
            (int) ($snapshot['idle_timeout_minutes'] ?? 30)
        ));
        $lastActivity = $session->last_activity_at ?? $session->issued_at;
        if ($lastActivity !== null && $lastActivity->copy()->addMinutes($idleMinutes)->lessThanOrEqualTo($now)) {
            $this->revokeSession($session, $token, 'idle_timeout_expired');

            return $this->failure(
                'SESSION_IDLE_TIMEOUT',
                'Your session timed out because it was inactive. Sign in again.'
            );
        }

        if ($lastActivity === null
            || $lastActivity->copy()->addSeconds(self::ACTIVITY_TOUCH_SECONDS)->lessThanOrEqualTo($now)) {
            $session->forceFill(['last_activity_at' => $now])->saveQuietly();
        }

        return null;
    }

    public function revokeCurrent(PosUser $user, mixed $token, string $reason = 'logout'): void
    {
        if ($token instanceof Model && Schema::hasTable('auth_sessions')) {
            AuthSession::query()
                ->where('pos_user_id', $user->getKey())
                ->where('personal_access_token_id', $token->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revocation_reason' => substr($reason, 0, 120),
                    'updated_at' => now(),
                ]);
        }

        if ($token instanceof Model) {
            $token->delete();
        }
    }

    public function revokeAllForUser(PosUser $user, string $reason): void
    {
        if (Schema::hasTable('auth_sessions')) {
            AuthSession::query()
                ->where('pos_user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revocation_reason' => substr($reason, 0, 120),
                    'updated_at' => now(),
                ]);
        }

        $user->tokens()->delete();
    }

    /** @return array{message: string, code: string} */
    private function failure(string $code, string $message): array
    {
        return ['message' => $message, 'code' => $code];
    }

    private function revokeSession(AuthSession $session, Model $token, string $reason): void
    {
        $session->forceFill([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->saveQuietly();
        $token->delete();
    }

    private function isMissingAuthSessionsTable(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '42P01'
            || $driverCode === 1146
            || (str_contains($message, 'no such table') && str_contains($message, 'auth_sessions'));
    }
}
