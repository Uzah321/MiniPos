<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_stock_daily_closing_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('front_stock_daily_closing_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');

            $table->string('product_code')->nullable();
            $table->string('product_sku')->nullable();
            $table->string('product_name');
            $table->string('product_type')->nullable();
            $table->string('uom_code')->nullable();
            $table->string('stock_area', 30)->default('front');

            $table->decimal('expected_quantity', 18, 6)->default(0);
            $table->decimal('counted_quantity', 18, 6)->default(0);
            $table->decimal('variance_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('variance_value', 18, 6)->default(0);

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('front_stock_daily_closing_id', 'fsdcl_closing_id_idx');
            $table->index('company_id', 'fsdcl_company_id_idx');
            $table->index('branch_id', 'fsdcl_branch_id_idx');
            $table->index('product_id', 'fsdcl_product_id_idx');
            $table->index('product_type', 'fsdcl_product_type_idx');
            $table->index('stock_area', 'fsdcl_stock_area_idx');

            $table->foreign('front_stock_daily_closing_id', 'fsdcl_closing_id_fk')
                ->references('id')
                ->on('front_stock_daily_closings')
                ->cascadeOnDelete();

            $table->unique(
                ['front_stock_daily_closing_id', 'product_id'],
                'fsdcl_closing_product_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('front_stock_daily_closing_lines');
    }
};
