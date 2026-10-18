<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_stock_reconciliation_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('trading_day_id');
            $table->date('trading_date');
            $table->string('status', 30)->default('draft');
            $table->uuid('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('total_revenue', 18, 6)->default(0);
            $table->decimal('total_recipe_cost', 18, 6)->default(0);
            $table->decimal('total_variance_value', 18, 6)->default(0);
            $table->decimal('gross_profit', 18, 6)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['company_id','branch_id','trading_date'], 'dsrr_company_branch_date_uq');
            $table->index('trading_day_id', 'dsrr_trading_day_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('daily_stock_reconciliation_reports');
    }
};
