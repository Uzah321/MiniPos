<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Ownership / Context
            |--------------------------------------------------------------------------
            */
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_id')->nullable();
            $table->uuid('cashier_id')->nullable();
            $table->uuid('user_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Sale Identity
            |--------------------------------------------------------------------------
            */
            $table->string('invoice_number')->unique();
            $table->dateTime('transaction_date')->nullable();

            $table->string('document_type')->default('invoice');
            // invoice, receipt, credit_note, debit_note

            $table->string('sale_type')->default('sale');
            // sale, return, refund, void

            $table->string('status')->default('completed');
            // draft, parked, completed, voided, refunded

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */
            $table->string('currency_code', 20);
            $table->string('base_currency_code', 20)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);

            /*
            |--------------------------------------------------------------------------
            | Totals In Sale Currency
            |--------------------------------------------------------------------------
            */
            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Totals In Base Currency
            |--------------------------------------------------------------------------
            */
            $table->decimal('base_subtotal_amount', 18, 4)->default(0);
            $table->decimal('base_discount_amount', 18, 4)->default(0);
            $table->decimal('base_tax_amount', 18, 4)->default(0);
            $table->decimal('base_total_amount', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Payment Summary
            |--------------------------------------------------------------------------
            */
            $table->uuid('payment_type_id')->nullable();
            $table->string('payment_method')->nullable();

            $table->decimal('amount_tendered', 18, 4)->default(0);
            $table->decimal('change_amount', 18, 4)->default(0);

            // Keep this if old frontend/backend uses "change"
            $table->decimal('change', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Customer / Extra Info
            |--------------------------------------------------------------------------
            */
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_tax_number')->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Receipt / Printing
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_printed')->default(false);
            $table->timestamp('printed_at')->nullable();
            $table->uuid('printed_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('till_id');
            $table->index('shift_id');
            $table->index('cashier_id');
            $table->index('user_id');
            $table->index('payment_type_id');
            $table->index('invoice_number');
            $table->index('transaction_date');
            $table->index('status');
            $table->index('currency_code');

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->cascadeOnDelete();

            $table->foreign('till_id')
                ->references('id')
                ->on('pos_tills')
                ->nullOnDelete();

            // Only enable this if your shifts table exists with uuid id.
             $table->foreign('shift_id')
                 ->references('id')
                 ->on('shiftconfigurations')
                 ->nullOnDelete();

            // Only enable this if your payment_types table uses uuid id.
             $table->foreign('payment_type_id')
                 ->references('id')
                 ->on('payment_types')
                 ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transactions');
    }
};
