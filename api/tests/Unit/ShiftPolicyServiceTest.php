<?php

namespace Tests\Unit;

use App\Services\Shifts\ShiftPolicyService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ShiftPolicyServiceTest extends TestCase
{
    public function test_auto_logout_delay_is_anchored_to_scheduled_end_not_login_time(): void
    {
        $service = new ShiftPolicyService();
        $shift = (object) [
            'opened_at' => '2026-10-01 10:35:10',
            'shift_name' => 'morning',
        ];
        $configuration = (object) [
            'morning_shift_start' => '06:00:00',
            'morning_shift_end' => '22:50:00',
        ];

        $cutoff = $service->resolveSalesCutoff([
            'block_sales_after_shift_end' => true,
            'shift_grace_minutes_after_end' => 10,
            'auto_shift_logout' => true,
            'auto_logout_after_minutes' => 2,
        ], $shift, $configuration);

        $this->assertNotNull($cutoff);
        $this->assertSame('automatic_logout', $cutoff['type']);
        $this->assertSame('2026-10-01 22:50:00', $cutoff['scheduled_end']->toDateTimeString());
        $this->assertSame('2026-10-01 22:52:00', $cutoff['at']->toDateTimeString());
    }

    public function test_overnight_shift_cutoff_uses_the_following_day(): void
    {
        $service = new ShiftPolicyService();
        $shift = (object) [
            'opened_at' => '2026-10-01 22:00:00',
            'shift_name' => 'Night Shift',
        ];
        $configuration = (object) [
            'night_shift_start' => '22:00:00',
            'night_shift_end' => '06:00:00',
        ];

        $cutoff = $service->resolveSalesCutoff([
            'block_sales_after_shift_end' => true,
            'shift_grace_minutes_after_end' => 10,
            'auto_shift_logout' => false,
            'auto_logout_after_minutes' => null,
        ], $shift, $configuration);

        $this->assertNotNull($cutoff);
        $this->assertSame('sales_grace', $cutoff['type']);
        $this->assertSame('2026-10-02 06:00:00', $cutoff['scheduled_end']->toDateTimeString());
        $this->assertSame('2026-10-02 06:10:00', $cutoff['at']->toDateTimeString());
    }

    public function test_day_shift_opened_during_end_minute_does_not_roll_to_the_next_day(): void
    {
        $service = new ShiftPolicyService();
        $shift = (object) [
            'opened_at' => '2026-10-01 22:50:10',
            'shift_name' => 'morning',
        ];
        $configuration = (object) [
            'morning_shift_start' => '06:00:00',
            'morning_shift_end' => '22:50:00',
        ];

        $cutoff = $service->resolveSalesCutoff([
            'block_sales_after_shift_end' => true,
            'shift_grace_minutes_after_end' => 10,
            'auto_shift_logout' => true,
            'auto_logout_after_minutes' => 2,
        ], $shift, $configuration);

        $this->assertNotNull($cutoff);
        $this->assertSame('2026-10-01 22:50:00', $cutoff['scheduled_end']->toDateTimeString());
        $this->assertSame('2026-10-01 22:52:00', $cutoff['at']->toDateTimeString());
    }

    public function test_overnight_shift_opened_during_end_minute_uses_the_current_day(): void
    {
        $service = new ShiftPolicyService();
        $shift = (object) [
            'opened_at' => '2026-10-02 06:00:30',
            'shift_name' => 'night',
        ];
        $configuration = (object) [
            'night_shift_start' => '22:00:00',
            'night_shift_end' => '06:00:00',
        ];

        $cutoff = $service->resolveSalesCutoff([
            'block_sales_after_shift_end' => false,
            'auto_shift_logout' => true,
            'auto_logout_after_minutes' => 2,
        ], $shift, $configuration);

        $this->assertNotNull($cutoff);
        $this->assertSame('2026-10-02 06:00:00', $cutoff['scheduled_end']->toDateTimeString());
        $this->assertSame('2026-10-02 06:02:00', $cutoff['at']->toDateTimeString());
    }

    public function test_auto_logout_does_not_invent_a_cutoff_without_a_scheduled_end(): void
    {
        $service = new ShiftPolicyService();

        $cutoff = $service->resolveSalesCutoff([
            'block_sales_after_shift_end' => false,
            'auto_shift_logout' => true,
            'auto_logout_after_minutes' => 2,
        ], (object) [
            'opened_at' => '2026-10-01 10:35:10',
            'shift_name' => 'manual',
        ], (object) []);

        $this->assertNull($cutoff);
    }

    public function test_explicit_sale_occurrence_time_controls_cutoff_evaluation(): void
    {
        $service = new ShiftPolicyService();
        $cutoff = [
            'type' => 'sales_grace',
            'scheduled_end' => Carbon::parse('2026-10-09 12:00:00'),
            'at' => Carbon::parse('2026-10-09 12:10:00'),
        ];

        $this->assertNull($service->salesBlockReasonForCutoff(
            $cutoff,
            Carbon::parse('2026-10-09 12:09:59')
        ));
        $this->assertStringContainsString(
            'sales grace period expired',
            (string) $service->salesBlockReasonForCutoff(
                $cutoff,
                Carbon::parse('2026-10-09 12:10:01')
            )
        );
    }
}
