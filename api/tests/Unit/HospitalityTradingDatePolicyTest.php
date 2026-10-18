<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Product\Support\HospitalityTradingDatePolicy;
use PHPUnit\Framework\TestCase;

class HospitalityTradingDatePolicyTest extends TestCase
{
    public function test_system_date_is_always_resolved_in_africa_harare(): void
    {
        $utcLateNight = CarbonImmutable::parse('2026-10-02 22:30:00', 'UTC');

        $this->assertSame('2026-10-03', HospitalityTradingDatePolicy::systemDate($utcLateNight));
    }

    public function test_a_past_open_trading_date_can_sell_and_run_day_end(): void
    {
        $state = HospitalityTradingDatePolicy::state('2026-10-01', 'open', '2026-10-02');

        $this->assertTrue($state['trading_date_is_past']);
        $this->assertFalse($state['trading_date_is_future']);
        $this->assertTrue($state['can_sell']);
        $this->assertTrue($state['can_run_day_end']);
    }

    public function test_a_future_trading_date_blocks_sales_and_day_end(): void
    {
        $state = HospitalityTradingDatePolicy::state('2026-10-03', 'open', '2026-10-02');

        $this->assertTrue($state['trading_date_is_future']);
        $this->assertFalse($state['can_sell']);
        $this->assertFalse($state['can_run_day_end']);
        $this->assertStringContainsString('ahead of system date', $state['sale_block_reason']);
    }

    public function test_day_end_running_and_missing_dates_are_never_saleable(): void
    {
        $running = HospitalityTradingDatePolicy::state('2026-10-02', 'day_end_running', '2026-10-02');
        $missing = HospitalityTradingDatePolicy::state(null, 'open', '2026-10-02');

        $this->assertFalse($running['can_sell']);
        $this->assertFalse($running['can_run_day_end']);
        $this->assertFalse($missing['can_sell']);
        $this->assertTrue($missing['awaiting_next_system_date']);
    }

    public function test_next_day_only_opens_when_it_is_not_after_system_date(): void
    {
        $this->assertSame(
            '2026-10-02',
            HospitalityTradingDatePolicy::nextOpenDate('2026-10-01', '2026-10-02')
        );
        $this->assertNull(
            HospitalityTradingDatePolicy::nextOpenDate('2026-10-02', '2026-10-02')
        );
        $this->assertNull(
            HospitalityTradingDatePolicy::nextOpenDate('2026-10-03', '2026-10-02')
        );
    }

    public function test_invalid_calendar_dates_are_rejected_instead_of_normalized(): void
    {
        $this->assertNull(HospitalityTradingDatePolicy::normalize('2026-02-31'));
        $this->assertNull(HospitalityTradingDatePolicy::normalize('not-a-date'));
        $this->assertSame('2026-10-02', HospitalityTradingDatePolicy::normalize('2026-10-02 12:00:00'));
    }
}
