<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_customer_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('credit_customer_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_session_id')->nullable();
            $table->uuid('cashier_id')->nullable();
            $table->uuid('payment_type_id')->nullable();
            $table->string('payment_number', 100);
            $table->string('payment_name')->nullable();
            $table->string('money_code')->nullable();
            $table->decimal('amount', 18, 4);
            $table->decimal('base_amount', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->string('base_currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('reference')->nullable();
            $table->string('approval_code')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('posted'); // posted, reversed, voided
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('credit_customer_id')
                ->references('id')
                ->on('credit_customers')
                ->cascadeOnDelete();

            $table->unique(['company_id', 'payment_number']);
            $table->index(['company_id', 'branch_id', 'credit_customer_id'], 'credit_customer_payments_account_idx');
            $table->index(['shift_session_id']);
            $table->index(['payment_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_payments');
    }
};
