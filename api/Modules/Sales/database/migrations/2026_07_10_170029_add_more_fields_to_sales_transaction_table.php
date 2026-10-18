<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'business_date')) {
                $table->date('business_date')->nullable()->index()->after('transaction_date');
            }

            if (!Schema::hasColumn('sales_transactions', 'trading_day_id')) {
                $table->uuid('trading_day_id')->nullable()->index()->after('business_date');
            }
        });

        if (Schema::hasTable('trading_days') && Schema::hasColumn('sales_transactions', 'business_date') && Schema::hasColumn('sales_transactions', 'trading_day_id')) {
            DB::statement('
                UPDATE sales_transactions st
                JOIN trading_days td ON td.id = st.trading_day_id
                SET st.business_date = DATE(td.trading_date)
                WHERE st.trading_day_id IS NOT NULL
                  AND st.business_date IS NULL
                  AND td.trading_date IS NOT NULL
            ');
        }

        if (Schema::hasColumn('sales_transactions', 'metadata') && Schema::hasColumn('sales_transactions', 'business_date')) {
            DB::statement('
                UPDATE sales_transactions
                SET business_date = JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.business_date"))
                WHERE business_date IS NULL
                  AND metadata IS NOT NULL
                  AND JSON_VALID(metadata)
                  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.business_date")) IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.business_date")) <> ""
            ');
        }
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('sales_transactions', 'trading_day_id')) {
                $table->dropColumn('trading_day_id');
            }

            if (Schema::hasColumn('sales_transactions', 'business_date')) {
                $table->dropColumn('business_date');
            }
        });
    }
};
