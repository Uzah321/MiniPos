<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Services\AuthPolicyResolver;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Models\PosTill;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PosLoginRateLimiter
{
    public const LOGIN = 'pos-login';

    public const LOGIN_MODE = 'pos-login-mode';

    public function __construct(
        private readonly AuthPolicyResolver $policies,
        private readonly EnterpriseScopeResolver $scopes,
    ) {}

    /**
     * Limit failed credential checks without making every tenant and terminal
     * sharing one public IP consume the same counter.
     *
     * @return array<int, Limit>
     */
    public function login(Request $request): array
    {
        $tenant = $this->tenantFingerprint($request);
        $principal = $this->principalFingerprint($request);
        $tillToken = trim((string) $request->header('X-Till-Token'));
        $source = $tillToken !== ''
            ? 'till:'.$this->fingerprint($tillToken)
            : 'ip:'.$this->fingerprint((string) $request->ip());
        $usesLoginCode = $this->loginCode($request) !== '';
        $lockout = $this->effectiveCredentialLockout($request);

        return [
            $this->failedLoginLimit(
                $lockout['maximum_attempts'],
                "credential:{$tenant}:{$principal}",
                'credential',
                $lockout['decay_seconds'],
            ),
            $this->failedLoginLimit(
                $tillToken !== '' || $usesLoginCode
                    ? (int) config('pos_security.login_rate_limit.terminal_failures_per_minute', 10)
                    : (int) config('pos_security.login_rate_limit.network_failures_per_minute', 30),
                "source:{$tenant}:{$source}",
                $tillToken !== '' ? 'terminal' : 'network'
            ),
        ];
    }

    /**
     * The login-mode lookup is intentionally isolated from login failures.
     * It is read-only, so a higher per-tenant/source ceiling is appropriate.
     *
     * @return array<int, Limit>
     */
    public function loginMode(Request $request): array
    {
        $tenant = $this->tenantFingerprint($request);
        $tillToken = trim((string) $request->header('X-Till-Token'));
        $source = $tillToken !== ''
            ? 'till:'.$this->fingerprint($tillToken)
            : 'ip:'.$this->fingerprint((string) $request->ip());

        return [
            Limit::perMinute((int) config(
                'pos_security.login_rate_limit.mode_requests_per_minute',
                120
            ))
                ->by("mode:{$tenant}:{$source}")
                ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                    $headers,
                    'login-mode',
                    'Too many login setup requests.'
                )),
        ];
    }

    private function failedLoginLimit(
        int $attempts,
        string $key,
        string $scope,
        int $decaySeconds = 60,
    ): Limit
    {
        return Limit::perSecond($attempts, $decaySeconds)
            ->by($key)
            ->after(fn (Response $response): bool => $this->isCredentialFailure($response))
            ->response(fn (Request $request, array $headers) => $this->throttledResponse(
                $headers,
                $scope,
                'Too many failed login attempts.'
            ));
    }

    private function isCredentialFailure(Response $response): bool
    {
        if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED) {
            return false;
        }

        $payload = json_decode((string) $response->getContent(), true);

        // A terminal which still needs activation is a configuration problem,
        // not a bad password/PIN attempt.
        return ! is_array($payload) || ($payload['code'] ?? null) !== 'TILL_TOKEN_REQUIRED';
    }

    /** @return array{maximum_attempts: int, decay_seconds: int} */
    private function effectiveCredentialLockout(Request $request): array
    {
        $fallback = [
            'maximum_attempts' => max(1, (int) config(
                'pos_security.login_rate_limit.credential_failures_per_minute',
                10
            )),
            'decay_seconds' => 60,
        ];

        // Resolve only from trusted middleware bindings. Request headers are
        // deliberately not used to select a policy because the limiter runs
        // on an unauthenticated endpoint.
        if (! app()->bound('company')) {
            return $fallback;
        }

        try {
            if (! Schema::hasTable('auth_policies')) {
                return $fallback;
            }

            $company = app('company');
            if (! $company instanceof Company) {
                return $fallback;
            }

            $scope = trim((string) $request->header('X-Till-Token')) !== ''
                && app()->bound('till')
                && app('till') instanceof PosTill
                ? $this->scopes->fromTill(app('till'))
                : $this->scopes->fromCompany($company);
            $policy = $this->policies->resolve('authentication', $scope);

            // Preserve the established limiter during a rolling migration.
            // Once an administrator explicitly publishes a policy layer, its
            // lockout window becomes authoritative for the credential bucket.
            if ($policy->appliedPolicyIds === []) {
                return $fallback;
            }

            return [
                'maximum_attempts' => max(1, min(
                    100,
                    (int) $policy->get('lockout.maximum_attempts', $fallback['maximum_attempts'])
                )),
                'decay_seconds' => max(30, min(
                    86400,
                    (int) $policy->get('lockout.decay_seconds', $fallback['decay_seconds'])
                )),
            ];
        } catch (Throwable) {
            // Authentication remains available with the established static
            // limiter if a node is between hierarchy/policy migrations.
            return $fallback;
        }
    }

    private function throttledResponse(array $headers, string $scope, string $message)
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

        return response()->json([
            'message' => "{$message} Try again in {$retryAfter} seconds.",
            'code' => 'LOGIN_RATE_LIMITED',
            'retry_after' => $retryAfter,
            'limit_scope' => $scope,
        ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
    }

    private function tenantFingerprint(Request $request): string
    {
        $companyCode = $request->header('X-Company-Code');
        if (! is_scalar($companyCode) || trim((string) $companyCode) === '') {
            $companyCode = $request->input('company_code');
        }

        if (is_scalar($companyCode) && trim((string) $companyCode) !== '') {
            return 'company-code:'.$this->fingerprint(strtoupper(trim((string) $companyCode)));
        }

        $domain = $request->header('X-Company-Domain');
        if (! is_scalar($domain) || trim((string) $domain) === '') {
            $domain = $request->getHost();
        }

        return 'domain:'.$this->fingerprint(strtolower(trim((string) $domain)));
    }

    private function principalFingerprint(Request $request): string
    {
        $loginCode = $this->loginCode($request);
        if ($loginCode !== '') {
            return 'code:'.$this->fingerprint($loginCode);
        }

        $username = strtolower(trim((string) $request->input('username')));

        return 'username:'.$this->fingerprint($username !== '' ? $username : 'missing');
    }

    private function loginCode(Request $request): string
    {
        $code = $request->input(
            'login_code',
            $request->input('pin_code', $request->input('code'))
        );

        // The effective credential policy may allow alphanumeric codes. Keep
        // the limiter identity aligned with the exact credential candidate;
        // stripping letters would collapse AB12 and CD12 into one bucket and
        // let one operator lock out another.
        return trim((string) $code);
    }

    private function fingerprint(string $value): string
    {
        return hash('sha256', $value);
    }
}
