<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align the deployed cash-up table with the fields already written by the
     * model/controller.  The original table intentionally permits one cash-up
     * per company/shift; multi-currency tenders live inside that one cash-up.
     */
    public function up(): void
    {
        if (!Schema::hasTable('pos_cashups')) {
            return;
        }

        $columns = [
            'cashup_period_key' => fn (Blueprint $table) => $table->string('cashup_period_key', 160)->nullable(),
            'base_currency_code' => fn (Blueprint $table) => $table->string('base_currency_code', 10)->nullable(),
            'cashout_total' => fn (Blueprint $table) => $table->decimal('cashout_total', 18, 4)->default(0),
            'cashout_count' => fn (Blueprint $table) => $table->unsignedInteger('cashout_count')->default(0),
            'expected_cash_after_cashouts' => fn (Blueprint $table) => $table->decimal('expected_cash_after_cashouts', 18, 4)->default(0),
            'cashout_summary' => fn (Blueprint $table) => $table->json('cashout_summary')->nullable(),
            'shift_policy_snapshot' => fn (Blueprint $table) => $table->json('shift_policy_snapshot')->nullable(),
            'business_mode_snapshot' => fn (Blueprint $table) => $table->json('business_mode_snapshot')->nullable(),
            'force_close_reason' => fn (Blueprint $table) => $table->text('force_close_reason')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('pos_cashups', $column)) {
                Schema::table('pos_cashups', fn (Blueprint $table) => $definition($table));
            }
        }

        if (
            Schema::hasColumn('pos_cashups', 'cashup_period_key')
            && Schema::hasColumn('pos_cashups', 'cashup_bracket_key')
        ) {
            DB::table('pos_cashups')
                ->whereNull('cashup_period_key')
                ->whereNotNull('cashup_bracket_key')
                ->update(['cashup_period_key' => DB::raw('cashup_bracket_key')]);
        }

        if (
            Schema::hasColumn('pos_cashups', 'base_currency_code')
            && Schema::hasColumn('pos_cashups', 'cashup_currency_code')
        ) {
            DB::table('pos_cashups')
                ->whereNull('base_currency_code')
                ->whereNotNull('cashup_currency_code')
                ->where('cashup_currency_code', '<>', 'MULTI')
                ->update(['base_currency_code' => DB::raw('cashup_currency_code')]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_cashups')) {
            return;
        }

        // Cash-out and policy columns are also owned by the earlier
        // GeneralSettings migration.  Never remove those on rollback here;
        // this migration only owns the three columns below.
        foreach ([
            'business_mode_snapshot',
            'base_currency_code',
            'cashup_period_key',
        ] as $column) {
            if (Schema::hasColumn('pos_cashups', $column)) {
                Schema::table('pos_cashups', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
