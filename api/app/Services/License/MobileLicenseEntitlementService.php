<?php

namespace App\Services\License;

use Carbon\Carbon;
use Modules\Company\Models\Company;
use Modules\Company\Models\License;
use RuntimeException;

final class MobileLicenseEntitlementService
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly LicenseService $licenses,
        private readonly LicenseAccessService $access,
        private readonly LicenseSignatureService $signatures
    ) {}

    /**
     * Build the company-scoped, offline-verifiable entitlement delivered to an
     * Android terminal. The human activation key and every signing key are
     * deliberately absent from both the signed payload and its envelope.
     *
     * Expired and revoked entitlements remain exportable so an installation can
     * be paired, kept blocked locally, and later receive a signed renewal.
     * Missing, tampered, or non-signable entitlements fail closed.
     *
     * @return array{license_entitlement: array<string, mixed>, license: array<string, mixed>, server_date: string}
     */
    public function issue(Company $company): array
    {
        $license = $this->access->currentLicense($company);
        if (! $license) {
            throw new MobileLicenseEntitlementException(
                'No signed company license is available for this terminal.',
                'LICENSE_MISSING',
                409
            );
        }

        if (! $this->licenses->verifyEntitlement($license)) {
            throw new MobileLicenseEntitlementException(
                'The stored company license entitlement failed verification.',
                'LICENSE_TAMPERED',
                409
            );
        }

        // A verified v1 entitlement may be upgraded by the authoritative
        // issuer. Any existing v2 material was verified above and is never
        // silently re-signed here.
        if ((int) $license->entitlement_version !== 2) {
            if (! $this->signatures->canSign()) {
                throw $this->signingUnavailable();
            }

            try {
                $license = $this->licenses->signEntitlement($license);
            } catch (RuntimeException) {
                throw $this->signingUnavailable();
            }

            if (! $this->licenses->verifyEntitlement($license)) {
                throw new MobileLicenseEntitlementException(
                    'The upgraded company license entitlement failed verification.',
                    'LICENSE_TAMPERED',
                    409
                );
            }
        }

        if (! $this->signatures->canSign()) {
            throw $this->signingUnavailable();
        }

        // Rotate the authoritative source entitlement first when the issuer's
        // active identity changes. signEntitlement increments its persisted
        // revision, preventing a new mobile payload/signature from appearing
        // under an already-seen revision.
        if (! hash_equals((string) $license->signature_key_id, $this->signatures->activeKeyId())
            || ! hash_equals(
                strtolower((string) $license->signature_algorithm),
                strtolower($this->signatures->algorithm())
            )) {
            try {
                $license = $this->licenses->signEntitlement($license);
            } catch (RuntimeException) {
                throw $this->signingUnavailable();
            }
        }

        $this->assertExportable($license);

        $status = $this->access->statusForLicense($company, $license);
        $payload = base64_encode($this->canonicalJson($this->payload($license)));

        try {
            $signed = $this->signatures->sign($payload);
        } catch (RuntimeException) {
            throw $this->signingUnavailable();
        }
        if (! hash_equals((string) $this->signatures->algorithm(), (string) $signed['algorithm'])
            || ! hash_equals((string) $this->signatures->activeKeyId(), (string) $signed['key_id'])) {
            throw $this->signingUnavailable();
        }
        if (! $this->signatures->verify(
            $payload,
            (string) $signed['signature'],
            (string) $signed['algorithm'],
            (string) $signed['key_id']
        )) {
            throw $this->signingUnavailable();
        }

        return [
            'license_entitlement' => [
                'payload' => $payload,
                'signature' => $signed['signature'],
                'algorithm' => $signed['algorithm'],
                'key_id' => $signed['key_id'],
                'revision' => max(1, (int) $license->entitlement_revision),
            ],
            'license' => $this->runtimeStatus($status),
            'server_date' => now()->toDateString(),
        ];
    }

    /** @param array<string, mixed> $entitlement */
    public function assertClientCompatibility(
        array $entitlement,
        int $contractVersion,
        array $supportedKeyIds,
        array $supportedAlgorithms,
        array $supportedKeyFingerprints
    ): void {
        $keyId = (string) ($entitlement['key_id'] ?? '');
        $algorithm = strtolower((string) ($entitlement['algorithm'] ?? ''));
        $keyIds = array_map(static fn (mixed $value): string => trim((string) $value), $supportedKeyIds);
        $algorithms = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $supportedAlgorithms
        );
        $clientFingerprint = strtolower(trim((string) ($supportedKeyFingerprints[$keyId] ?? '')));
        $serverFingerprint = $keyId !== '' && $algorithm !== ''
            ? $this->signatures->publicKeyFingerprint($keyId, $algorithm)
            : null;

        if ($contractVersion !== self::CONTRACT_VERSION
            || $keyId === ''
            || ! in_array($keyId, $keyIds, true)
            || $algorithm === ''
            || ! in_array($algorithm, $algorithms, true)
            || ! is_string($serverFingerprint)
            || ! preg_match('/^[a-f0-9]{64}$/', $clientFingerprint)
            || ! hash_equals($serverFingerprint, $clientFingerprint)) {
            throw new MobileLicenseEntitlementException(
                'This application cannot verify the company license signing identity. Update the application before pairing.',
                'MOBILE_LICENSE_CONTRACT_UNSUPPORTED',
                409
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(License $license): array
    {
        $revokedAt = $license->revoked_at
            ? Carbon::parse($license->revoked_at)->format('Y-m-d H:i:s')
            : null;
        $issuedAt = Carbon::parse($license->signed_at)->format('Y-m-d H:i:s');

        return [
            'authorization_state' => $revokedAt || $license->status === 'revoked' ? 'revoked' : 'issued',
            'company_id' => (string) $license->company_id,
            'entitlement_revision' => max(1, (int) $license->entitlement_revision),
            'expires_at' => $license->expires_at ? Carbon::parse($license->expires_at)->toDateString() : null,
            'issued_at' => $issuedAt,
            'license_id' => (string) $license->id,
            'license_type' => (string) $license->license_type,
            'max_branches' => (int) $license->max_branches,
            'max_devices' => (int) $license->max_devices,
            'max_users' => (int) $license->max_users,
            'purpose' => 'mobile_license_entitlement',
            'revoked_at' => $revokedAt,
            'signature_algorithm' => (string) $license->signature_algorithm,
            'signature_key_id' => (string) $license->signature_key_id,
            'starts_at' => $license->starts_at ? Carbon::parse($license->starts_at)->toDateString() : null,
            'version' => self::CONTRACT_VERSION,
        ];
    }

    private function assertExportable(License $license): void
    {
        if (trim((string) $license->id) === ''
            || trim((string) $license->company_id) === ''
            || trim((string) $license->license_type) === ''
            || ! $license->starts_at
            || ! $license->expires_at
            || ! $license->signed_at
            || (int) $license->entitlement_revision < 1
            || ($license->status === 'revoked' && ! $license->revoked_at)) {
            throw $this->signingUnavailable();
        }

        try {
            $startsAt = Carbon::parse($license->starts_at)->startOfDay();
            $expiresAt = Carbon::parse($license->expires_at)->startOfDay();
        } catch (\Throwable) {
            throw $this->signingUnavailable();
        }
        if ($expiresAt->lt($startsAt)) {
            throw $this->signingUnavailable();
        }
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    private function runtimeStatus(array $status): array
    {
        return [
            'valid' => (bool) ($status['valid'] ?? false),
            'code' => (string) ($status['code'] ?? 'LICENSE_STATUS_UNKNOWN'),
            'license_id' => isset($status['license_id']) ? (string) $status['license_id'] : null,
            'expires_at' => isset($status['expires_at']) ? (string) $status['expires_at'] : null,
            'days_remaining' => isset($status['days_remaining']) ? (int) $status['days_remaining'] : null,
            'warning_days' => max((int) ($status['warning_days'] ?? 90), 0),
            'warning_required' => (bool) ($status['warning_required'] ?? false),
            'license_support_phone' => isset($status['license_support_phone'])
                ? trim((string) $status['license_support_phone'])
                : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function signingUnavailable(): MobileLicenseEntitlementException
    {
        return new MobileLicenseEntitlementException(
            'The cloud license signer is unavailable. Pairing was not consumed; contact the system administrator.',
            'LICENSE_ENTITLEMENT_UNAVAILABLE',
            503
        );
    }
}
