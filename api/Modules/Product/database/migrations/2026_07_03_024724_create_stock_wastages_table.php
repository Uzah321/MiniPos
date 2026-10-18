<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_wastages')) {
            Schema::create('stock_wastages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('till_id')->nullable()->index();
                $table->uuid('created_by')->nullable()->index();
                $table->date('wastage_date')->index();
                $table->string('stock_area', 30)->default('front')->index();
                $table->string('reference_no', 60)->nullable()->index();
                $table->string('status', 30)->default('posted')->index();
                $table->string('reason_code', 80)->nullable()->index();
                $table->text('notes')->nullable();
                $table->unsignedInteger('total_lines')->default(0);
                $table->decimal('total_quantity', 18, 6)->default(0);
                $table->decimal('total_cost', 18, 6)->default(0);
                $table->json('currency_totals')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('stock_wastage_lines')) {
            Schema::create('stock_wastage_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('stock_wastage_id')->index();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('product_id')->index();
                $table->string('product_code', 100)->nullable();
                $table->string('product_sku', 100)->nullable();
                $table->string('product_name', 255);
                $table->string('product_type', 100)->nullable();
                $table->string('uom_code', 50)->nullable();
                $table->string('stock_area', 30)->default('front')->index();
                $table->decimal('opening_quantity', 18, 6)->default(0);
                $table->decimal('wastage_quantity', 18, 6)->default(0);
                $table->decimal('closing_quantity', 18, 6)->default(0);
                $table->decimal('unit_cost', 18, 6)->default(0);
                $table->decimal('total_cost', 18, 6)->default(0);
                $table->string('currency_code', 10)->default('USD')->index();
                $table->string('reason_code', 80)->nullable()->index();
                $table->text('notes')->nullable();
                $table->uuid('stock_movement_id')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastage_lines');
        Schema::dropIfExists('stock_wastages');
    }
};
