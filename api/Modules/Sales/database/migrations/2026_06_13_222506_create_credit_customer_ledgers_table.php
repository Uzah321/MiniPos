<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_customer_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('credit_customer_id');
            $table->uuid('sales_transaction_id')->nullable();
            $table->uuid('receipt_line_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->uuid('shift_session_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('cashier_id')->nullable();
            $table->string('entry_type', 40); // sale, payment, adjustment_debit, adjustment_credit, reversal
            $table->string('document_number', 100)->nullable();
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->decimal('debit_amount', 18, 4)->default(0);
            $table->decimal('credit_amount', 18, 4)->default(0);
            $table->decimal('balance_after', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->string('base_currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('credit_customer_id')
                ->references('id')
                ->on('credit_customers')
                ->cascadeOnDelete();

            $table->index(['company_id', 'branch_id', 'credit_customer_id'], 'credit_customer_ledger_account_idx');
            $table->index(['sales_transaction_id']);
            $table->index(['payment_id']);
            $table->index(['shift_session_id']);
            $table->index(['entry_type', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_ledger');
    }
};
