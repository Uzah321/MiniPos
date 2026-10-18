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
            $table->uuid('id')->primary();

            $table->uuid('company_id')->index('ccpa_company_idx');
            $table->uuid('branch_id')->nullable()->index('ccpa_branch_idx');
            $table->uuid('credit_customer_id')->index('ccpa_customer_idx');
            $table->uuid('credit_customer_payment_id')->index('ccpa_payment_idx');
            $table->uuid('sales_transaction_id')->nullable()->index('ccpa_sale_idx');
            $table->uuid('ledger_id')->nullable()->index('ccpa_ledger_idx');

            $table->string('document_number', 100)->nullable()->index('ccpa_doc_idx');
            $table->decimal('allocated_amount', 18, 4)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->decimal('base_allocated_amount', 18, 4)->default(0);
            $table->string('base_currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);

            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable()->index('ccpa_created_by_idx');
            $table->timestamps();

            $table->index(['credit_customer_id', 'sales_transaction_id'], 'ccpa_customer_sale_idx');
            $table->index(['credit_customer_payment_id', 'sales_transaction_id'], 'ccpa_payment_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_payment_allocations');
    }
};
