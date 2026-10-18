<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_stock_reconciliation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('trading_day_id');
            $table->date('trading_date');
            $table->string('section', 30);
            $table->uuid('menu_product_id')->nullable();
            $table->string('menu_product_name')->nullable();
            $table->string('menu_product_code')->nullable();
            $table->string('menu_product_sku')->nullable();
            $table->decimal('menu_quantity_sold', 18, 6)->default(0);
            $table->decimal('menu_avg_selling_price', 18, 6)->default(0);
            $table->decimal('menu_revenue', 18, 6)->default(0);
            $table->decimal('menu_recipe_cost', 18, 6)->default(0);
            $table->decimal('menu_gross_profit', 18, 6)->default(0);
            $table->decimal('menu_cost_percent', 18, 6)->default(0);
            $table->uuid('ingredient_product_id')->nullable();
            $table->string('ingredient_name')->nullable();
            $table->string('ingredient_code')->nullable();
            $table->string('ingredient_sku')->nullable();
            $table->string('ingredient_type')->nullable();
            $table->string('uom_code')->nullable();
            $table->decimal('recipe_qty_per_menu', 18, 6)->default(0);
            $table->decimal('ingredient_consumed_qty', 18, 6)->default(0);
            $table->decimal('opening_qty', 18, 6)->default(0);
            $table->decimal('receipts_qty', 18, 6)->default(0);
            $table->decimal('boh_to_front_qty', 18, 6)->default(0);
            $table->decimal('other_issues_qty', 18, 6)->default(0);
            $table->decimal('sales_consumption_qty', 18, 6)->default(0);
            $table->decimal('adjustments_qty', 18, 6)->default(0);
            $table->decimal('expected_closing_qty', 18, 6)->default(0);
            $table->decimal('counted_closing_qty', 18, 6)->default(0);
            $table->decimal('variance_qty', 18, 6)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('ingredient_total_cost', 18, 6)->default(0);
            $table->decimal('variance_value', 18, 6)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('report_id', 'dsrl_report_idx');
            $table->index(['company_id','branch_id','trading_date'], 'dsrl_company_branch_date_idx');
            $table->index('menu_product_id', 'dsrl_menu_idx');
            $table->index('ingredient_product_id', 'dsrl_ing_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('daily_stock_reconciliation_lines');
    }
};
