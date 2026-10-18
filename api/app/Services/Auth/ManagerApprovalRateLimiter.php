<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ManagerApprovalRateLimiter
{
    public const VERIFY = 'pos-manager-approval';

    /**
     * Apply overlapping failure budgets so rotating a manager username, actor
     * account, or source address cannot bypass every limit. Only fingerprints
     * are stored in limiter keys; credentials are never included.
     *
     * @return array<int, Limit>
     */
    public function verify(Request $request): array
    {
        $company = $this->companyFingerprint($request);
        $actor = $this->actorFingerprint($request);
        $manager = $this->fingerprint(strtolower(trim((string) $request->input('username'))));
        $source = $this->fingerprint((string) $request->ip());

        return [
            $this->failedAttemptLimit(
                (int) config('pos_security.manager_approval_rate_limit.manager_failures_per_minute', 5),
                'manager:'.$this->fingerprint($company."\0".$manager),
                'manager'
            ),
            $this->failedAttemptLimit(
                (int) config('pos_security.manager_approval_rate_limit.actor_failures_per_minute', 10),
                'actor:'.$this->fingerprint($company."\0".$actor),
                'actor'
            ),
            $this->failedAttemptLimit(
                (int) config('pos_security.manager_approval_rate_limit.network_failures_per_minute', 30),
                'network:'.$this->fingerprint($company."\0".$source),
                'network'
            ),
        ];
    }

    private function failedAttemptLimit(int $attempts, string $key, string $scope): Limit
    {
        return Limit::perMinute(max(1, $attempts))
            ->by($key)
            ->after(fn (Response $response): bool => $this->isInvalidCredentialResponse($response))
            ->response(fn (Request $request, array $headers) => $this->throttledResponse($headers, $scope));
    }

    private function isInvalidCredentialResponse(Response $response): bool
    {
        if ($response->getStatusCode() !== Response::HTTP_UNPROCESSABLE_ENTITY) {
            return false;
        }

        $payload = json_decode((string) $response->getContent(), true);

        return is_array($payload)
            && ($payload['code'] ?? null) === 'MANAGER_APPROVAL_INVALID_CREDENTIALS';
    }

    private function throttledResponse(array $headers, string $scope)
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

        return response()->json([
            'message' => "Too many failed manager approval attempts. Try again in {$retryAfter} seconds.",
            'code' => 'MANAGER_APPROVAL_RATE_LIMITED',
            'retry_after' => $retryAfter,
            'limit_scope' => $scope,
        ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
    }

    private function companyFingerprint(Request $request): string
    {
        if (app()->bound('company') && app('company')?->id) {
            return $this->fingerprint((string) app('company')->id);
        }

        $companyCode = $request->header('X-Company-Code');
        if (is_scalar($companyCode) && trim((string) $companyCode) !== '') {
            return $this->fingerprint(strtoupper(trim((string) $companyCode)));
        }

        $companyDomain = $request->header('X-Company-Domain');
        if (! is_scalar($companyDomain) || trim((string) $companyDomain) === '') {
            $companyDomain = $request->getHost();
        }

        return $this->fingerprint(strtolower(trim((string) $companyDomain)));
    }

    private function actorFingerprint(Request $request): string
    {
        $actor = $request->user();
        $identifier = $actor?->getAuthIdentifier();

        return $this->fingerprint((string) ($identifier ?: 'unauthenticated'));
    }

    private function fingerprint(string $value): string
    {
        return hash('sha256', $value !== '' ? $value : 'missing');
    }
}
