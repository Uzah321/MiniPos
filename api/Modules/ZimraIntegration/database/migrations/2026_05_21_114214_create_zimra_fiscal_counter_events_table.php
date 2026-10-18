<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zimra_fiscal_counter_events')) {
            return;
        }

        Schema::create('zimra_fiscal_counter_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();

            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('fiscal_device_id', 36)->index();
            $table->char('fiscal_day_id', 36)->index();
            $table->char('zimra_receipt_id', 36)->index();
            $table->char('sales_transaction_id', 36)->index();

            $table->string('receipt_type', 40);
            $table->string('receipt_currency', 3);

            $table->string('fiscal_counter_type', 60);
            $table->string('fiscal_counter_currency', 3);
            $table->integer('fiscal_counter_tax_id')->nullable();
            $table->decimal('fiscal_counter_tax_percent', 8, 4)->nullable();
            $table->string('fiscal_counter_money_type', 60)->nullable();
            $table->decimal('fiscal_counter_value', 21, 4)->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
             * Idempotency: one receipt may only post the same counter key once.
             */
            $table->unique([
                'zimra_receipt_id',
                'fiscal_counter_type',
                'fiscal_counter_currency',
                'fiscal_counter_tax_id',
                'fiscal_counter_tax_percent',
                'fiscal_counter_money_type',
            ], 'zimra_counter_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimra_fiscal_counter_events');
    }
};
