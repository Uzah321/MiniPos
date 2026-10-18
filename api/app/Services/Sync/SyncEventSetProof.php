<?php

namespace App\Services\Sync;

/**
 * Constant-size proof for an append-only set of sync events.
 *
 * Each event contributes SHA-256(event id + payload hash) to a commutative
 * XOR accumulator. Count plus the 256-bit accumulator lets edge and cloud
 * compare the complete acknowledged set without loading an unbounded sales
 * history on every status poll. Database uniqueness prevents duplicate ids.
 */
class SyncEventSetProof
{
    public const ALGORITHM = 'sha256-xor-v1';

    public function empty(): array
    {
        return [
            'algorithm' => self::ALGORITHM,
            'count' => 0,
            'digest' => str_repeat('0', 64),
        ];
    }

    public function valid(mixed $proof): bool
    {
        return is_array($proof)
            && ($proof['algorithm'] ?? null) === self::ALGORITHM
            && is_int($proof['count'] ?? null)
            && $proof['count'] >= 0
            && is_string($proof['digest'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $proof['digest']) === 1;
    }

    public function add(array $proof, string $eventId, string $payloadHash): array
    {
        if (! $this->valid($proof) || ! preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new \InvalidArgumentException('Invalid sync event-set proof input.');
        }

        $digest = hex2bin($proof['digest']);
        $leaf = hash('sha256', strtolower($eventId).':'.strtolower($payloadHash), true);
        if ($digest === false) {
            throw new \InvalidArgumentException('Invalid sync event-set digest.');
        }

        return [
            'algorithm' => self::ALGORITHM,
            'count' => $proof['count'] + 1,
            'digest' => bin2hex($digest ^ $leaf),
        ];
    }
}
