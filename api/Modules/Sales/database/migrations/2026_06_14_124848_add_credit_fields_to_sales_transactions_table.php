<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_transaction_payments')) {
            return;
        }

        if (!Schema::hasColumn('sales_transaction_payments', 'sales_transaction_id')) {
            return;
        }

        /*
         * Credit customer account payments are not POS sales, so they do not have
         * a sales_transaction_id. They still need to be stored in this table so
         * cashup/payment reports can see that money was received during the shift.
         */
        DB::statement('ALTER TABLE sales_transaction_payments MODIFY sales_transaction_id CHAR(36) NULL');
    }

    public function down(): void
    {
        // Do not revert to NOT NULL automatically because historical credit payments
        // may already contain NULL sales_transaction_id values.
    }
};
