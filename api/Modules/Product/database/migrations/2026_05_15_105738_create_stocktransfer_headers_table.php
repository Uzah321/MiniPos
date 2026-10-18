<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_transfer_headers')) {
            Schema::create('stock_transfer_headers', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();

                $table->string('transfer_no')->index();
                $table->date('transfer_date')->index();

                $table->char('from_branch_id', 36)->index();
                $table->char('to_branch_id', 36)->index();

                $table->enum('status', [
                    'draft',
                    'requested',
                    'approved',
                    'dispatched',
                    'partially_received',
                    'received',
                    'cancelled',
                    'rejected',
                ])->default('draft')->index();

                $table->decimal('total_quantity_requested', 18, 4)->default(0);
                $table->decimal('total_quantity_approved', 18, 4)->default(0);
                $table->decimal('total_quantity_dispatched', 18, 4)->default(0);
                $table->decimal('total_quantity_received', 18, 4)->default(0);
                $table->decimal('total_quantity_variance', 18, 4)->default(0);

                $table->decimal('total_cost_value', 18, 4)->default(0);
                $table->decimal('total_selling_value', 18, 4)->default(0);
                $table->decimal('total_variance_cost_value', 18, 4)->default(0);
                $table->decimal('total_variance_selling_value', 18, 4)->default(0);

                $table->string('cost_currency', 10)->nullable();
                $table->string('selling_currency', 10)->nullable();

                $table->text('request_notes')->nullable();
                $table->text('approval_notes')->nullable();
                $table->text('dispatch_notes')->nullable();
                $table->text('receiving_notes')->nullable();
                $table->text('cancel_notes')->nullable();

                $table->char('requested_by', 36)->nullable();
                $table->timestamp('requested_at')->nullable();

                $table->char('approved_by', 36)->nullable();
                $table->timestamp('approved_at')->nullable();

                $table->char('dispatched_by', 36)->nullable();
                $table->timestamp('dispatched_at')->nullable();

                $table->char('received_by', 36)->nullable();
                $table->timestamp('received_at')->nullable();

                $table->char('cancelled_by', 36)->nullable();
                $table->timestamp('cancelled_at')->nullable();

                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'transfer_no'], 'stock_transfer_no_unique');
            });
        }

        if (!Schema::hasTable('stock_transfer_lines')) {
            Schema::create('stock_transfer_lines', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('stock_transfer_id', 36)->index();
                $table->char('company_id', 36)->index();

                $table->char('product_id', 36)->index();
                $table->string('product_name');
                $table->string('code')->nullable();
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->string('category_name')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('uom_name')->nullable();

                $table->decimal('quantity_requested', 18, 4)->default(0);
                $table->decimal('quantity_approved', 18, 4)->default(0);
                $table->decimal('quantity_dispatched', 18, 4)->default(0);
                $table->decimal('quantity_received', 18, 4)->default(0);
                $table->decimal('quantity_variance', 18, 4)->default(0);

                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('cost_value', 18, 4)->default(0);
                $table->decimal('variance_cost_value', 18, 4)->default(0);

                $table->decimal('selling_price', 18, 4)->default(0);
                $table->decimal('selling_value', 18, 4)->default(0);
                $table->decimal('variance_selling_value', 18, 4)->default(0);

                $table->string('cost_currency', 10)->nullable();
                $table->string('selling_currency', 10)->nullable();

                $table->text('line_notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['stock_transfer_id', 'product_id'], 'stock_transfer_line_product_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfer_headers');
    }
};
