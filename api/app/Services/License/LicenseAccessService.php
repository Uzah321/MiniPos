<?php

namespace App\Services\License;

use App\Services\Auth\SuperAdminService;
use Carbon\Carbon;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Company\Models\License;

class LicenseAccessService
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly SuperAdminService $superAdmins,
    ) {
    }

    public function status(Company $company, mixed $user = null): array
    {
        return $this->statusForLicense($company, $this->currentLicense($company), $user);
    }

    public function statusForLicense(Company $company, ?License $license, mixed $user = null): array
    {
        // A company Super Admin is a tenant permission administrator, not the
        // owner of the ServeNest platform. License bypass is reserved for the
        // immutable, deployment-level UUID allowlist checked by
        // isPlatformAdministrator(). This also works during login before a new
        // access token exists; workspace abilities continue to constrain what
        // the subsequently issued token may call.
        $platformAdministrator = $this->superAdmins->isPlatformAdministrator($user);
        $actual = $this->actualStatus($company, $license, $platformAdministrator);

        if ($platformAdministrator) {
            if ($actual['valid']) {
                // A valid entitlement needs no bypass. Keeping the underlying
                // status intact also preserves expiry warnings for the owner.
                return $actual;
            }

            return [
                ...$actual,
                'valid' => true,
                'bypass' => true,
                'underlying_code' => $actual['code'],
                'code' => 'LICENSE_BYPASSED',
                'message' => 'System owner license bypass is active.',
                'warning_required' => false,
                'warning' => false,
            ];
        }

        return $actual;
    }

    public function enforce(Company $company, mixed $user = null): array
    {
        $status = $this->status($company, $user);

        // A platform-owner request must not postpone eviction of tenant users.
        // `bypass` is true only when the underlying company license is invalid.
        if (!$status['valid'] || ($status['bypass'] ?? false)) {
            $this->revokeNonPlatformAdministratorTokens($company);
        }

        return $status;
    }

    public function revokeNonPlatformAdministratorTokens(Company $company): int
    {
        $revoked = 0;

        PosUser::with('role')
            ->where('company_id', $company->id)
            ->get()
            ->each(function (PosUser $user) use (&$revoked) {
                if ($this->superAdmins->isPlatformAdministrator($user)) {
                    return;
                }

                $revoked += $user->tokens()->delete();
            });

        return $revoked;
    }

    public function responsePayload(array $status): array
    {
        return [
            'message' => $status['message'],
            'code' => $status['code'],
            'force_logout' => !$status['valid'] && !$status['bypass'],
            'server_date' => now()->toDateString(),
            'license' => $status,
        ];
    }

    private function actualStatus(Company $company, ?License $license, bool $platformAdministrator): array
    {
        $supportPhone = $company->license_support_phone ?: config('license.default_support_phone');
        $warningDays = max((int) config('license.warning_days', 90), 0);
        $base = [
            'valid' => false,
            'bypass' => false,
            'status' => $license?->status ?? 'missing',
            'license_id' => $license?->id,
            'license_key' => $platformAdministrator ? $license?->license_key : null,
            'license_type' => $license?->license_type,
            'starts_at' => $license?->starts_at?->toDateString(),
            'expires_at' => $license?->expires_at?->toDateString(),
            'days_remaining' => $this->daysRemaining($license),
            'warning_required' => false,
            'warning' => false,
            'warning_threshold_days' => $warningDays,
            'warning_days' => $warningDays,
            'license_support_phone' => $supportPhone,
            'server_date' => now()->toDateString(),
        ];

        if (strtolower((string) $company->status) !== 'active') {
            return [...$base, 'code' => 'COMPANY_INACTIVE', 'message' => $this->message(
                'This company is inactive. Contact the system administrator.',
                $supportPhone
            )];
        }

        if (!$license) {
            return [...$base, 'code' => 'LICENSE_MISSING', 'message' => $this->message(
                'No license is assigned to this company.',
                $supportPhone
            )];
        }

        $code = $this->licenses->validationCode($license);
        $base['status'] = $license->fresh()->status;

        if ($code !== 'LICENSE_VALID') {
            return [
                ...$base,
                'code' => $code,
                'message' => $this->message($this->messageForCode($code), $supportPhone),
            ];
        }

        $warning = $base['days_remaining'] !== null
            && $base['days_remaining'] >= 0
            && $base['days_remaining'] <= $warningDays;

        return [
            ...$base,
            'valid' => true,
            'status' => 'active',
            'code' => $warning ? 'LICENSE_EXPIRING' : 'LICENSE_VALID',
            'message' => $warning
                ? $this->message('The company license is approaching expiry.', $supportPhone)
                : 'Company license is valid.',
            'warning_required' => $warning,
            'warning' => $warning,
        ];
    }

    public function currentLicense(Company $company): ?License
    {
        return $company->licenses()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->first();
    }

    private function daysRemaining(?License $license): ?int
    {
        if (!$license?->expires_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(
            Carbon::parse($license->expires_at)->startOfDay(),
            false
        );
    }

    private function messageForCode(string $code): string
    {
        return match ($code) {
            'LICENSE_EXPIRED' => 'The company license has expired.',
            'LICENSE_REVOKED' => 'The company license has been revoked.',
            'LICENSE_NOT_STARTED' => 'The company license is not active yet.',
            'LICENSE_TAMPERED' => 'The company license could not be verified.',
            default => 'The company license is not active.',
        };
    }

    private function message(string $message, ?string $phone): string
    {
        return $phone ? rtrim($message) . ' Contact admin: ' . $phone : $message;
    }
}
