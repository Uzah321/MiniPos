<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        Schema::table('credit_customer_payment_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_customer_payment_allocations', 'payment_ledger_id')) {
                $table->char('payment_ledger_id', 36)->nullable()->after('credit_customer_payment_id');
            }
            if (!Schema::hasColumn('credit_customer_payment_allocations', 'sale_ledger_id')) {
                $table->char('sale_ledger_id', 36)->nullable()->after('payment_ledger_id');
            }
            if (!Schema::hasColumn('credit_customer_payment_allocations', 'amount')) {
                $table->decimal('amount', 18, 4)->default(0)->after('document_number');
            }
            if (!Schema::hasColumn('credit_customer_payment_allocations', 'currency_code')) {
                $table->string('currency_code', 10)->default('USD')->after('amount');
            }
        });
    }

    public function down(): void
    {
        // Safe patch migration: no destructive rollback.
    }
};
