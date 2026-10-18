<?php

namespace App\Services\Security;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Company\Models\CompanyClockGuard;

class ClockGuardService
{
    public function check(string $companyId): array
    {
        $now = now();

        $guard = CompanyClockGuard::firstOrCreate(
            ['company_id' => $companyId],
            [
                'id' => (string) Str::uuid(),
                'last_seen_at' => $now,
                'last_seen_timezone' => config('app.timezone'),
                'last_seen_source' => 'server',
                'rollback_tolerance_minutes' => 10,
            ]
        );

        $lastSeen = $guard->last_seen_at;
        $toleranceMinutes = (int) ($guard->rollback_tolerance_minutes ?: 10);

        if ($lastSeen) {
            $minimumAllowedTime = Carbon::parse($lastSeen)->subMinutes($toleranceMinutes);

            if ($now->lt($minimumAllowedTime)) {
                return [
                    'ok' => false,
                    'code' => 'CLOCK_ROLLBACK_DETECTED',
                    'message' => 'Computer date/time appears to be incorrect. Login has been blocked for license protection.',
                    'server_time' => $now->toIso8601String(),
                    'last_seen_at' => Carbon::parse($lastSeen)->toIso8601String(),
                    'tolerance_minutes' => $toleranceMinutes,
                ];
            }
        }

        if (!$lastSeen || $now->gt(Carbon::parse($lastSeen))) {
            $guard->update([
                'last_seen_at' => $now,
                'last_seen_timezone' => config('app.timezone'),
                'last_seen_source' => 'server',
            ]);
        }

        return [
            'ok' => true,
            'code' => 'CLOCK_OK',
            'message' => 'Clock check passed.',
            'server_time' => $now->toIso8601String(),
            'last_seen_at' => optional($guard->fresh()->last_seen_at)->toIso8601String(),
            'tolerance_minutes' => $toleranceMinutes,
        ];
    }

    public function checkOrFail(string $companyId): void
    {
        $result = $this->check($companyId);

        if (!$result['ok']) {
            abort(response()->json([
                'message' => $result['message'],
                'error' => $result['code'],
                'data' => $result,
            ], 403));
        }
    }
}
