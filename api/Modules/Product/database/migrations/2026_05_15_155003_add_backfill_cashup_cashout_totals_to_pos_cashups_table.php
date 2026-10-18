<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_cashups')) {
            return;
        }

        $rows = DB::table('pos_cashups')
            ->select(['id', 'expected_cash', 'cashout_total', 'expected_cash_after_cashouts', 'sales_summary', 'cashout_summary'])
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            $salesSummary = $this->toArrayValue($row->sales_summary);
            $cashoutSummary = $this->toArrayValue($row->cashout_summary);
            $nestedCash = $salesSummary['cash'] ?? [];

            $cashoutRows = $cashoutSummary['cash_out_rows'] ?? [];
            $nestedCashoutTotal = (float) ($nestedCash['cashout_total'] ?? 0);
            $summaryCashoutTotal = (float) ($cashoutSummary['cashout_total'] ?? collect($cashoutRows)->sum('amount'));
            $storedCashoutTotal = (float) ($row->cashout_total ?? 0);

            $cashoutTotal = $storedCashoutTotal > 0
                ? $storedCashoutTotal
                : ($summaryCashoutTotal > 0 ? $summaryCashoutTotal : $nestedCashoutTotal);

            $storedExpectedAfter = (float) ($row->expected_cash_after_cashouts ?? 0);
            $nestedExpectedAfter = (float) ($nestedCash['expected_cash_after_cashouts'] ?? 0);
            $expectedCash = (float) ($row->expected_cash ?? ($nestedCash['expected_cash'] ?? 0));

            $expectedAfter = $storedExpectedAfter > 0
                ? $storedExpectedAfter
                : ($nestedExpectedAfter > 0 ? $nestedExpectedAfter : round($expectedCash - $cashoutTotal, 4));

            DB::table('pos_cashups')
                ->where('id', $row->id)
                ->update([
                    'cashout_total' => round($cashoutTotal, 4),
                    'cashout_count' => (int) ($cashoutSummary['cashout_count'] ?? count($cashoutRows)),
                    'expected_cash_after_cashouts' => round($expectedAfter, 4),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // This is a data correction migration. No destructive rollback.
    }

    private function toArrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }
};
