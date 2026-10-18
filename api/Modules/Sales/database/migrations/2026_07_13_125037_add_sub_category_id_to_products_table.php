<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'order_number')) {
                $table->string('order_number', 50)->nullable()->after('invoice_number');
            }
            if (!Schema::hasColumn('sales_transactions', 'order_sequence')) {
                $table->unsignedInteger('order_sequence')->nullable()->after('order_number');
            }
            if (!Schema::hasColumn('sales_transactions', 'order_business_date')) {
                $table->date('order_business_date')->nullable()->after('business_date');
            }
            if (!Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
                $table->string('kitchen_print_status', 30)->nullable()->after('status');
            }
            if (!Schema::hasColumn('sales_transactions', 'kitchen_printed_at')) {
                $table->timestamp('kitchen_printed_at')->nullable()->after('kitchen_print_status');
            }
        });

        Schema::table('receipt_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('receipt_lines', 'order_number')) {
                $table->string('order_number', 50)->nullable()->after('line_no');
            }
            if (!Schema::hasColumn('receipt_lines', 'kitchen_print_status')) {
                $table->string('kitchen_print_status', 30)->nullable()->after('order_number');
            }
            if (!Schema::hasColumn('receipt_lines', 'kitchen_printed_at')) {
                $table->timestamp('kitchen_printed_at')->nullable()->after('kitchen_print_status');
            }
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'order_business_date'], 'sales_order_business_date_idx');
            $table->unique(['company_id', 'branch_id', 'till_id', 'order_business_date', 'order_sequence'], 'sales_order_unique_till_day_seq');
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            try { $table->dropUnique('sales_order_unique_till_day_seq'); } catch (\Throwable $e) {}
            try { $table->dropIndex('sales_order_business_date_idx'); } catch (\Throwable $e) {}
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            foreach (['kitchen_printed_at', 'kitchen_print_status', 'order_business_date', 'order_sequence', 'order_number'] as $column) {
                if (Schema::hasColumn('sales_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('receipt_lines', function (Blueprint $table) {
            foreach (['kitchen_printed_at', 'kitchen_print_status', 'order_number'] as $column) {
                if (Schema::hasColumn('receipt_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
