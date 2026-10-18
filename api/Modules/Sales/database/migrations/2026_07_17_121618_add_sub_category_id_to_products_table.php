<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_cashups', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_cashups', 'cashup_number')) {
                $table->string('cashup_number', 80)->nullable()->after('id');
            }

            if (!Schema::hasColumn('pos_cashups', 'business_mode')) {
                $table->string('business_mode', 40)->default('retail')->after('cashup_number');
            }

            if (!Schema::hasColumn('pos_cashups', 'boh_enabled')) {
                $table->boolean('boh_enabled')->default(false)->after('business_mode');
            }

            if (!Schema::hasColumn('pos_cashups', 'fast_food_mode_enabled')) {
                $table->boolean('fast_food_mode_enabled')->default(false)->after('boh_enabled');
            }

            if (!Schema::hasColumn('pos_cashups', 'trading_date')) {
                $table->date('trading_date')->nullable()->after('fast_food_mode_enabled');
            }

            if (!Schema::hasColumn('pos_cashups', 'cashup_currency_code')) {
                $table->string('cashup_currency_code', 10)->nullable()->after('trading_date');
            }

            if (!Schema::hasColumn('pos_cashups', 'cashup_bracket_key')) {
                $table->string('cashup_bracket_key', 160)->nullable()->after('cashup_currency_code');
            }

            if (!Schema::hasColumn('pos_cashups', 'cashup_period_label')) {
                $table->string('cashup_period_label', 180)->nullable()->after('cashup_bracket_key');
            }

            if (!Schema::hasColumn('pos_cashups', 'cashup_opened_at')) {
                $table->timestamp('cashup_opened_at')->nullable()->after('cashup_period_label');
            }

            if (!Schema::hasColumn('pos_cashups', 'cashup_closed_at')) {
                $table->timestamp('cashup_closed_at')->nullable()->after('cashup_opened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_cashups', function (Blueprint $table) {
            foreach ([
                         'cashup_number',
                         'business_mode',
                         'boh_enabled',
                         'fast_food_mode_enabled',
                         'trading_date',
                         'cashup_currency_code',
                         'cashup_bracket_key',
                         'cashup_period_label',
                         'cashup_opened_at',
                         'cashup_closed_at',
                     ] as $column) {
                if (Schema::hasColumn('pos_cashups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
