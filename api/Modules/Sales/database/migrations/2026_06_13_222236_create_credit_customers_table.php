<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('account_number', 60);
            $table->string('customer_code', 60)->nullable();
            $table->string('name');
            $table->string('trading_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('current_balance', 18, 4)->default(0);
            $table->decimal('available_credit', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->integer('payment_terms_days')->default(30);
            $table->boolean('allow_credit_sales')->default(false);
            $table->boolean('requires_po_number')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('status', 30)->default('pending'); // pending, approved, suspended, blocked, inactive
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('blocked_by')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->string('block_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'account_number']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customers');
    }
};
