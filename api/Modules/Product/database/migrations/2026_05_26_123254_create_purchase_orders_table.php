<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->nullable()->index();
            $table->string('po_number', 80)->index();
            $table->date('order_date')->nullable()->index();
            $table->date('expected_date')->nullable();
            $table->string('currency_code', 10)->default('USD')->index();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('shipping_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->decimal('base_grand_total', 18, 4)->default(0);
            $table->enum('status', ['draft','submitted','approved','partially_received','received','cancelled','closed'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable()->index();
            $table->char('approved_by', 36)->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'po_number']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('purchase_order_id', 36)->index();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('supplier_id', 36)->nullable()->index(); // line supplier can differ from header supplier
            $table->char('product_id', 36)->index();
            $table->string('product_name')->nullable();
            $table->string('product_code', 100)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('ordered_quantity', 18, 4)->default(0);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->json('tax_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
