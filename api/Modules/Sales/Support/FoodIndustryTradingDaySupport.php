<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Product\Models\TradingDay;
use Modules\Product\Support\HospitalityTradingDatePolicy;

trait FoodIndustryTradingDaySupport
{
    private function foodIndustryTradingDayEnabled(string $companyId, ?string $branchId): bool
    {
        try {
            if (property_exists($this, 'companySettingService') && $this->companySettingService) {
                return $this->companySettingService->isHospitalityMode($companyId, $branchId);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    private function foodIndustrySystemBusinessDate(): string
    {
        return HospitalityTradingDatePolicy::systemDate();
    }

    private function foodIndustryTradingDateString(?TradingDay $day): ?string
    {
        if (!$day) {
            return null;
        }

        $raw = $day->getRawOriginal('trading_date') ?: $day->getAttributeFromArray('trading_date') ?: $day->trading_date;

        if (!$raw) {
            return null;
        }

        return HospitalityTradingDatePolicy::normalize($raw);
    }

    private function emptyFoodIndustrySaleDatePayload(): array
    {
        return [
            'food_industry_business_date_enabled' => false,
            'sale_blocked' => false,
            'sale_block_reason' => null,
            'trading_day_id' => null,
            'business_date' => null,
            'system_date' => $this->foodIndustrySystemBusinessDate(),
            'transaction_date' => now(),
            'trading_day_status_at_sale' => null,
            'trading_date_is_future' => false,
            'trading_date_is_past' => false,
            'trading_date_matches_system_date' => false,
        ];
    }

    private function blockedFoodIndustrySaleDatePayload(?TradingDay $day, string $reason, bool $future = false): array
    {
        $systemDate = $this->foodIndustrySystemBusinessDate();
        $businessDate = $this->foodIndustryTradingDateString($day);

        return [
            'food_industry_business_date_enabled' => true,
            'sale_blocked' => true,
            'sale_block_reason' => $reason,
            'trading_day_id' => $day?->id,
            'business_date' => $businessDate,
            'system_date' => $systemDate,
            'transaction_date' => now(),
            'trading_day_status_at_sale' => $day?->status,
            'trading_date_is_future' => $future,
            'trading_date_is_past' => $businessDate ? $businessDate < $systemDate : false,
            'trading_date_matches_system_date' => $businessDate === $systemDate,
        ];
    }

    private function allowedFoodIndustrySaleDatePayload(TradingDay $day): array
    {
        $systemDate = $this->foodIndustrySystemBusinessDate();
        $businessDate = $this->foodIndustryTradingDateString($day);

        return [
            'food_industry_business_date_enabled' => true,
            'sale_blocked' => false,
            'sale_block_reason' => null,
            'trading_day_id' => $day->id,
            'business_date' => $businessDate,
            'system_date' => $systemDate,
            'transaction_date' => now(),
            'trading_day_status_at_sale' => $day->status,
            'trading_date_is_future' => $businessDate ? $businessDate > $systemDate : false,
            'trading_date_is_past' => $businessDate ? $businessDate < $systemDate : false,
            'trading_date_matches_system_date' => $businessDate === $systemDate,
        ];
    }

    private function resolveFoodIndustryOpenTradingDay(string $companyId, string $branchId): ?TradingDay
    {
        if (!$this->foodIndustryTradingDayEnabled($companyId, $branchId)) {
            return null;
        }

        $running = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'day_end_running')
            ->orderByDesc('day_end_started_at')
            ->orderByDesc('trading_date')
            ->lockForUpdate()
            ->first();

        if ($running) {
            return $running;
        }

        $day = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if ($day) {
            return $day;
        }

        $lastClosed = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'closed')
            ->orderByDesc('trading_date')
            ->lockForUpdate()
            ->first();

        $systemDate = $this->foodIndustrySystemBusinessDate();
        $tradingDate = $lastClosed
            ? HospitalityTradingDatePolicy::nextOpenDate($this->foodIndustryTradingDateString($lastClosed), $systemDate)
            : $systemDate;

        // Closing today's business day must not silently open tomorrow and let
        // the branch post into a future accounting date.  The next request on
        // the next real system day will create the next trading day.
        if (!$tradingDate) {
            return null;
        }

        return TradingDay::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'trading_date' => $tradingDate,
            ],
            [
                'id' => (string) Str::uuid(),
                'status' => 'open',
                'opened_at' => now(),
                'metadata' => [
                    'source' => $lastClosed ? 'auto_open_food_industry_sale_from_last_closed_day' : 'auto_open_food_industry_sale_initial_install',
                    'scope' => 'food_industry_only',
                    'system_date_at_open' => $systemDate,
                    'created_from_last_closed_day' => $lastClosed?->id,
                ],
            ]
        );
    }

    private function foodIndustrySaleDatePayload(string $companyId, string $branchId): array
    {
        if (!$this->foodIndustryTradingDayEnabled($companyId, $branchId)) {
            return $this->emptyFoodIndustrySaleDatePayload();
        }

        $day = $this->resolveFoodIndustryOpenTradingDay($companyId, $branchId);

        if (!$day) {
            return $this->blockedFoodIndustrySaleDatePayload(
                day: null,
                reason: 'Sale rejected. No valid Hospitality trading date is open for this branch. Run or review Day End before selling.'
            );
        }

        $systemDate = $this->foodIndustrySystemBusinessDate();
        $businessDate = $this->foodIndustryTradingDateString($day);

        $openDayCount = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->count();

        if ($openDayCount > 1) {
            return $this->blockedFoodIndustrySaleDatePayload(
                day: $day,
                reason: 'Sale rejected. Multiple open Hospitality trading dates exist for this branch. Resolve the duplicate trading-day records before selling.'
            );
        }

        if (!$businessDate) {
            return $this->blockedFoodIndustrySaleDatePayload(
                day: $day,
                reason: 'Sale rejected. The active Hospitality trading date is missing or invalid.'
            );
        }

        if ($day->status === 'day_end_running') {
            return $this->blockedFoodIndustrySaleDatePayload(
                day: $day,
                reason: 'POS is locked because Day End is currently running for trading date ' . ($businessDate ?: 'UNKNOWN') . '.',
                future: false
            );
        }

        if ($businessDate > $systemDate) {
            return $this->blockedFoodIndustrySaleDatePayload(
                day: $day,
                reason: 'Sale rejected. Trading date ' . $businessDate . ' is ahead of system date ' . $systemDate . '. POS must not sell after Day End until the real system date reaches the next trading date.',
                future: true
            );
        }

        return $this->allowedFoodIndustrySaleDatePayload($day);
    }

    private function applyFoodIndustryBusinessDateToSale($sale, array $payload): void
    {
        if (!$sale || !($payload['food_industry_business_date_enabled'] ?? false)) {
            return;
        }

        $updates = [];

        if (Schema::hasColumn('sales_transactions', 'trading_day_id')) {
            $updates['trading_day_id'] = $payload['trading_day_id'];
        }

        if (Schema::hasColumn('sales_transactions', 'business_date')) {
            $updates['business_date'] = $payload['business_date'];
        }

        if (Schema::hasColumn('sales_transactions', 'metadata')) {
            $current = $sale->metadata;

            if (!is_array($current)) {
                $current = json_decode($current ?? '[]', true) ?: [];
            }

            $updates['metadata'] = array_merge($current, [
                'food_industry_business_date_enabled' => true,
                'business_date' => $payload['business_date'],
                'system_date_at_sale' => $payload['system_date'] ?? null,
                'trading_day_id' => $payload['trading_day_id'],
                'trading_day_status_at_sale' => $payload['trading_day_status_at_sale'],
                'trading_date_is_future' => $payload['trading_date_is_future'] ?? false,
                'trading_date_is_past' => $payload['trading_date_is_past'] ?? false,
                'trading_date_matches_system_date' => $payload['trading_date_matches_system_date'] ?? false,
                'transaction_date_is_system_timestamp' => true,
            ]);
        }

        if (!empty($updates)) {
            // forceFill is intentional: business_date and trading_day_id are
            // accounting controls and must never be dropped by mass-assignment
            // configuration on a legacy deployment.
            $sale->forceFill($updates)->save();
        }
    }
}
