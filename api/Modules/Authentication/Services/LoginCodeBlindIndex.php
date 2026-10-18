<?php

namespace Modules\Authentication\Services;

use InvalidArgumentException;
use LogicException;

final class LoginCodeBlindIndex
{
    /** @var array<string, string> */
    private array $keys;

    private string $activeKeyId;

    /**
     * @param  array<string, string|null>|null  $keys
     */
    public function __construct(?array $keys = null, ?string $activeKeyId = null)
    {
        $configured = $keys ?? config('authentication.security.login_code_blind_index.keys', []);
        $this->keys = collect($configured)
            ->mapWithKeys(function (mixed $key, mixed $keyId): array {
                $normalisedId = trim((string) $keyId);
                $normalisedKey = trim((string) $key);

                return $normalisedId === '' || $normalisedKey === ''
                    ? []
                    : [$normalisedId => $normalisedKey];
            })
            ->all();

        $this->activeKeyId = trim((string) (
            $activeKeyId
            ?? config('authentication.security.login_code_blind_index.active_key_id', 'v1')
        ));

        if ($this->activeKeyId === '' || ! array_key_exists($this->activeKeyId, $this->keys)) {
            throw new LogicException('The active login-code lookup key is not configured.');
        }

        foreach ($this->keys as $key) {
            if (strlen($this->keyMaterial($key)) < 16) {
                throw new LogicException('Login-code lookup keys must contain at least 128 bits of key material.');
            }
        }
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function digestForCompany(string $companyId, string $loginCode): string
    {
        return $this->digestWithKey(
            $this->activeKeyId,
            $companyId,
            $loginCode
        );
    }

    /**
     * Return current and previous digests so key rotation does not lock out
     * users whose blind index has not yet been refreshed.
     *
     * @return array<string, string> key id => digest
     */
    public function candidateDigestsForCompany(string $companyId, string $loginCode): array
    {
        $digests = [];
        foreach (array_keys($this->keys) as $keyId) {
            $digests[$keyId] = $this->digestWithKey($keyId, $companyId, $loginCode);
        }

        return $digests;
    }

    public function digestWithKey(string $keyId, string $companyId, string $loginCode): string
    {
        $companyId = trim($companyId);
        if ($companyId === '') {
            throw new InvalidArgumentException('A company identifier is required for login-code lookup.');
        }

        if (! array_key_exists($keyId, $this->keys)) {
            throw new InvalidArgumentException('The requested login-code lookup key is unavailable.');
        }

        $normalisedCode = self::canonicalizeCode($loginCode);
        $payload = "servnest-login-code\0{$keyId}\0company:{$companyId}\0{$normalisedCode}";

        return hash_hmac('sha256', $payload, $this->keyMaterial($this->keys[$keyId]));
    }

    public function matches(
        string $digest,
        string $keyId,
        string $companyId,
        string $loginCode
    ): bool {
        return hash_equals($digest, $this->digestWithKey($keyId, $companyId, $loginCode));
    }

    /**
     * Canonicalize identity only; credential syntax belongs to the effective
     * CredentialPolicyService policy. Codes remain case-sensitive, leading
     * zeroes remain significant, and valid Unicode is normalized to NFC when
     * the runtime provides the intl Normalizer.
     */
    public static function canonicalizeCode(string $loginCode): string
    {
        if (function_exists('mb_check_encoding') && ! mb_check_encoding($loginCode, 'UTF-8')) {
            throw new InvalidArgumentException('The login code must be valid UTF-8.');
        }

        $normalised = preg_replace('/^\s+|\s+$/u', '', $loginCode);
        if ($normalised === null) {
            throw new InvalidArgumentException('The login code must be valid UTF-8.');
        }
        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($normalised, \Normalizer::FORM_C);
        }
        if ($normalised === false || $normalised === '') {
            throw new InvalidArgumentException('The login code must not be empty.');
        }

        // Never cast or case-fold: both leading zeroes and casing are part of
        // the credential verified by the slow hash.
        return $normalised;
    }

    private function keyMaterial(string $configuredKey): string
    {
        if (! str_starts_with($configuredKey, 'base64:')) {
            return $configuredKey;
        }

        $decoded = base64_decode(substr($configuredKey, 7), true);
        if ($decoded === false) {
            throw new LogicException('A login-code lookup key is not valid base64.');
        }

        return $decoded;
    }
}
