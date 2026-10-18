<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_order_overheads', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_overheads', 'staff_name')) $table->string('staff_name')->nullable()->after('description');
            if (!Schema::hasColumn('production_order_overheads', 'staff_id')) $table->string('staff_id')->nullable()->after('staff_name');
            if (!Schema::hasColumn('production_order_overheads', 'costing_method')) $table->string('costing_method')->default('manual_amount')->after('staff_id');
            if (!Schema::hasColumn('production_order_overheads', 'worked_hours')) $table->decimal('worked_hours', 18, 4)->default(0)->after('costing_method');
            if (!Schema::hasColumn('production_order_overheads', 'hourly_rate')) $table->decimal('hourly_rate', 18, 4)->default(0)->after('worked_hours');
            if (!Schema::hasColumn('production_order_overheads', 'quantity')) $table->decimal('quantity', 18, 4)->default(0)->after('hourly_rate');
            if (!Schema::hasColumn('production_order_overheads', 'rate')) $table->decimal('rate', 18, 4)->default(0)->after('quantity');
            if (!Schema::hasColumn('production_order_overheads', 'monthly_salary')) $table->decimal('monthly_salary', 18, 4)->default(0)->after('rate');
            if (!Schema::hasColumn('production_order_overheads', 'working_days_in_month')) $table->decimal('working_days_in_month', 18, 4)->default(0)->after('monthly_salary');
            if (!Schema::hasColumn('production_order_overheads', 'working_hours_per_day')) $table->decimal('working_hours_per_day', 18, 4)->default(0)->after('working_days_in_month');
            if (!Schema::hasColumn('production_order_overheads', 'production_hours')) $table->decimal('production_hours', 18, 4)->default(0)->after('working_hours_per_day');
            if (!Schema::hasColumn('production_order_overheads', 'allocation_percent')) $table->decimal('allocation_percent', 18, 4)->default(0)->after('production_hours');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_overheads', function (Blueprint $table) {
            $columns = ['staff_name','staff_id','costing_method','worked_hours','hourly_rate','quantity','rate','monthly_salary','working_days_in_month','working_hours_per_day','production_hours','allocation_percent'];
            foreach ($columns as $column) if (Schema::hasColumn('production_order_overheads', $column)) $table->dropColumn($column);
        });
    }
};
