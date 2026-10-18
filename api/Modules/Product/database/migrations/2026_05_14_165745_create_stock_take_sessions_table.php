<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_take_sessions')) {
            Schema::create('stock_take_sessions', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->index();
                $table->string('stock_take_no')->index();
                $table->date('as_of_date')->index();
                $table->string('title')->nullable();
                $table->enum('status', ['draft', 'count_uploaded', 'variance_review', 'approved', 'posted', 'cancelled'])->default('draft')->index();

                $table->decimal('system_quantity', 18, 4)->default(0);
                $table->decimal('counted_quantity', 18, 4)->default(0);
                $table->decimal('variance_quantity', 18, 4)->default(0);
                $table->decimal('system_cost_value', 18, 4)->default(0);
                $table->decimal('counted_cost_value', 18, 4)->default(0);
                $table->decimal('variance_cost_value', 18, 4)->default(0);
                $table->decimal('system_selling_value', 18, 4)->default(0);
                $table->decimal('counted_selling_value', 18, 4)->default(0);
                $table->decimal('variance_selling_value', 18, 4)->default(0);

                $table->integer('line_count')->default(0);
                $table->integer('matched_count')->default(0);
                $table->integer('short_count')->default(0);
                $table->integer('over_count')->default(0);
                $table->integer('zero_count')->default(0);
                $table->integer('unmatched_count')->default(0);

                $table->string('cost_currency', 10)->nullable();
                $table->string('selling_currency', 10)->nullable();
                $table->json('filters')->nullable();
                $table->json('metadata')->nullable();

                $table->char('created_by', 36)->nullable();
                $table->char('uploaded_by', 36)->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->char('approved_by', 36)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->char('posted_by', 36)->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'branch_id', 'stock_take_no'], 'stk_session_no_unique');
            });
        }

        if (!Schema::hasTable('stock_take_lines')) {
            Schema::create('stock_take_lines', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('stock_take_session_id', 36)->index();
                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->index();
                $table->char('product_id', 36)->index();

                $table->string('product_name');
                $table->string('code')->nullable();
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->string('category_name')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('uom_name')->nullable();

                $table->decimal('system_quantity', 18, 4)->default(0);
                $table->decimal('counted_quantity', 18, 4)->default(0);
                $table->decimal('variance_quantity', 18, 4)->default(0);

                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('system_cost_value', 18, 4)->default(0);
                $table->decimal('counted_cost_value', 18, 4)->default(0);
                $table->decimal('variance_cost_value', 18, 4)->default(0);

                $table->decimal('selling_price', 18, 4)->default(0);
                $table->decimal('system_selling_value', 18, 4)->default(0);
                $table->decimal('counted_selling_value', 18, 4)->default(0);
                $table->decimal('variance_selling_value', 18, 4)->default(0);

                $table->string('cost_currency', 10)->nullable();
                $table->string('selling_currency', 10)->nullable();
                $table->enum('variance_type', ['matched', 'short', 'over', 'zero_count', 'unmatched'])->default('matched')->index();
                $table->text('count_notes')->nullable();
                $table->text('review_notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['stock_take_session_id', 'product_id'], 'stk_line_session_product_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_take_sessions');
    }
};
