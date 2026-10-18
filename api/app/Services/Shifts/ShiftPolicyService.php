<?php

namespace App\Services\Shifts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\ShiftConfiguration;

class ShiftPolicyService
{
    public function get(string $companyId, ?string $branchId = null): array
    {
        $defaults = [
            'auto_shift_logout' => false,
            'auto_logout_after_minutes' => null,
            'allow_login_with_unclosed_previous_shift' => true,
            'allow_continue_unclosed_shift' => true,
            'only_original_cashier_can_close_shift' => true,
            'manager_can_force_close_shift' => true,
            'allow_new_shift_when_previous_unclosed' => false,
            'allow_new_shift_within_same_shift_interval' => false,
            'require_cashup_before_new_shift' => true,
            'shift_warning_before_end_minutes' => 15,
            'shift_grace_minutes_after_end' => 10,
            'show_shift_banner_in_admin' => true,
            'block_sales_after_shift_end' => false,
            'cashup_must_include_cashouts' => true,
            'require_cashout_reason' => true,
            'cashout_requires_manager_approval' => false,
            'allow_unscheduled_shifts' => true,
            'early_clock_in_minutes' => 15,
            'allow_supervisor_cashup' => true,
            'require_on_behalf_reason' => true,
        ];

        $rows = DB::table('general_settings')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->whereIn('setting_key', array_keys($defaults))
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
            ->get();

        $settings = $defaults;

        foreach ($rows as $row) {
            $settings[$row->setting_key] = $this->cast($row->setting_value, $row->setting_type);
        }

        if (Schema::hasTable('shiftconfigurations')) {
            $configuration = ShiftConfiguration::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');
                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
                ->get()
                ->last();

            if ($configuration) {
                foreach ([
                    'auto_shift_logout',
                    'auto_logout_after_minutes',
                    'allow_continue_unclosed_shift',
                    'require_cashup_before_new_shift',
                    'shift_warning_before_end_minutes',
                    'shift_grace_minutes_after_end',
                    'block_sales_after_shift_end',
                    'allow_unscheduled_shifts',
                    'early_clock_in_minutes',
                    'allow_supervisor_cashup',
                    'require_on_behalf_reason',
                ] as $key) {
                    if (Schema::hasColumn('shiftconfigurations', $key) && $configuration->{$key} !== null) {
                        $settings[$key] = $configuration->{$key};
                    }
                }

                $settings['allow_new_shift_when_previous_unclosed'] = (bool) $configuration->allow_multiple_open_shifts;
                $settings['only_original_cashier_can_close_shift'] = !(bool) ($settings['allow_supervisor_cashup'] ?? true);
                $settings['manager_can_force_close_shift'] = (bool) ($settings['allow_supervisor_cashup'] ?? true);
            }
        }

        return $settings;
    }

    public function value(string $companyId, ?string $branchId, string $key, mixed $default = null): mixed
    {
        return $this->get($companyId, $branchId)[$key] ?? $default;
    }

    public function salesBlockReason(
        string $companyId,
        ?string $branchId,
        object $shift,
        ?CarbonInterface $evaluatedAt = null
    ): ?string
    {
        return $this->salesBlockReasonForCutoff(
            $this->salesCutoff($companyId, $branchId, $shift),
            $evaluatedAt
        );
    }

    /**
     * Resolve the configured cutoff once so heartbeat responses can report the
     * same value enforced by sale creation without repeating policy queries.
     */
    public function salesCutoff(string $companyId, ?string $branchId, object $shift): ?array
    {
        $policy = $this->get($companyId, $branchId);
        $configuration = !empty($shift->shift_configuration_id)
            ? ShiftConfiguration::find($shift->shift_configuration_id)
            : null;

        return $this->resolveSalesCutoff($policy, $shift, $configuration);
    }

    public function salesBlockReasonForCutoff(
        ?array $cutoff,
        ?CarbonInterface $evaluatedAt = null
    ): ?string
    {
        $evaluatedAt ??= now();

        if (!$cutoff || $evaluatedAt->lessThanOrEqualTo($cutoff['at'])) {
            return null;
        }

        if ($cutoff['type'] === 'automatic_logout') {
            return 'This shift reached its scheduled end at '
                . $cutoff['scheduled_end']->toDateTimeString()
                . ' and its configured selling lock delay expired at '
                . $cutoff['at']->toDateTimeString()
                . '. Complete cashup before recording more sales or payments.';
        }

        return 'This shift reached its scheduled end at '
            . $cutoff['scheduled_end']->toDateTimeString()
            . ' and its sales grace period expired at '
            . $cutoff['at']->toDateTimeString()
            . '. Complete cashup before recording more sales or payments.';
    }

    /**
     * Resolve the first valid sales cutoff for a shift.
     *
     * The legacy auto-logout setting is enforced here as a selling lock after
     * the configured shift end. It is not a maximum session duration. Anchoring
     * it to opened_at caused a value such
     * as "2 minutes" to block a 06:00-22:50 shift two minutes after login.
     */
    public function resolveSalesCutoff(array $policy, object $shift, ?object $configuration): ?array
    {
        $scheduledEnd = $this->scheduledShiftEnd($shift, $configuration);

        if (!$scheduledEnd) {
            return null;
        }

        $cutoffs = [];

        if ($policy['block_sales_after_shift_end'] ?? false) {
            $cutoffs[] = [
                'type' => 'sales_grace',
                'scheduled_end' => $scheduledEnd->copy(),
                'at' => $scheduledEnd->copy()->addMinutes(
                    max(0, (int) ($policy['shift_grace_minutes_after_end'] ?? 0))
                ),
            ];
        }

        if (($policy['auto_shift_logout'] ?? false) && (int) ($policy['auto_logout_after_minutes'] ?? 0) > 0) {
            $cutoffs[] = [
                'type' => 'automatic_logout',
                'scheduled_end' => $scheduledEnd->copy(),
                'at' => $scheduledEnd->copy()->addMinutes(
                    (int) $policy['auto_logout_after_minutes']
                ),
            ];
        }

        if (!$cutoffs) {
            return null;
        }

        usort(
            $cutoffs,
            fn (array $left, array $right) => $left['at']->getTimestamp() <=> $right['at']->getTimestamp()
        );

        return $cutoffs[0];
    }

    private function scheduledShiftEnd(object $shift, ?object $configuration): ?Carbon
    {
        $openedAt = data_get($shift, 'opened_at');

        if (!$openedAt || !$configuration) {
            return null;
        }

        $shiftName = strtolower(trim((string) data_get($shift, 'shift_name')));
        $shiftName = str_replace(['_', '-'], ' ', $shiftName);

        $timeFields = match (true) {
            str_contains($shiftName, 'morning') => ['morning_shift_start', 'morning_shift_end'],
            str_contains($shiftName, 'afternoon'), str_contains($shiftName, 'evening') => ['afternoon_shift_start', 'afternoon_shift_end'],
            str_contains($shiftName, 'night') => ['night_shift_start', 'night_shift_end'],
            default => null,
        };

        $startTime = $timeFields ? data_get($configuration, $timeFields[0]) : null;
        $endTime = $timeFields ? data_get($configuration, $timeFields[1]) : null;

        if (!$startTime || !$endTime) {
            return null;
        }

        $opened = Carbon::parse($openedAt);
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $scheduledEnd = $opened->copy()->setTime(
            (int) $end->format('H'),
            (int) $end->format('i'),
            (int) $end->format('s')
        );

        $startMinute = ((int) $start->format('H') * 60) + (int) $start->format('i');
        $endMinute = ((int) $end->format('H') * 60) + (int) $end->format('i');
        $openedMinute = ($opened->hour * 60) + $opened->minute;
        $crossesMidnight = $startMinute > $endMinute;

        /*
         * Only an interval whose configured start is later than its configured
         * end crosses midnight. Comparing the full opened_at timestamp to an
         * HH:mm:00 end incorrectly moved sessions opened during the end minute
         * (for example 22:50:10) to the following day's cutoff.
         */
        if ($crossesMidnight && $openedMinute > $endMinute) {
            $scheduledEnd->addDay();
        }

        return $scheduledEnd;
    }

    private function cast(mixed $value, ?string $type): mixed
    {
        return match ($type) {
            'boolean' => in_array($value, [true, 1, '1', 'true', 'TRUE', 'yes', 'YES'], true),
            'integer' => (int) $value,
            'decimal', 'float' => (float) $value,
            default => $value,
        };
    }
}
