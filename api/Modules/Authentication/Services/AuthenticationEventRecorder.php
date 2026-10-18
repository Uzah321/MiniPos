<?php

namespace Modules\Authentication\Services;

use InvalidArgumentException;
use LogicException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\AuthenticationEvent;
use Modules\Authentication\Models\PosUser;

final class AuthenticationEventRecorder
{
    private const SENSITIVE_METADATA_KEYS = [
        'authorization',
        'cookie',
        'login_code',
        'otp',
        'password',
        'password_confirmation',
        'pin',
        'recovery_code',
        'secret',
        'token',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $eventType,
        string $outcome,
        AuthorizationScope $scope,
        ?PosUser $user = null,
        ?PosUser $actor = null,
        array $context = []
    ): AuthenticationEvent {
        $eventType = trim($eventType);
        $outcome = trim($outcome);
        if ($eventType === '' || $outcome === '') {
            throw new InvalidArgumentException('Authentication events require a type and outcome.');
        }

        $scopeValues = $scope->toArray();

        return AuthenticationEvent::query()->create([
            'event_type' => $eventType,
            'outcome' => $outcome,
            'pos_user_id' => $user?->getKey(),
            'actor_pos_user_id' => $actor?->getKey(),
            'auth_session_id' => $context['auth_session_id'] ?? null,
            'channel' => $context['channel'] ?? null,
            'workspace' => $context['workspace'] ?? null,
            'authentication_method' => $context['authentication_method'] ?? null,
            'identifier_hash' => isset($context['identifier'])
                ? $this->identifierHash((string) $context['identifier'], $scope->companyId)
                : null,
            ...$scopeValues,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'reason_code' => $context['reason_code'] ?? null,
            'metadata' => $this->redact((array) ($context['metadata'] ?? [])),
            'occurred_at' => $context['occurred_at'] ?? now(),
        ]);
    }

    public function identifierHash(string $identifier, ?string $companyId = null): string
    {
        $configuredKey = trim((string) config('authentication.security.audit_identifier_key'));
        if ($configuredKey === '') {
            throw new LogicException('The authentication audit identifier key is not configured.');
        }

        $key = $configuredKey;
        if (str_starts_with($configuredKey, 'base64:')) {
            $key = base64_decode(substr($configuredKey, 7), true);
            if ($key === false) {
                throw new LogicException('The authentication audit identifier key is not valid base64.');
            }
        }

        if (strlen($key) < 16) {
            throw new LogicException('The authentication audit identifier key must contain at least 128 bits.');
        }

        $scopeKey = trim((string) $companyId);
        $scopeKey = $scopeKey === '' ? 'global' : 'company:'.$scopeKey;

        return hash_hmac(
            'sha256',
            $scopeKey."\0".mb_strtolower(trim($identifier)),
            $key
        );
    }

    /** @param array<string|int, mixed> $metadata @return array<string|int, mixed> */
    private function redact(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            $normalisedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));
            if ($this->isSensitiveMetadataKey($normalisedKey)) {
                $metadata[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = $this->redact($value);
            }
        }

        return $metadata;
    }

    private function isSensitiveMetadataKey(string $key): bool
    {
        foreach (self::SENSITIVE_METADATA_KEYS as $sensitiveKey) {
            if ($key === $sensitiveKey
                || str_starts_with($key, $sensitiveKey.'_')
                || str_ends_with($key, '_'.$sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
