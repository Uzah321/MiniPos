<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'trading_day_id')) {
                $table->uuid('trading_day_id')->nullable()->after('branch_id');
            }

            if (!Schema::hasColumn('sales_transactions', 'business_date')) {
                $table->date('business_date')->nullable()->after('trading_day_id');
            }
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            try {
                $table->index(['company_id', 'branch_id', 'business_date'], 'sales_txn_company_branch_business_date_idx');
            } catch (\Throwable $e) {
                // index may already exist on some installations
            }

            try {
                $table->index('trading_day_id', 'sales_txn_trading_day_id_idx');
            } catch (\Throwable $e) {
                // index may already exist on some installations
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            try { $table->dropIndex('sales_txn_company_branch_business_date_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('sales_txn_trading_day_id_idx'); } catch (\Throwable $e) {}

            if (Schema::hasColumn('sales_transactions', 'business_date')) {
                $table->dropColumn('business_date');
            }

            if (Schema::hasColumn('sales_transactions', 'trading_day_id')) {
                $table->dropColumn('trading_day_id');
            }
        });
    }
};
