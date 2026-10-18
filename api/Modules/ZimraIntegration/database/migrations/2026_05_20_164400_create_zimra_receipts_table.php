<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zimra_receipts')) {
            return;
        }

        Schema::create('zimra_receipts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36);
            $table->char('branch_id', 36)->nullable();
            $table->char('fiscal_device_id', 36);
            $table->char('fiscal_day_id', 36);
            $table->char('sales_transaction_id', 36);
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('fiscal_day_no');
            $table->unsignedBigInteger('receipt_counter');
            $table->unsignedBigInteger('receipt_global_no');
            $table->string('invoice_no');
            $table->string('receipt_type')->default('FiscalInvoice');
            $table->string('receipt_currency', 10);
            $table->text('receipt_hash')->nullable();
            $table->text('receipt_signature')->nullable();
            $table->string('fdms_receipt_id')->nullable();
            $table->string('operation_id')->nullable();
            $table->text('qr_url')->nullable();
            $table->text('qr_code_data')->nullable();
            $table->text('qr_string')->nullable();
            $table->json('payload');
            $table->json('response')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'receipt_global_no']);
            $table->unique(['sales_transaction_id']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['fiscal_day_id', 'status']);
//            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
//            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
//            $table->foreign('fiscal_device_id')->references('id')->on('fiscal_devices')->cascadeOnDelete();
//            $table->foreign('fiscal_day_id')->references('id')->on('fiscal_days')->cascadeOnDelete();
//            $table->foreign('sales_transaction_id')->references('id')->on('sales_transactions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimra_receipts');
    }
};
