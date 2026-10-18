<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->index();
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->nullable()->index();
            $table->string('grn_number', 80)->index();
            $table->date('received_date')->index();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->enum('status', ['draft','posted','cancelled'])->default('posted')->index();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'grn_number']);
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('purchase_receipt_id', 36)->index();
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('purchase_order_line_id', 36)->nullable()->index();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->index();
            $table->char('supplier_id', 36)->nullable()->index(); // line supplier can differ
            $table->char('product_id', 36)->index();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('purchase_receipt_id')->references('id')->on('purchase_receipts')->cascadeOnDelete();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->index();
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('purchase_receipt_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->nullable()->index();
            $table->string('return_number', 80)->index();
            $table->date('return_date')->index();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->enum('status', ['draft','posted','cancelled'])->default('posted')->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'return_number']);
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('purchase_return_id', 36)->index();
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('purchase_order_line_id', 36)->nullable()->index();
            $table->char('purchase_receipt_id', 36)->nullable()->index();
            $table->char('purchase_receipt_line_id', 36)->nullable()->index();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->index();
            $table->char('supplier_id', 36)->nullable()->index();
            $table->char('product_id', 36)->index();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->cascadeOnDelete();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->index();
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('purchase_receipt_id', 36)->nullable()->index();
            $table->string('invoice_number', 120)->index();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('balance_due', 18, 4)->default(0);
            $table->enum('status', ['draft','posted','partially_paid','paid','cancelled'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'supplier_id', 'invoice_number']);
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('supplier_invoice_id', 36)->index();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->nullable()->index(); // line supplier can differ when consolidating invoices
            $table->char('purchase_order_id', 36)->nullable()->index();
            $table->char('purchase_order_line_id', 36)->nullable()->index();
            $table->char('purchase_receipt_id', 36)->nullable()->index();
            $table->char('purchase_receipt_line_id', 36)->nullable()->index();
            $table->char('product_id', 36)->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
    }
};
