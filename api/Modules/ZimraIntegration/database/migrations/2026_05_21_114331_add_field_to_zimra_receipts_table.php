<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zimra_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('zimra_receipts', 'original_zimra_receipt_id')) {
                $table->char('original_zimra_receipt_id', 36)->nullable()->after('sales_transaction_id')->index();
            }

            if (!Schema::hasColumn('zimra_receipts', 'original_sales_transaction_id')) {
                $table->char('original_sales_transaction_id', 36)->nullable()->after('original_zimra_receipt_id')->index();
            }

            if (!Schema::hasColumn('zimra_receipts', 'receipt_notes')) {
                $table->text('receipt_notes')->nullable()->after('receipt_currency');
            }

            if (!Schema::hasColumn('zimra_receipts', 'credit_debit_note')) {
                $table->json('credit_debit_note')->nullable()->after('receipt_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zimra_receipts', function (Blueprint $table) {
            foreach ([
                         'original_zimra_receipt_id',
                         'original_sales_transaction_id',
                         'receipt_notes',
                         'credit_debit_note',
                     ] as $column) {
                if (Schema::hasColumn('zimra_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
