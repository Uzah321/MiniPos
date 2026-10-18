<?php

namespace App\Services\Zimra;

use App\Models\MobileFiscalDevice;
use App\Models\SyncNode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A Back Office administrator, not the selling terminal, establishes certificate trust. */
class MobileFiscalRegistrationService
{
    public function register(array $data, SyncNode $node, string $actorId): MobileFiscalDevice
    {
        if ($node->deployment_mode !== 'android' || $node->status !== 'active'
            || $node->revoked_at || ! $node->activated_at || ! $node->till_id) {
            throw new \InvalidArgumentException('Select an active, paired Android terminal with a till.');
        }

        $deviceId = (int) $data['device_id'];
        $serial = trim((string) $data['device_serial_no']);
        $certificate = trim((string) $data['certificate_pem']) . "\n";
        if (preg_match('/-----BEGIN\s+[^-]*PRIVATE KEY-----/i', $certificate)) {
            throw new \InvalidArgumentException('Upload only the public X.509 certificate. Never upload the Android private key to Central.');
        }
        $parsed = openssl_x509_parse($certificate);
        if ($deviceId < 1 || $deviceId > 2147483647 || ! is_array($parsed)) {
            throw new \InvalidArgumentException('The ZIMRA device ID or public certificate is invalid.');
        }
        $expectedCn = 'ZIMRA-' . $serial . '-' . str_pad((string) $deviceId, 10, '0', STR_PAD_LEFT);
        if (! hash_equals($expectedCn, (string) data_get($parsed, 'subject.CN'))) {
            throw new \InvalidArgumentException('The certificate common name does not match this device ID and serial number.');
        }
        $now = time();
        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validUntil = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($validFrom > $now || $validUntil <= $now) {
            throw new \InvalidArgumentException('The ZIMRA public certificate is not currently valid.');
        }
        $key = openssl_pkey_get_public($certificate);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || ! in_array($details['type'] ?? null, [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC], true)) {
            throw new \InvalidArgumentException('The certificate must contain a supported RSA or EC public key.');
        }

        $environment = (string) $data['environment'];
        $trustedCa = trim((string) config('zimra.trusted_ca_bundle', ''));
        if ($environment === 'production' && ($trustedCa === '' || ! is_file($trustedCa))) {
            throw new \InvalidArgumentException('A ZIMRA trusted CA bundle must be configured before registering a production certificate.');
        }
        if ($trustedCa !== '' && is_file($trustedCa)
            && openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_CLIENT, [$trustedCa]) !== 1) {
            throw new \InvalidArgumentException('The certificate did not verify against the configured ZIMRA CA bundle.');
        }

        $qrUrl = rtrim(trim((string) $data['qr_url']), '/') . '/';
        $parts = parse_url($qrUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || ! str_ends_with($host, '.zimra.co.zw')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new \InvalidArgumentException('Use the authoritative HTTPS QR URL returned by ZIMRA GetConfig.');
        }
        if (! $node->branch()->where('company_id', $node->company_id)->exists()
            || ! $node->till()->where('company_id', $node->company_id)->where('branch_id', $node->branch_id)->exists()) {
            throw new \InvalidArgumentException('The paired Android terminal must belong to a branch and till in the selected company.');
        }

        return DB::transaction(function () use ($data, $node, $actorId, $deviceId, $serial, $certificate, $validUntil, $qrUrl, $environment) {
            $existing = MobileFiscalDevice::query()->where('sync_node_id', $node->id)->lockForUpdate()->first();
            if ($existing && ((string) $existing->company_id !== (string) $node->company_id
                    || (string) $existing->branch_id !== (string) $node->branch_id
                    || (string) $existing->till_id !== (string) $node->till_id
                    || $existing->device_id !== $deviceId
                    || $existing->certificate_sha256 !== openssl_x509_fingerprint($certificate, 'sha256')
                    || $existing->device_operating_mode !== $data['device_operating_mode']
                    || $existing->environment !== $environment
                    || $existing->qr_url !== $qrUrl)) {
                // The server cannot know about receipts still only on an
                // offline device. Without an explicit certificate-history and
                // decommission workflow, rotation could strand their proofs.
                throw new \InvalidArgumentException('This terminal’s fiscal device and certificate are immutable. Reconcile offline receipts and use an audited decommission/renewal workflow before replacement.');
            }
            $owner = MobileFiscalDevice::query()->where('device_id', $deviceId)
                ->where('sync_node_id', '!=', $node->id)->first();
            if ($owner) {
                throw new \InvalidArgumentException('This ZIMRA device ID is already assigned to another terminal.');
            }

            return MobileFiscalDevice::query()->updateOrCreate(
                ['sync_node_id' => $node->id],
                [
                    'company_id' => $node->company_id,
                    'branch_id' => $node->branch_id,
                    'till_id' => $node->till_id,
                    'device_id' => $deviceId,
                    'device_serial_no' => $serial,
                    'environment' => $environment,
                    'device_operating_mode' => $data['device_operating_mode'],
                    'qr_url' => $qrUrl,
                    'certificate_pem' => $certificate,
                    'certificate_sha256' => (string) openssl_x509_fingerprint($certificate, 'sha256'),
                    'certificate_valid_until' => CarbonImmutable::createFromTimestamp($validUntil, 'UTC'),
                    'is_active' => true,
                    'registered_by' => $actorId,
                ]
            );
        });
    }
}
