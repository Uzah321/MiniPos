<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_customers')) {
            if (Schema::hasColumn('credit_customers', 'current_balance')) {
                DB::statement('ALTER TABLE `credit_customers` MODIFY `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0');
            }

            if (Schema::hasColumn('credit_customers', 'available_credit')) {
                DB::statement('ALTER TABLE `credit_customers` MODIFY `available_credit` DECIMAL(18,4) NOT NULL DEFAULT 0');
            }
        }

        if (Schema::hasTable('credit_customer_ledger') && Schema::hasColumn('credit_customer_ledger', 'balance_after')) {
            DB::statement('ALTER TABLE `credit_customer_ledger` MODIFY `balance_after` DECIMAL(18,4) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        // Keep signed balances. Credit customer accounts need negative balances for prepaid credit.
    }
};
