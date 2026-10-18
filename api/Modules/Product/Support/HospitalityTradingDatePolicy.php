<?php

namespace Modules\Product\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure business-date rules shared by Hospitality POS and Day End.
 *
 * A trading date is a DATE value, not a timestamp.  The physical clock is
 * always interpreted in Africa/Harare so a server configured in another
 * timezone cannot open tomorrow's trading day early.
 */
final class HospitalityTradingDatePolicy
{
    public const TIMEZONE = 'Africa/Harare';

    public static function runtimeLockCacheKey(string $companyId, string $branchId): string
    {
        return 'hospitality_day_end_runtime_lock:' . $companyId . ':' . $branchId;
    }

    public static function systemDate(CarbonInterface|string|null $now = null): string
    {
        if ($now instanceof CarbonInterface) {
            return CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE)->toDateString();
        }

        return CarbonImmutable::parse($now ?? 'now', self::TIMEZONE)
            ->setTimezone(self::TIMEZONE)
            ->toDateString();
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);

        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $match)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $match[1], self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $match[1]
            ? $match[1]
            : null;
    }

    public static function nextOpenDate(mixed $closedTradingDate, CarbonInterface|string|null $now = null): ?string
    {
        $closedDate = self::normalize($closedTradingDate);

        if (!$closedDate) {
            return null;
        }

        $candidate = CarbonImmutable::createFromFormat('!Y-m-d', $closedDate, self::TIMEZONE)
            ->addDay()
            ->toDateString();

        return $candidate <= self::systemDate($now) ? $candidate : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function state(mixed $tradingDate, ?string $status = null, CarbonInterface|string|null $now = null): array
    {
        $systemDate = self::systemDate($now);
        $date = self::normalize($tradingDate);
        $normalizedStatus = strtolower(trim((string) $status));
        $future = $date !== null && $date > $systemDate;
        $past = $date !== null && $date < $systemDate;
        $matches = $date !== null && $date === $systemDate;
        $running = $normalizedStatus === 'day_end_running';
        $open = $normalizedStatus === 'open';

        $reason = null;
        if (!$date) {
            $reason = 'No valid Hospitality trading date is open for this branch.';
        } elseif ($future) {
            $reason = "Trading date {$date} is ahead of system date {$systemDate}.";
        } elseif ($running) {
            $reason = "Day End is running for trading date {$date}.";
        } elseif (!$open) {
            $reason = "Trading date {$date} is not open.";
        }

        return [
            'system_date' => $systemDate,
            'system_timezone' => self::TIMEZONE,
            'trading_date_is_future' => $future,
            'trading_date_is_past' => $past,
            'trading_date_matches_system_date' => $matches,
            'awaiting_next_system_date' => !$date,
            'can_sell' => $date !== null && $open && !$future,
            'can_run_day_end' => $date !== null && $open && !$future,
            'sale_block_reason' => $reason,
        ];
    }
}
