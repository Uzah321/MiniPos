<?php
namespace App\Services\Shifts;

use Carbon\Carbon;
use Modules\Authentication\Models\ShiftConfiguration;

class ShiftResolverService
{
    public function getConfig(string $companyId, string $branchId): ?ShiftConfiguration
    {
        $config = ShiftConfiguration::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->first();

        if (!$config) {
            $config = ShiftConfiguration::where('company_id', $companyId)
                ->whereNull('branch_id')
                ->where('status', 'active')
                ->first();
        }

        return $config;
    }

    public function detect(string $companyId, string $branchId, ?string $time = null, ?string $requestedShiftName = null): ?string
    {
        $time = $time ?? now()->format('H:i');

        $config = $this->getConfig($companyId, $branchId);

        if (!$config || !$config->enable_shifts) {
            return null;
        }

        if ($config->shift_mode === 'manual') {
            $requested = strtolower(trim((string) $requestedShiftName));
            return $requested !== '' ? $requested : 'manual';
        }

        if ($this->isBetween($time, $config->morning_shift_start, $config->morning_shift_end)) {
            return 'morning';
        }

        if ($this->isBetween($time, $config->afternoon_shift_start, $config->afternoon_shift_end)) {
            return 'afternoon';
        }

        if ($this->isBetween($time, $config->night_shift_start, $config->night_shift_end)) {
            return 'night';
        }

        $earlyMinutes = max(0, (int) ($config->early_clock_in_minutes ?? 0));
        if ($earlyMinutes > 0) {
            foreach ([
                'morning' => $config->morning_shift_start,
                'afternoon' => $config->afternoon_shift_start,
                'night' => $config->night_shift_start,
            ] as $name => $start) {
                if ($this->isWithinEarlyWindow($time, $start, $earlyMinutes)) {
                    return $name;
                }
            }
        }

        return $config->allow_unscheduled_shifts ? 'unscheduled' : null;
    }

    private function isBetween(?string $time, ?string $start, ?string $end): bool
    {
        if (!$time || !$start || !$end) {
            return false;
        }

        if ($start <= $end) {
            return $time >= $start && $time <= $end;
        }

        return $time >= $start || $time <= $end;
    }

    private function isWithinEarlyWindow(?string $time, ?string $start, int $minutes): bool
    {
        if (!$time || !$start || $minutes <= 0) {
            return false;
        }

        $now = Carbon::createFromFormat('H:i', substr($time, 0, 5));
        $shiftStart = Carbon::createFromFormat('H:i', substr($start, 0, 5));
        $difference = ($shiftStart->hour * 60 + $shiftStart->minute) - ($now->hour * 60 + $now->minute);
        if ($difference < -720) {
            $difference += 1440;
        }

        return $difference >= 0 && $difference <= $minutes;
    }
}
