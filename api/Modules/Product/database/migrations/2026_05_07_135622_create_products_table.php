<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('category_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('parent_product_id')->nullable();
            $table->uuid('base_uom_id');

            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->text('description')->nullable();

            $table->enum('product_type', ['simple', 'parent', 'child', 'service'])->default('simple');

            $table->boolean('stock_tracking_enabled')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('allow_discount')->default(true);
            $table->boolean('taxable')->default(false);
            $table->uuid('tax_id')->nullable();

            $table->decimal('reorder_level', 15, 4)->default(0);
            $table->decimal('minimum_stock', 15, 4)->default(0);
            $table->decimal('maximum_stock', 15, 4)->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('product_categories')->nullOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->foreign('parent_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('base_uom_id')->references('id')->on('uoms')->restrictOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
