<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the legacy ON UPDATE clause which silently replaced a shift's
     * opening time whenever cashup closed it. Repair already affected rows
     * from the cashup's immutable opening snapshot first.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pos_user_shift_sessions')
            || ! Schema::hasColumn('pos_user_shift_sessions', 'opened_at')) {
            return;
        }

        $this->repairClosedShiftOpeningTimes();

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `pos_user_shift_sessions` '
                .'MODIFY `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            );
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring ON UPDATE CURRENT_TIMESTAMP
        // would corrupt shift history again. Repaired business timestamps are
        // retained on rollback.
    }

    private function repairClosedShiftOpeningTimes(): void
    {
        if (! Schema::hasTable('pos_cashups')) {
            return;
        }

        $hasCashupOpenedAt = Schema::hasColumn('pos_cashups', 'cashup_opened_at');
        $hasOpenedAt = Schema::hasColumn('pos_cashups', 'opened_at');
        $query = DB::table('pos_user_shift_sessions as shifts')
            ->join('pos_cashups as cashups', function ($join): void {
                $join->on('cashups.shift_session_id', '=', 'shifts.id')
                    ->on('cashups.company_id', '=', 'shifts.company_id')
                    ->on('cashups.branch_id', '=', 'shifts.branch_id');
            })
            ->whereNotNull('shifts.closed_at')
            ->select([
                'shifts.id',
                'shifts.company_id',
                'shifts.branch_id',
                'shifts.opened_at',
                'shifts.closed_at',
                'shifts.created_at',
                $hasCashupOpenedAt
                    ? 'cashups.cashup_opened_at'
                    : DB::raw('NULL as cashup_opened_at'),
                $hasOpenedAt
                    ? 'cashups.opened_at as cashup_legacy_opened_at'
                    : DB::raw('NULL as cashup_legacy_opened_at'),
            ])
            ->orderBy('shifts.id');
        if (Schema::hasColumn('pos_cashups', 'deleted_at')) {
            $query->whereNull('cashups.deleted_at');
        }

        $query->chunk(250, function ($rows): void {
            foreach ($rows as $row) {
                $closedAt = $this->date($row->closed_at ?? null);
                $currentOpenedAt = $this->date($row->opened_at ?? null);
                if (! $closedAt || ! $currentOpenedAt) {
                    continue;
                }

                $cashupOpening = $this->firstOpeningBeforeClose([
                    $row->cashup_opened_at ?? null,
                    $row->cashup_legacy_opened_at ?? null,
                ], $closedAt);
                $replacement = $cashupOpening
                    ?: $this->firstOpeningBeforeClose([$row->created_at ?? null], $closedAt);
                if (! $replacement) {
                    continue;
                }

                $changedByLegacyClause = $currentOpenedAt->greaterThanOrEqualTo($closedAt);
                $differsFromCashupSnapshot = $cashupOpening
                    && ! $currentOpenedAt->equalTo($cashupOpening);
                if (! $changedByLegacyClause && ! $differsFromCashupSnapshot) {
                    continue;
                }

                DB::table('pos_user_shift_sessions')
                    ->where('id', $row->id)
                    ->where('company_id', $row->company_id)
                    ->where('branch_id', $row->branch_id)
                    ->whereNotNull('closed_at')
                    ->update(['opened_at' => $replacement->toDateTimeString()]);
            }
        });
    }

    private function firstOpeningBeforeClose(array $values, Carbon $closedAt): ?Carbon
    {
        foreach ($values as $value) {
            $candidate = $this->date($value);
            if ($candidate && $candidate->lessThan($closedAt)) {
                return $candidate;
            }
        }

        return null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
};
