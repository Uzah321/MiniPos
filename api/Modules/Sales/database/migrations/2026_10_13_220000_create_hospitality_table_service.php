<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitality_table_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('shop_id');
            $table->string('assignment_mode', 16)->default('auto');
            $table->boolean('require_table_for_dine_in')->default(true);
            $table->boolean('allow_cross_till_recall')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'shop_id'], 'hospitality_table_settings_scope_uq');
            $table->index(['shop_id', 'is_active'], 'hospitality_table_settings_shop_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });

        Schema::create('hospitality_tables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('shop_id');
            $table->string('code', 80);
            $table->string('name');
            $table->string('section', 120)->nullable();
            $table->string('table_type', 24)->default('dining');
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'code'], 'hospitality_tables_shop_code_uq');
            $table->index(
                ['company_id', 'branch_id', 'shop_id', 'table_type', 'is_active'],
                'hospitality_tables_scope_idx'
            );
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });

        Schema::create('hospitality_table_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('shop_id');
            $table->uuid('till_id');
            $table->uuid('last_till_id');
            $table->uuid('hospitality_table_id')->nullable();
            // MySQL permits multiple NULLs in a unique key. Active rows copy
            // hospitality_table_id here; terminal rows set it to NULL. This is
            // the database-level race guard against concurrent assignment.
            $table->uuid('active_table_id')->nullable()->unique('hospitality_active_table_uq');
            $table->string('client_request_id', 100);
            $table->string('service_type', 24);
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('content_revision')->default(1);
            $table->string('kitchen_status', 24)->default('pending');
            $table->unsignedBigInteger('last_kitchen_content_revision')->nullable();
            $table->unsignedInteger('guest_count')->default(1);
            $table->string('display_label')->nullable();
            $table->text('notes')->nullable();
            $table->char('cart_hash', 64);
            $table->char('currency_code', 3);
            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->uuid('opened_by');
            $table->uuid('last_updated_by');
            $table->uuid('sales_transaction_id')->nullable()->unique('hospitality_table_order_sale_uq');
            $table->uuid('cleared_by')->nullable();
            $table->uuid('manager_approval_id')->nullable()->unique('hospitality_table_order_approval_uq');
            $table->string('clear_reason', 1000)->nullable();
            // DATETIME avoids MariaDB's legacy implicit-default rules for
            // multiple non-null TIMESTAMP columns on the same table.
            $table->dateTime('opened_at');
            $table->dateTime('last_activity_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cleared_at')->nullable();
            $table->dateTime('kitchen_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'client_request_id'], 'hospitality_table_order_request_uq');
            $table->index(
                ['company_id', 'branch_id', 'shop_id', 'status', 'last_activity_at'],
                'hospitality_table_orders_scope_status_idx'
            );
            $table->index(['last_till_id', 'status'], 'hospitality_table_orders_till_status_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('till_id')->references('id')->on('pos_tills')->restrictOnDelete();
            $table->foreign('last_till_id')->references('id')->on('pos_tills')->restrictOnDelete();
            $table->foreign('hospitality_table_id')->references('id')->on('hospitality_tables')->nullOnDelete();
            $table->foreign('active_table_id')->references('id')->on('hospitality_tables')->restrictOnDelete();
            $table->foreign('sales_transaction_id')->references('id')->on('sales_transactions')->nullOnDelete();
        });

        Schema::create('hospitality_table_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('table_order_id');
            $table->uuid('product_id');
            $table->uuid('menu_item_channel_id')->nullable();
            $table->unsignedInteger('line_no');
            $table->string('line_name');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_subtotal', 18, 4)->default(0);
            $table->decimal('line_discount', 18, 4)->default(0);
            $table->decimal('line_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['table_order_id', 'line_no'], 'hospitality_table_order_line_no_uq');
            $table->index(['company_id', 'branch_id', 'product_id'], 'hospitality_table_order_lines_scope_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('table_order_id')->references('id')->on('hospitality_table_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('menu_item_channel_id')->references('id')->on('menu_item_channels')->nullOnDelete();
        });

        Schema::create('hospitality_table_order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('table_order_id');
            $table->uuid('till_id');
            $table->uuid('actor_id');
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->uuid('manager_approval_id')->nullable();
            $table->uuid('command_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['table_order_id', 'command_id'], 'hospitality_table_order_command_uq');
            $table->index(['company_id', 'branch_id', 'created_at'], 'hospitality_table_order_events_scope_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('table_order_id')->references('id')->on('hospitality_table_orders')->cascadeOnDelete();
            $table->foreign('till_id')->references('id')->on('pos_tills')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitality_table_order_events');
        Schema::dropIfExists('hospitality_table_order_lines');
        Schema::dropIfExists('hospitality_table_orders');
        Schema::dropIfExists('hospitality_tables');
        Schema::dropIfExists('hospitality_table_settings');
    }
};
