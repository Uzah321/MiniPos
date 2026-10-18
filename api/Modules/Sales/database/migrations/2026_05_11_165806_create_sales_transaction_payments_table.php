<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_transaction_payments', function (Blueprint $table) {
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

            $table->uuid('sales_transaction_id');
            $table->uuid('payment_type_id');

            /*
            |--------------------------------------------------------------------------
            | Payment Info
            |--------------------------------------------------------------------------
            */
            $table->string('payment_name')->nullable();
            $table->string('money_code')->nullable();

            $table->string('currency_code', 20);
            $table->string('base_currency_code', 20)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);

            /*
            |--------------------------------------------------------------------------
            | Amounts
            |--------------------------------------------------------------------------
            */
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('base_amount', 18, 4)->default(0);

            $table->decimal('amount_tendered', 18, 4)->default(0);
            $table->decimal('change_amount', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | References
            |--------------------------------------------------------------------------
            */
            $table->string('reference')->nullable();
            $table->string('approval_code')->nullable();
            $table->string('card_last_four')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('till_id');
            $table->index('shift_id');
            $table->index('sales_transaction_id');
            $table->index('payment_type_id');
            $table->index('currency_code');

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreign('sales_transaction_id')
                ->references('id')
                ->on('sales_transactions')
                ->cascadeOnDelete();

            $table->foreign('till_id')
                ->references('id')
                ->on('pos_tills')
                ->nullOnDelete();

            // Enable only if your shifts table exists with uuid id.
             $table->foreign('shift_id')
                 ->references('id')
                 ->on('shiftconfigurations')
                 ->nullOnDelete();

            // Enable only if payment_types table uses uuid id.
             $table->foreign('payment_type_id')
                 ->references('id')
                 ->on('payment_types')
                 ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transaction_payments');
    }
};
