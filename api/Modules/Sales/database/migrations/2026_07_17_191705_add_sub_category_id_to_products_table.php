<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            Schema::create('credit_customer_payment_allocations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('credit_customer_id')->index();
                $table->uuid('credit_customer_payment_id')->index();
                $table->uuid('sales_transaction_id')->index();
                $table->uuid('receipt_line_id')->nullable()->index();
                $table->uuid('product_id')->nullable()->index();
                $table->string('invoice_number')->nullable()->index();
                $table->string('payment_number')->nullable()->index();
                $table->date('payment_date')->index();
                $table->string('currency_code', 10)->index();
                $table->string('base_currency_code', 10)->nullable();
                $table->decimal('allocated_amount', 18, 4)->default(0);
                $table->decimal('base_allocated_amount', 18, 4)->default(0);
                $table->string('allocation_status', 30)->default('posted')->index();
                $table->timestamp('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->string('reversal_reason', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'payment_date']);
                $table->index(['sales_transaction_id', 'allocation_status']);
                $table->index(['receipt_line_id', 'allocation_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_payment_allocations');
    }
};
