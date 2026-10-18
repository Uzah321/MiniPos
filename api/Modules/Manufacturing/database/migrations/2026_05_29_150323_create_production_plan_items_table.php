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
        Schema::create('production_plan_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('production_plan_id');

            $table->uuid('finished_product_id');
            $table->uuid('production_recipe_id')->nullable();

            $table->decimal('forecast_quantity', 18, 4)->default(0);
            $table->decimal('current_stock_quantity', 18, 4)->default(0);
            $table->decimal('safety_stock_quantity', 18, 4)->default(0);

            $table->decimal('planned_quantity', 18, 4);
            $table->decimal('batch_size', 18, 4)->default(1);
            $table->integer('planned_batches')->default(1);

            $table->decimal('planned_unit_cost', 18, 6)->default(0);
            $table->decimal('planned_total_cost', 18, 4)->default(0);

            $table->date('required_by')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index('production_plan_id');
            $table->index('finished_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_plan_items');
    }
};
