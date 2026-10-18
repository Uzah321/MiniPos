<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shiftconfigurations', function (Blueprint $table) {
            $table->boolean('allow_unscheduled_shifts')->default(true)->after('allow_multiple_open_shifts');
            $table->unsignedInteger('early_clock_in_minutes')->default(15)->after('allow_unscheduled_shifts');
            $table->unsignedInteger('shift_warning_before_end_minutes')->default(15)->after('early_clock_in_minutes');
            $table->unsignedInteger('shift_grace_minutes_after_end')->default(10)->after('shift_warning_before_end_minutes');
            $table->boolean('allow_continue_unclosed_shift')->default(true)->after('shift_grace_minutes_after_end');
            $table->boolean('require_cashup_before_new_shift')->default(true)->after('allow_continue_unclosed_shift');
            $table->boolean('block_sales_after_shift_end')->default(false)->after('require_cashup_before_new_shift');
            $table->boolean('allow_supervisor_cashup')->default(true)->after('block_sales_after_shift_end');
            $table->boolean('require_on_behalf_reason')->default(true)->after('allow_supervisor_cashup');
        });
    }

    public function down(): void
    {
        Schema::table('shiftconfigurations', function (Blueprint $table) {
            $table->dropColumn([
                'allow_unscheduled_shifts',
                'early_clock_in_minutes',
                'shift_warning_before_end_minutes',
                'shift_grace_minutes_after_end',
                'allow_continue_unclosed_shift',
                'require_cashup_before_new_shift',
                'block_sales_after_shift_end',
                'allow_supervisor_cashup',
                'require_on_behalf_reason',
            ]);
        });
    }
};
