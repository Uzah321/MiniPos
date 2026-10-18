<?php

namespace App\Services\License;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Company\Models\Company;
use Modules\Company\Models\License;
use RuntimeException;

class LicenseService
{
    public const KEY_PATTERN = '/^[ABCDEFGHJKMNPQRSTVWXYZ23456789]{4}(?:-[ABCDEFGHJKMNPQRSTVWXYZ23456789]{4}){3}$/';

    private const KEY_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    public function generateLicenseKey(?Company $company = null, string $licenseType = 'enterprise'): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $raw = '';
            $lastIndex = strlen(self::KEY_ALPHABET) - 1;

            for ($index = 0; $index < 16; $index++) {
                $raw .= self::KEY_ALPHABET[random_int(0, $lastIndex)];
            }

            $key = implode('-', str_split($raw, 4));

            if (! $this->licenseKeyExists($key)) {
                return $key;
            }
        }

        throw new RuntimeException('Unable to generate a unique license key.');
    }

    public function normalizeLicenseKey(string $key): string
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($key)) ?? '');
        $formatted = implode('-', str_split($raw, 4));

        if (strlen($raw) !== 16 || ! preg_match(self::KEY_PATTERN, $formatted)) {
            throw new InvalidArgumentException('The license key must use the format XXXX-XXXX-XXXX-XXXX.');
        }

        return $formatted;
    }

    public function createLicense(Company $company, array $data): License
    {
        $licenseType = $data['license_type'] ?? 'enterprise';
        $key = ! empty($data['license_key'])
            ? $this->normalizeLicenseKey((string) $data['license_key'])
            : $this->generateLicenseKey($company, $licenseType);
        $startsAt = Carbon::parse($data['starts_at'] ?? now())->startOfDay();
        $expiresAt = Carbon::parse($data['expires_at'] ?? now()->addYear())->startOfDay();

        if ($expiresAt->lt($startsAt)) {
            throw new InvalidArgumentException('The license expiry date cannot be before its start date.');
        }

        return DB::transaction(function () use ($company, $data, $licenseType, $key) {
            $license = License::create([
                'company_id' => $company->id,
                'license_key' => $key,
                'license_type' => $licenseType,
                'max_branches' => $data['max_branches'] ?? 1,
                'max_users' => $data['max_users'] ?? 5,
                'max_devices' => $data['max_devices'] ?? 1,
                'starts_at' => $data['starts_at'] ?? now()->toDateString(),
                'expires_at' => $data['expires_at'] ?? now()->addYear()->toDateString(),
                'status' => $data['status'] ?? 'active',
                'renewal_source' => $data['renewal_source'] ?? 'manual',
                'license_token' => $data['license_token'] ?? null,
                'activated_at' => array_key_exists('activated_at', $data) ? $data['activated_at'] : now(),
                'revoked_at' => $data['revoked_at'] ?? null,
            ]);

            return $this->signEntitlement($license);
        });
    }

    public function expireActiveLicenses(Company $company, ?string $exceptLicenseId = null): void
    {
        License::where('company_id', $company->id)
            ->where('status', 'active')
            ->when($exceptLicenseId, fn ($query) => $query->where('id', '!=', $exceptLicenseId))
            ->update(['status' => 'expired']);

        $company->unsetRelation('activeLicense');
    }

    public function renewLicense(Company $company, array $data): License
    {
        $startsAt = Carbon::parse($data['starts_at'] ?? now())->startOfDay();
        if ($startsAt->gt(now()->startOfDay())) {
            throw new InvalidArgumentException('An immediately assigned license cannot start in the future.');
        }

        $expiresAt = Carbon::parse($data['expires_at'] ?? now()->addYear())->startOfDay();
        if ($expiresAt->lt($startsAt)) {
            throw new InvalidArgumentException('The license expiry date cannot be before its start date.');
        }

        return DB::transaction(function () use ($company, $data) {
            $this->expireActiveLicenses($company);

            return $this->createLicense($company, [
                ...$data,
                'status' => 'active',
                'renewal_source' => $data['renewal_source'] ?? 'manual',
                'activated_at' => now(),
            ]);
        });
    }

    public function isValid(License $license): bool
    {
        return $this->validationCode($license) === 'LICENSE_VALID';
    }

    public function validationCode(License $license): string
    {
        // Verify first so changing status/revocation fields cannot bypass the
        // signed entitlement checks. Expiry remains derived from signed dates.
        if (! $this->verifyEntitlement($license)) {
            return 'LICENSE_TAMPERED';
        }

        if ($license->revoked_at || $license->status === 'revoked') {
            return 'LICENSE_REVOKED';
        }

        if ($license->status !== 'active') {
            return $license->status === 'expired' ? 'LICENSE_EXPIRED' : 'LICENSE_INACTIVE';
        }

        if ($license->starts_at && now()->startOfDay()->lt(Carbon::parse($license->starts_at)->startOfDay())) {
            return 'LICENSE_NOT_STARTED';
        }

        if ($license->expires_at && now()->gt(Carbon::parse($license->expires_at)->endOfDay())) {
            $license->forceFill(['status' => 'expired'])->saveQuietly();

            return 'LICENSE_EXPIRED';
        }

        return 'LICENSE_VALID';
    }

    public function signEntitlement(License $license): License
    {
        if ($this->signatureService()->canSign()) {
            $license->forceFill($this->v2SignatureAttributes($license))->saveQuietly();

            return $license->fresh();
        }

        if ($this->signatureService()->mode() !== 'issuer'
            || ! config('license.signing.legacy_hmac_sign', false)) {
            throw new RuntimeException('This node is not configured to issue license entitlements.');
        }

        $signingKey = $this->legacySigningKey();
        if ($signingKey === null) {
            throw new RuntimeException('A legacy license HMAC key is required to issue v1 entitlements.');
        }

        $payload = $this->encodePayload($this->legacyEntitlementPayload($license));

        $license->forceFill([
            'entitlement_payload' => $payload,
            'entitlement_signature' => hash_hmac('sha256', $payload, $signingKey),
        ])->saveQuietly();

        return $license->fresh();
    }

    public function verifyEntitlement(License $license): bool
    {
        if ($this->hasV2Material($license)) {
            // Never fall back to legacy HMAC when any v2 material is present.
            return $this->verifyV2Entitlement($license);
        }

        if ($license->entitlement_payload || $license->entitlement_signature) {
            if (! $license->entitlement_payload
                || ! $license->entitlement_signature
                || ! config('license.signing.legacy_hmac_verify', false)) {
                return false;
            }

            $signingKey = $this->legacySigningKey();
            if ($signingKey === null) {
                return false;
            }

            $expectedPayload = $this->encodePayload($this->legacyEntitlementPayload($license));
            if (! hash_equals((string) $license->entitlement_payload, $expectedPayload)) {
                return false;
            }

            return hash_equals(
                (string) $license->entitlement_signature,
                hash_hmac('sha256', $expectedPayload, $signingKey)
            );
        }

        if ($this->signatureService()->mode() !== 'issuer'
            || ! config('license.signing.allow_unsigned_legacy', false)
            || ! config('license.signing.auto_upgrade_unsigned', false)) {
            return false;
        }

        try {
            $fresh = $this->signEntitlement($license);
            $license->setRawAttributes($fresh->getAttributes(), true);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function activateByKey(Company $company, string $key): License
    {
        $normalised = $this->normalizeLicenseKey($key);

        return DB::transaction(function () use ($company, $normalised) {
            $license = License::where('company_id', $company->id)
                ->where('license_key', $normalised)
                ->lockForUpdate()
                ->first();

            if (! $license) {
                throw new InvalidArgumentException('The license key is not assigned to this company.');
            }

            if (! $this->verifyEntitlement($license)) {
                throw new InvalidArgumentException('The stored license entitlement is invalid.');
            }

            if ($license->revoked_at || $license->status === 'revoked') {
                throw new InvalidArgumentException('This license has been revoked.');
            }

            if ($license->status === 'expired') {
                throw new InvalidArgumentException('This license has expired.');
            }

            if ($license->starts_at && now()->startOfDay()->lt(Carbon::parse($license->starts_at)->startOfDay())) {
                throw new InvalidArgumentException('This license is not active yet.');
            }

            if ($license->expires_at && now()->gt(Carbon::parse($license->expires_at)->endOfDay())) {
                $license->forceFill(['status' => 'expired'])->saveQuietly();
                throw new InvalidArgumentException('This license has expired.');
            }

            $this->expireActiveLicenses($company, (string) $license->id);
            $license->forceFill([
                'status' => 'active',
                'activated_at' => now(),
                'renewal_source' => $license->renewal_source ?: 'offline_key',
            ])->save();
            $company->unsetRelation('activeLicense');

            return $license->fresh();
        });
    }

    public function updateLicense(License $license, array $data): License
    {
        if (array_key_exists('license_key', $data) && $data['license_key']) {
            $data['license_key'] = $this->normalizeLicenseKey((string) $data['license_key']);
        }

        // Persist the mutable fields and their matching signature together so
        // a signing/configuration failure cannot leave a tampered DB row.
        $license->fill($data);

        return $this->signEntitlement($license);
    }

    /** Portable signed export; normal offline activation uses the short key. */
    public function generateOfflineLicenseToken(
        Company $company,
        array $data,
        ?License $license = null
    ): string {
        if ($license && (string) $license->company_id !== (string) $company->id) {
            throw new InvalidArgumentException('The license does not belong to this company.');
        }

        if (! $license) {
            $license = new License;
            $license->forceFill([
                'id' => (string) ($data['license_id'] ?? Str::uuid()),
                'company_id' => $company->id,
                'license_key' => ! empty($data['license_key'])
                    ? $this->normalizeLicenseKey((string) $data['license_key'])
                    : $this->generateLicenseKey($company, (string) ($data['license_type'] ?? 'enterprise')),
                'license_type' => $data['license_type'] ?? 'enterprise',
                'max_branches' => $data['max_branches'] ?? 1,
                'max_users' => $data['max_users'] ?? 5,
                'max_devices' => $data['max_devices'] ?? 1,
                'starts_at' => $data['starts_at'] ?? now()->toDateString(),
                'expires_at' => $data['expires_at'] ?? now()->addYear()->toDateString(),
                'status' => 'inactive',
                'revoked_at' => null,
            ]);
            $license->forceFill($this->v2SignatureAttributes($license));
        } elseif ($this->hasV2Material($license)) {
            // Existing v2 material is authoritative. Never turn a tampered or
            // partially-corrupt record into a freshly authorized export.
            if (! $this->verifyV2Entitlement($license)) {
                throw new RuntimeException('The stored v2 license entitlement is invalid.');
            }
        } else {
            // Only a verified legacy entitlement may be upgraded for portable
            // v2 export. verifyEntitlement may itself upgrade an explicitly
            // allowed unsigned legacy row on an issuer.
            if (! $this->verifyEntitlement($license)) {
                throw new RuntimeException('A verified license entitlement is required for offline export.');
            }
            if (! $this->hasV2Material($license)) {
                $license = $this->signEntitlement($license);
            }
        }

        if (! $this->verifyV2Entitlement($license)) {
            throw new RuntimeException('A valid v2 entitlement is required for offline export.');
        }

        $payload = base64_decode((string) $license->entitlement_payload_v2, true);
        $signature = base64_decode((string) $license->entitlement_signature_v2, true);
        if ($payload === false || $signature === false) {
            throw new RuntimeException('The signed license entitlement is malformed.');
        }

        return implode('.', [
            'v2',
            $this->base64UrlEncode($payload),
            $this->base64UrlEncode($signature),
            $this->base64UrlEncode((string) $license->signature_key_id),
        ]);
    }

    public function validateOfflineLicenseToken(string $token): array
    {
        $parts = explode('.', trim($token));

        if (count($parts) === 4 && $parts[0] === 'v2') {
            return $this->validateV2OfflineLicenseToken($parts);
        }

        if (! config('license.signing.legacy_offline_token_verify', false)
            || ! config('license.signing.legacy_hmac_verify', false)
            || count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid legacy license token format.');
        }

        [$encoded, $signature] = $parts;
        $signingKey = $this->legacySigningKey();
        if ($signingKey === null
            || ! hash_equals(hash_hmac('sha256', $encoded, $signingKey), $signature)) {
            throw new InvalidArgumentException('Invalid license signature.');
        }

        $decoded = base64_decode($encoded, true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($payload) || empty($payload['company_id']) || empty($payload['expires_at'])) {
            throw new InvalidArgumentException('Invalid license payload.');
        }

        if (now()->gt(Carbon::parse($payload['expires_at'])->endOfDay())) {
            throw new InvalidArgumentException('License token has already expired.');
        }

        return $payload;
    }

    public function renewUsingOfflineToken(string $token, ?Company $resolvedCompany = null): License
    {
        $payload = $this->validateOfflineLicenseToken($token);
        $company = Company::find($payload['company_id']);

        if (! $company || ($resolvedCompany && (string) $company->id !== (string) $resolvedCompany->id)) {
            throw new InvalidArgumentException('Company not found for this license.');
        }

        if (($payload['version'] ?? null) === 2) {
            return $this->importV2OfflineEntitlement($company, $payload, $token);
        }

        return $this->renewLicense($company, [
            'license_type' => $payload['license_type'] ?? 'enterprise',
            'max_branches' => $payload['max_branches'] ?? 1,
            'max_users' => $payload['max_users'] ?? 5,
            'max_devices' => $payload['max_devices'] ?? 1,
            'starts_at' => $payload['starts_at'] ?? now()->toDateString(),
            'expires_at' => $payload['expires_at'],
            'renewal_source' => 'legacy_offline_token',
            'license_token' => $token,
        ]);
    }

    private function legacyEntitlementPayload(License $license): array
    {
        return [
            'version' => 1,
            'license_id' => (string) $license->id,
            'company_id' => (string) $license->company_id,
            'license_key' => strtoupper((string) $license->license_key),
            'license_type' => (string) $license->license_type,
            'max_branches' => (int) $license->max_branches,
            'max_users' => (int) $license->max_users,
            'max_devices' => (int) $license->max_devices,
            'starts_at' => $license->starts_at ? Carbon::parse($license->starts_at)->toDateString() : null,
            'expires_at' => $license->expires_at ? Carbon::parse($license->expires_at)->toDateString() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function v2SignatureAttributes(License $license): array
    {
        $signatures = $this->signatureService();
        if (! $signatures->canSign()) {
            throw new RuntimeException('An issuer private key is required to sign a v2 entitlement.');
        }

        $alreadySigned = (int) $license->entitlement_version === 2
            && ! empty($license->entitlement_signature_v2);
        $revision = max(1, (int) ($license->entitlement_revision ?? 1));
        if ($alreadySigned) {
            $revision++;
        }

        $signedAt = now()->startOfSecond();
        $algorithm = $signatures->algorithm();
        $keyId = $signatures->activeKeyId();
        $payload = $this->encodeCanonicalPayload($this->v2EntitlementPayload(
            $license,
            $algorithm,
            $keyId,
            $revision,
            $signedAt
        ));
        $signed = $signatures->sign($payload);

        if ($signed['algorithm'] !== $algorithm || $signed['key_id'] !== $keyId) {
            throw new RuntimeException('The license signing identity changed while signing.');
        }

        return [
            'entitlement_payload_v2' => $payload,
            'entitlement_signature_v2' => $signed['signature'],
            'signature_algorithm' => $algorithm,
            'signature_key_id' => $keyId,
            'entitlement_version' => 2,
            'entitlement_revision' => $revision,
            'signed_at' => $signedAt,
        ];
    }

    private function verifyV2Entitlement(License $license): bool
    {
        if ((int) $license->entitlement_version !== 2
            || empty($license->entitlement_payload_v2)
            || empty($license->entitlement_signature_v2)
            || empty($license->signature_algorithm)
            || empty($license->signature_key_id)
            || empty($license->signed_at)
            || (int) $license->entitlement_revision < 1) {
            return false;
        }

        $expectedPayload = $this->encodeCanonicalPayload($this->v2EntitlementPayload(
            $license,
            (string) $license->signature_algorithm,
            (string) $license->signature_key_id,
            (int) $license->entitlement_revision,
            Carbon::parse($license->signed_at)
        ));

        if (! hash_equals((string) $license->entitlement_payload_v2, $expectedPayload)) {
            return false;
        }

        return $this->signatureService()->verify(
            $expectedPayload,
            (string) $license->entitlement_signature_v2,
            (string) $license->signature_algorithm,
            (string) $license->signature_key_id
        );
    }

    private function hasV2Material(License $license): bool
    {
        return (int) $license->entitlement_version >= 2
            || ! empty($license->entitlement_payload_v2)
            || ! empty($license->entitlement_signature_v2)
            || ! empty($license->signature_algorithm)
            || ! empty($license->signature_key_id)
            || ! empty($license->signed_at);
    }

    /** @return array<string, mixed> */
    private function v2EntitlementPayload(
        License $license,
        string $algorithm,
        string $keyId,
        int $revision,
        Carbon $signedAt
    ): array {
        $revokedAt = $license->revoked_at
            ? Carbon::parse($license->revoked_at)->format('Y-m-d H:i:s')
            : null;

        return [
            'authorization_state' => $revokedAt || $license->status === 'revoked' ? 'revoked' : 'issued',
            'company_id' => (string) $license->company_id,
            'entitlement_revision' => $revision,
            'expires_at' => $license->expires_at ? Carbon::parse($license->expires_at)->toDateString() : null,
            // Persist the database wall-clock representation. Laravel's
            // timestamp cast otherwise re-applies each node's local timezone.
            'issued_at' => $signedAt->format('Y-m-d H:i:s'),
            'license_id' => (string) $license->id,
            'license_key' => strtoupper((string) $license->license_key),
            'license_type' => (string) $license->license_type,
            'max_branches' => (int) $license->max_branches,
            'max_devices' => (int) $license->max_devices,
            'max_users' => (int) $license->max_users,
            'purpose' => 'license_entitlement',
            'revoked_at' => $revokedAt,
            'signature_algorithm' => $algorithm,
            'signature_key_id' => $keyId,
            'starts_at' => $license->starts_at ? Carbon::parse($license->starts_at)->toDateString() : null,
            'version' => 2,
        ];
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<string, mixed>
     */
    private function validateV2OfflineLicenseToken(array $parts): array
    {
        $payloadJson = $this->base64UrlDecode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        $keyId = $this->base64UrlDecode($parts[3]);

        if ($payloadJson === false || $signature === false || $keyId === false) {
            throw new InvalidArgumentException('Invalid v2 license token encoding.');
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)
            || (int) ($payload['version'] ?? 0) !== 2
            || ($payload['purpose'] ?? null) !== 'license_entitlement'
            || empty($payload['license_id'])
            || empty($payload['company_id'])
            || empty($payload['license_key'])
            || empty($payload['expires_at'])
            || empty($payload['signature_algorithm'])
            || empty($payload['signature_key_id'])
            || ! hash_equals((string) $payload['signature_key_id'], $keyId)
            || ! hash_equals($this->canonicalJson($payload), $payloadJson)) {
            throw new InvalidArgumentException('Invalid v2 license token payload.');
        }

        $encodedPayload = base64_encode($payloadJson);
        $encodedSignature = base64_encode($signature);
        if (! $this->signatureService()->verify(
            $encodedPayload,
            $encodedSignature,
            (string) $payload['signature_algorithm'],
            $keyId
        )) {
            throw new InvalidArgumentException('Invalid license signature.');
        }

        if (($payload['authorization_state'] ?? null) !== 'issued'
            || ! empty($payload['revoked_at'])) {
            throw new InvalidArgumentException('This license entitlement has been revoked.');
        }

        if (now()->gt(Carbon::parse($payload['expires_at'])->endOfDay())) {
            throw new InvalidArgumentException('License token has already expired.');
        }

        $payload['_entitlement_payload_v2'] = $encodedPayload;
        $payload['_entitlement_signature_v2'] = $encodedSignature;

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function importV2OfflineEntitlement(Company $company, array $payload, string $token): License
    {
        $licenseKey = $this->normalizeLicenseKey((string) $payload['license_key']);
        $startsAt = Carbon::parse($payload['starts_at'] ?? now())->startOfDay();
        $expiresAt = Carbon::parse($payload['expires_at'])->startOfDay();

        if ($expiresAt->lt($startsAt)) {
            throw new InvalidArgumentException('The license expiry date cannot be before its start date.');
        }

        return DB::transaction(function () use ($company, $payload, $token, $licenseKey) {
            $license = License::query()
                ->where('id', (string) $payload['license_id'])
                ->orWhere('license_key', $licenseKey)
                ->lockForUpdate()
                ->first();

            if ($license && (string) $license->company_id !== (string) $company->id) {
                throw new InvalidArgumentException('The signed license belongs to another company.');
            }

            if ($license
                && (int) $license->entitlement_version >= 2
                && (int) $license->entitlement_revision > (int) $payload['entitlement_revision']) {
                throw new InvalidArgumentException('A newer license entitlement is already installed.');
            }

            if ($license
                && (int) $license->entitlement_version >= 2
                && (int) $license->entitlement_revision === (int) $payload['entitlement_revision']
                && ! hash_equals(
                    (string) $license->entitlement_payload_v2,
                    (string) $payload['_entitlement_payload_v2']
                )) {
                throw new InvalidArgumentException('The entitlement revision conflicts with the installed license.');
            }

            $this->expireActiveLicenses($company, (string) $payload['license_id']);
            $license ??= new License;
            $license->forceFill([
                'id' => (string) $payload['license_id'],
                'company_id' => $company->id,
                'license_key' => $licenseKey,
                'license_type' => $payload['license_type'] ?? 'enterprise',
                'max_branches' => (int) ($payload['max_branches'] ?? 1),
                'max_users' => (int) ($payload['max_users'] ?? 5),
                'max_devices' => (int) ($payload['max_devices'] ?? 1),
                'starts_at' => $payload['starts_at'] ?? now()->toDateString(),
                'expires_at' => $payload['expires_at'],
                'status' => 'active',
                'renewal_source' => 'offline_token_v2',
                'license_token' => $token,
                'activated_at' => now(),
                'revoked_at' => null,
                'entitlement_payload_v2' => $payload['_entitlement_payload_v2'],
                'entitlement_signature_v2' => $payload['_entitlement_signature_v2'],
                'signature_algorithm' => $payload['signature_algorithm'],
                'signature_key_id' => $payload['signature_key_id'],
                'entitlement_version' => 2,
                'entitlement_revision' => (int) $payload['entitlement_revision'],
                'signed_at' => Carbon::parse($payload['issued_at']),
            ])->save();

            if (! $this->verifyV2Entitlement($license)) {
                throw new InvalidArgumentException('The imported license entitlement is invalid.');
            }

            $company->unsetRelation('activeLicense');

            return $license->fresh();
        });
    }

    private function encodePayload(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function encodeCanonicalPayload(array $payload): string
    {
        return base64_encode($this->canonicalJson($payload));
    }

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
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function signatureService(): LicenseSignatureService
    {
        return app(LicenseSignatureService::class);
    }

    private function legacySigningKey(): ?string
    {
        $key = trim((string) config('license.signing.legacy_hmac_key'));
        if ($key === '' && config('license.signing.legacy_app_key_fallback', false)) {
            $key = (string) config('app.key');
        }

        if ($key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    protected function licenseKeyExists(string $key): bool
    {
        return License::where('license_key', $key)->exists();
    }
}
