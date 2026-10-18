<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zimra_fiscal_counters')) {
            return;
        }

        Schema::create('zimra_fiscal_counters', function (Blueprint $table) {
            $table->char('id', 36)->primary();

            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('fiscal_device_id', 36)->index();
            $table->char('fiscal_day_id', 36)->index();

            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('fiscal_day_no')->index();

            /*
             * FDMS values:
             * SaleByTax
             * SaleTaxByTax
             * CreditNoteByTax
             * CreditNoteTaxByTax
             * DebitNoteByTax
             * DebitNoteTaxByTax
             * BalanceByMoneyType
             */
            $table->string('fiscal_counter_type', 60);
            $table->string('fiscal_counter_currency', 3);

            /*
             * For *ByTax counters.
             * fiscal_counter_tax_id is used for payload and for ordering.
             * fiscal_counter_tax_percent is used in the signature string.
             * Exempt may have null percent.
             */
            $table->integer('fiscal_counter_tax_id')->nullable();
            $table->decimal('fiscal_counter_tax_percent', 8, 4)->nullable();

            /*
             * For BalanceByMoneyType.
             */
            $table->string('fiscal_counter_money_type', 60)->nullable();

            $table->decimal('fiscal_counter_value', 21, 4)->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique([
                'fiscal_day_id',
                'fiscal_counter_type',
                'fiscal_counter_currency',
                'fiscal_counter_tax_id',
                'fiscal_counter_tax_percent',
                'fiscal_counter_money_type',
            ], 'zimra_counter_unique');

            $table->index([
                'fiscal_day_id',
                'fiscal_counter_type',
                'fiscal_counter_currency',
            ], 'zimra_counter_day_type_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimra_fiscal_counters');
    }
};
