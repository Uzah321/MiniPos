<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        Schema::create('credit_customer_payment_allocations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index('ccpa_company_idx');
            $table->char('branch_id', 36)->nullable()->index('ccpa_branch_idx');
            $table->char('credit_customer_id', 36)->index('ccpa_customer_idx');

            $table->char('credit_customer_payment_id', 36)->index('ccpa_payment_idx');
            $table->char('payment_ledger_id', 36)->nullable()->index('ccpa_pay_ledger_idx');
            $table->char('sale_ledger_id', 36)->index('ccpa_sale_ledger_idx');
            $table->char('sales_transaction_id', 36)->nullable()->index('ccpa_sale_tx_idx');

            $table->string('document_number', 100)->nullable()->index('ccpa_doc_idx');
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');

            $table->char('created_by', 36)->nullable()->index('ccpa_created_by_idx');
            $table->timestamps();

            $table->index(['credit_customer_id', 'sale_ledger_id'], 'ccpa_customer_sale_idx');
            $table->index(['credit_customer_payment_id', 'sale_ledger_id'], 'ccpa_payment_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_payment_allocations');
    }
};
