<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_line_taxes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Ownership / Context
            |--------------------------------------------------------------------------
            */
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Sale / Line Reference
            |--------------------------------------------------------------------------
            */
            $table->uuid('sales_transaction_id');
            $table->uuid('receipt_line_id');

            /*
            |--------------------------------------------------------------------------
            | Tax Details
            |--------------------------------------------------------------------------
            */
            $table->uuid('tax_id')->nullable();

            $table->string('tax_code')->nullable();
            $table->decimal('tax_percent', 8, 4)->default(0);

            // true = price already includes tax
            // false = tax is added on top
            $table->boolean('is_inclusive')->default(true);

            // keep this even for normal sales so later fiscalisation can reuse it
            $table->boolean('obliged_to_zimra')->default(false);

            /*
            |--------------------------------------------------------------------------
            | Amounts
            |--------------------------------------------------------------------------
            */
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('sales_amount_with_tax', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */
            $table->string('currency_code', 20);
            $table->string('base_currency_code', 20)->nullable();

            $table->decimal('base_tax_amount', 18, 4)->default(0);
            $table->decimal('base_sales_amount_with_tax', 18, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | Extra
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
            $table->index('receipt_line_id');
            $table->index('tax_id');
            $table->index('tax_code');
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

            $table->foreign('receipt_line_id')
                ->references('id')
                ->on('receipt_lines')
                ->cascadeOnDelete();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Optional Tax FK
            |--------------------------------------------------------------------------
            | Enable this only if your taxes table exists and uses uuid ids.
            |--------------------------------------------------------------------------
            */
            // $table->foreign('tax_id')
            //     ->references('id')
            //     ->on('taxes')
            //     ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_line_taxes');
    }
};
