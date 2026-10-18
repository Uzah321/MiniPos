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

        if (!Schema::hasTable('sales_transactions')) {
            return;
        }

        /*
         * Existing sales from the earlier patch may have the correct business date
         * only inside metadata. Move that value into the reportable column.
         */
        if (Schema::hasColumn('sales_transactions', 'metadata') && Schema::hasColumn('sales_transactions', 'business_date')) {
            DB::statement(<<<'SQL'
                UPDATE sales_transactions
                SET business_date = DATE(JSON_UNQUOTE(JSON_EXTRACT(
                    CASE WHEN JSON_VALID(metadata) THEN metadata ELSE JSON_OBJECT() END,
                    '$.business_date'
                )))
                WHERE business_date IS NULL
                  AND metadata IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(
                    CASE WHEN JSON_VALID(metadata) THEN metadata ELSE JSON_OBJECT() END,
                    '$.business_date'
                  )) IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(
                    CASE WHEN JSON_VALID(metadata) THEN metadata ELSE JSON_OBJECT() END,
                    '$.business_date'
                  )) <> ''
            SQL);
        }

        /*
         * If trading_day_id exists but business_date is empty, recover the date from
         * trading_days. This is the safest historical repair.
         */
        if (Schema::hasTable('trading_days') && Schema::hasColumn('sales_transactions', 'business_date') && Schema::hasColumn('sales_transactions', 'trading_day_id')) {
            DB::statement(<<<'SQL'
                UPDATE sales_transactions st
                JOIN trading_days td ON td.id = st.trading_day_id
                SET st.business_date = DATE(td.trading_date)
                WHERE st.business_date IS NULL
                  AND st.trading_day_id IS NOT NULL
                  AND td.trading_date IS NOT NULL
            SQL);
        }

        /*
         * Last recovery for the exact hospitality issue:
         * if a sale was posted while a branch had an open trading day, but the sale
         * row never received business_date/trading_day_id, attach it to that open
         * trading day instead of the system date. This only targets completed rows
         * whose system transaction date is after the open trading date.
         */
        if (Schema::hasTable('trading_days') && Schema::hasColumn('sales_transactions', 'business_date') && Schema::hasColumn('sales_transactions', 'trading_day_id')) {
            DB::statement(<<<'SQL'
                UPDATE sales_transactions st
                JOIN trading_days td
                  ON td.company_id = st.company_id
                 AND td.branch_id = st.branch_id
                 AND td.status = 'open'
                SET st.business_date = DATE(td.trading_date),
                    st.trading_day_id = td.id
                WHERE st.business_date IS NULL
                  AND st.trading_day_id IS NULL
                  AND st.status = 'completed'
                  AND DATE(st.transaction_date) >= DATE(td.trading_date)
            SQL);
        }
    }

    public function down(): void
    {
        // Data repair migration. Do not delete business dates on rollback.
    }
};
