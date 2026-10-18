<?php

namespace App\Services\License;

use RuntimeException;

class LicenseSignatureService
{
    public const ALGORITHM_OPENSSL_SHA256 = 'openssl-sha256';

    public const ALGORITHM_ED25519 = 'ed25519';

    public function mode(): string
    {
        return strtolower((string) config('license.signing.mode', 'issuer'));
    }

    public function algorithm(): string
    {
        return strtolower((string) config(
            'license.signing.algorithm',
            self::ALGORITHM_OPENSSL_SHA256
        ));
    }

    public function activeKeyId(): string
    {
        return trim((string) config('license.signing.active_key_id'));
    }

    public function canSign(): bool
    {
        return $this->mode() === 'issuer'
            && $this->activeKeyId() !== ''
            && trim((string) config('license.signing.private_key')) !== '';
    }

    /**
     * @return array{algorithm: string, key_id: string, signature: string}
     */
    public function sign(string $payload): array
    {
        if ($this->mode() !== 'issuer') {
            throw new RuntimeException('This node is configured to verify licenses only.');
        }

        $keyId = $this->activeKeyId();
        if ($keyId === '') {
            throw new RuntimeException('LICENSE_SIGNATURE_KEY_ID is required on an issuer node.');
        }

        $algorithm = $this->algorithm();
        $signature = match ($algorithm) {
            self::ALGORITHM_OPENSSL_SHA256 => $this->signWithOpenSsl($payload),
            self::ALGORITHM_ED25519 => $this->signWithEd25519($payload),
            default => throw new RuntimeException("Unsupported license signature algorithm [{$algorithm}]."),
        };

        return [
            'algorithm' => $algorithm,
            'key_id' => $keyId,
            'signature' => base64_encode($signature),
        ];
    }

    public function verify(
        string $payload,
        string $signature,
        string $algorithm,
        string $keyId
    ): bool {
        $publicKey = $this->publicKeys()[$keyId] ?? null;
        $decodedSignature = base64_decode($signature, true);

        if (! is_string($publicKey) || $publicKey === '' || $decodedSignature === false) {
            return false;
        }

        return match (strtolower($algorithm)) {
            self::ALGORITHM_OPENSSL_SHA256 => $this->verifyWithOpenSsl(
                $payload,
                $decodedSignature,
                $publicKey
            ),
            self::ALGORITHM_ED25519 => $this->verifyWithEd25519(
                $payload,
                $decodedSignature,
                $publicKey
            ),
            default => false,
        };
    }

    public function publicKeyFingerprint(string $keyId, string $algorithm): ?string
    {
        $publicKey = $this->publicKeys()[$keyId] ?? null;
        if (! is_string($publicKey) || trim($publicKey) === '') {
            return null;
        }

        $der = match (strtolower($algorithm)) {
            self::ALGORITHM_OPENSSL_SHA256 => $this->openSslSubjectPublicKeyInfo($publicKey),
            self::ALGORITHM_ED25519 => $this->ed25519SubjectPublicKeyInfo($publicKey),
            default => null,
        };

        return is_string($der) && $der !== '' ? hash('sha256', $der) : null;
    }

    private function signWithOpenSsl(string $payload): string
    {
        if (! extension_loaded('openssl')) {
            throw new RuntimeException('The OpenSSL PHP extension is required to issue licenses.');
        }

        $privateKey = openssl_pkey_get_private(
            $this->decodeOpenSslKey((string) config('license.signing.private_key')),
            (string) config('license.signing.private_key_passphrase', '')
        );

        if ($privateKey === false) {
            throw new RuntimeException('The configured license private key is invalid.');
        }

        $signature = '';
        if (! openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL could not sign the license entitlement.');
        }

        return $signature;
    }

    private function verifyWithOpenSsl(string $payload, string $signature, string $publicKey): bool
    {
        if (! extension_loaded('openssl')) {
            return false;
        }

        $key = openssl_pkey_get_public($this->decodeOpenSslKey($publicKey));
        if ($key === false) {
            return false;
        }

        return openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function signWithEd25519(string $payload): string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The Sodium PHP extension is required for Ed25519 license signing.');
        }

        $secret = $this->decodeRawKey((string) config('license.signing.private_key'));
        if (strlen($secret) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($secret));
        }

        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The configured Ed25519 private key has an invalid length.');
        }

        return sodium_crypto_sign_detached($payload, $secret);
    }

    private function verifyWithEd25519(string $payload, string $signature, string $publicKey): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $key = $this->decodeRawKey($publicKey);
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $payload, $key);
    }

    private function openSslSubjectPublicKeyInfo(string $publicKey): ?string
    {
        if (! extension_loaded('openssl')) {
            return null;
        }
        $key = openssl_pkey_get_public($this->decodeOpenSslKey($publicKey));
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details) || empty($details['key'])) {
            return null;
        }

        $pem = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', (string) $details['key']);
        $der = is_string($pem) ? base64_decode($pem, true) : false;

        return $der === false ? null : $der;
    }

    private function ed25519SubjectPublicKeyInfo(string $publicKey): ?string
    {
        $raw = $this->decodeRawKey($publicKey);
        if (! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        // RFC 8410 SubjectPublicKeyInfo prefix for id-Ed25519 followed by the
        // 32-byte raw public key as a BIT STRING.
        return hex2bin('302a300506032b6570032100').$raw;
    }

    /** @return array<string, string> */
    private function publicKeys(): array
    {
        $keys = config('license.signing.public_keys', []);

        return is_array($keys) ? $keys : [];
    }

    private function decodeOpenSslKey(string $key): string
    {
        $key = trim(str_replace('\\n', "\n", $key));
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded === false ? '' : $decoded;
    }

    private function decodeRawKey(string $key): string
    {
        $key = trim($key);
        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
        }

        $decoded = base64_decode($key, true);

        return $decoded === false ? '' : $decoded;
    }
}
