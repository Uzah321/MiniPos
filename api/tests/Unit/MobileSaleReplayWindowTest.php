<?php

namespace Tests\Unit;

use App\Services\Sync\MobileSaleReplayWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MobileSaleReplayWindowTest extends TestCase
{
    public function test_sale_before_shift_close_is_historically_valid_even_if_uploaded_later(): void
    {
        $sale = CarbonImmutable::parse('2026-10-07T11:00:00+02:00');
        $this->assertTrue(MobileSaleReplayWindow::contains(
            $sale, '2026-10-07T09:00:00+02:00', '2026-10-07T12:00:00+02:00'
        ));
    }

    public function test_force_day_end_started_before_offline_sale_is_not_valid(): void
    {
        $sale = CarbonImmutable::parse('2026-10-07T11:00:00+02:00');
        $this->assertFalse(MobileSaleReplayWindow::contains(
            $sale, '2026-10-07T09:00:00+02:00', '2026-10-07T12:00:00+02:00',
            '2026-10-07T10:30:00+02:00'
        ));
    }

    public function test_sale_after_shift_close_is_not_backdated_into_that_shift(): void
    {
        $sale = CarbonImmutable::parse('2026-10-07T13:00:00+02:00');
        $this->assertFalse(MobileSaleReplayWindow::contains(
            $sale, '2026-10-07T09:00:00+02:00', '2026-10-07T12:00:00+02:00'
        ));
    }
}
