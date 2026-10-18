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
        Schema::create('production_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');

            $table->string('plan_number')->unique();
            $table->string('name');

            $table->enum('planning_basis', [
                'manual',
                'sales_forecast',
                'sales_order',
                'minimum_stock',
                'reorder_level'
            ])->default('manual');

            $table->date('period_start');
            $table->date('period_end');

            $table->enum('status', [
                'draft',
                'approved',
                'converted_to_orders',
                'cancelled'
            ])->default('draft');

            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
