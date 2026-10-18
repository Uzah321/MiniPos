<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('back_stock_month_end_counts')) {
            Schema::create('back_stock_month_end_counts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->date('month_end_date');
                $table->string('stock_area', 30)->default('back');
                $table->string('status', 40)->default('open');
                $table->uuid('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->integer('total_lines')->default(0);
                $table->integer('variance_lines')->default(0);
                $table->decimal('expected_total_quantity', 18, 6)->default(0);
                $table->decimal('counted_total_quantity', 18, 6)->default(0);
                $table->decimal('variance_total_quantity', 18, 6)->default(0);
                $table->decimal('variance_total_value', 18, 6)->default(0);
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('company_id', 'bsmec_company_idx');
                $table->index('branch_id', 'bsmec_branch_idx');
                $table->index('month_end_date', 'bsmec_month_idx');
                $table->index('stock_area', 'bsmec_area_idx');
                $table->index('status', 'bsmec_status_idx');
                $table->index('started_by', 'bsmec_started_by_idx');
                $table->index('closed_by', 'bsmec_closed_by_idx');
                $table->unique(['company_id', 'branch_id', 'month_end_date', 'stock_area'], 'bsmec_unique_month_area');
            });
        }

        if (!Schema::hasTable('back_stock_month_end_count_lines')) {
            Schema::create('back_stock_month_end_count_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('back_stock_month_end_count_id');
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->uuid('product_id');
                $table->string('product_code')->nullable();
                $table->string('product_sku')->nullable();
                $table->string('product_name');
                $table->string('product_type')->nullable();
                $table->string('uom_code', 50)->nullable();
                $table->string('stock_area', 30)->default('back');
                $table->decimal('expected_quantity', 18, 6)->default(0);
                $table->decimal('counted_quantity', 18, 6)->default(0);
                $table->decimal('variance_quantity', 18, 6)->default(0);
                $table->decimal('unit_cost', 18, 6)->default(0);
                $table->decimal('variance_value', 18, 6)->default(0);
                $table->string('currency_code', 12)->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('back_stock_month_end_count_id', 'bsmec_lines_count_idx');
                $table->index('company_id', 'bsmec_lines_company_idx');
                $table->index('branch_id', 'bsmec_lines_branch_idx');
                $table->index('product_id', 'bsmec_lines_product_idx');
                $table->index('product_code', 'bsmec_lines_code_idx');
                $table->index('product_sku', 'bsmec_lines_sku_idx');
                $table->index('product_type', 'bsmec_lines_type_idx');
                $table->index('stock_area', 'bsmec_lines_area_idx');
                $table->unique(['back_stock_month_end_count_id', 'product_id'], 'bsmec_lines_unique_product');
            });
        }

        if (!Schema::hasTable('hospitality_month_end_reports')) {
            Schema::create('hospitality_month_end_reports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->date('month_end_date');
                $table->date('date_from');
                $table->date('date_to');
                $table->string('status', 40)->default('closed');
                $table->decimal('sales_total', 18, 6)->default(0);
                $table->json('sales_currency_summary')->nullable();
                $table->decimal('front_stock_variance_total_value', 18, 6)->default(0);
                $table->decimal('back_stock_variance_total_value', 18, 6)->default(0);
                $table->longText('report_payload')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('company_id', 'hmer_company_idx');
                $table->index('branch_id', 'hmer_branch_idx');
                $table->index('month_end_date', 'hmer_month_idx');
                $table->index('date_from', 'hmer_from_idx');
                $table->index('date_to', 'hmer_to_idx');
                $table->index('status', 'hmer_status_idx');
                $table->index('closed_by', 'hmer_closed_by_idx');
                $table->unique(['company_id', 'branch_id', 'month_end_date'], 'hmer_unique_month');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitality_month_end_reports');
        Schema::dropIfExists('back_stock_month_end_count_lines');
        Schema::dropIfExists('back_stock_month_end_counts');
    }
};
