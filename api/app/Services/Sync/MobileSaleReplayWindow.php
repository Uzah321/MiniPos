<?php

namespace App\Services\Sync;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Original occurrence must be within persisted trading and cashier windows. */
final class MobileSaleReplayWindow
{
    public static function contains(
        CarbonInterface $occurredAt,
        mixed $openedAt,
        mixed $closedAt,
        mixed $lockStartedAt = null
    ): bool {
        if (! $openedAt || $occurredAt->lessThan(CarbonImmutable::parse($openedAt))) {
            return false;
        }
        if ($closedAt && $occurredAt->greaterThan(CarbonImmutable::parse($closedAt))) {
            return false;
        }
        return ! $lockStartedAt || $occurredAt->lessThan(CarbonImmutable::parse($lockStartedAt));
    }
}
