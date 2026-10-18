<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('stock_transfer_id')->index();
            $table->uuid('stock_transfer_line_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->uuid('source_warehouse_id')->nullable()->index();
            $table->uuid('source_location_id')->nullable()->index();
            $table->uuid('destination_warehouse_id')->nullable()->index();
            $table->uuid('destination_location_id')->nullable()->index();
            $table->decimal('quantity_dispatched', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('status', 30)->default('in_transit')->index();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();
        });

        Schema::create('inventory_recalls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('recall_number', 80);
            $table->string('title', 180);
            $table->text('reason');
            $table->string('severity', 20)->default('high')->index();
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('initiated_at');
            $table->uuid('initiated_by')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable()->index();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'recall_number']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('inventory_recall_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('recall_id')->index();
            $table->uuid('batch_id')->index();
            $table->uuid('product_id')->index();
            $table->decimal('quantity_at_recall', 18, 4)->default(0);
            $table->decimal('quantity_quarantined', 18, 4)->default(0);
            $table->string('status', 30)->default('recalled')->index();
            $table->timestamps();
            $table->unique(['company_id', 'recall_id', 'batch_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('recall_id')->references('id')->on('inventory_recalls')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->cascadeOnDelete();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('supplier_id')->index();
            $table->string('payment_number', 80);
            $table->date('payment_date')->index();
            $table->string('payment_method', 40);
            $table->string('currency_code', 10);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount', 18, 4);
            $table->decimal('base_amount', 18, 4);
            $table->string('reference', 160)->nullable();
            $table->string('status', 30)->default('posted')->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('reversed_by')->nullable()->index();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_number']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('supplier_payment_id')->index();
            $table->uuid('supplier_invoice_id')->index();
            $table->decimal('amount', 18, 4);
            $table->decimal('base_amount', 18, 4);
            $table->timestamps();
            $table->unique(['company_id', 'supplier_payment_id', 'supplier_invoice_id'], 'supplier_payment_allocation_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payments')->cascadeOnDelete();
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('inventory_recall_batches');
        Schema::dropIfExists('inventory_recalls');
        Schema::dropIfExists('inventory_transfer_allocations');
    }
};
