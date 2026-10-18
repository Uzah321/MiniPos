<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Ownership / Context
            |--------------------------------------------------------------------------
            */
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('sales_transaction_id');

            /*
            |--------------------------------------------------------------------------
            | Product Info
            |--------------------------------------------------------------------------
            */
            $table->uuid('product_id');
            $table->uuid('product_price_id')->nullable();
            $table->uuid('price_level_id')->nullable();

            $table->integer('line_no')->default(1);

            $table->string('line_name');
            $table->string('product_code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Quantity / Price In Sale Currency
            |--------------------------------------------------------------------------
            */
            $table->decimal('line_quantity', 18, 4);
            $table->decimal('line_price', 18, 4);
            $table->decimal('line_subtotal', 18, 4)->default(0);
            $table->decimal('line_discount', 18, 4)->default(0);
            $table->decimal('line_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Base Currency Values
            |--------------------------------------------------------------------------
            */
            $table->decimal('base_line_price', 18, 4)->default(0);
            $table->decimal('base_line_subtotal', 18, 4)->default(0);
            $table->decimal('base_line_discount', 18, 4)->default(0);
            $table->decimal('base_line_tax', 18, 4)->default(0);
            $table->decimal('base_line_total', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */
            $table->string('currency_code', 20);
            $table->string('base_currency_code', 20)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Extra Data
            |--------------------------------------------------------------------------
            */
            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('sales_transaction_id');
            $table->index('product_id');
            $table->index('product_price_id');
            $table->index('price_level_id');
            $table->index('line_no');
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

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();

            // Enable only if product_prices table exists and id is uuid.
             $table->foreign('product_price_id')
                 ->references('id')
                 ->on('product_prices')
                 ->nullOnDelete();

            // Enable only if price_levels table exists and id is uuid.
             $table->foreign('price_level_id')
                 ->references('id')
                 ->on('price_levels')
                 ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_lines');
    }
};
