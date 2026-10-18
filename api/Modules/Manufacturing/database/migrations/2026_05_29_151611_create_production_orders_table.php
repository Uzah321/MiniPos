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
        Schema::create('production_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');

            $table->uuid('production_plan_id')->nullable();
            $table->uuid('production_plan_item_id')->nullable();

            $table->uuid('production_recipe_id')->nullable();
            $table->uuid('finished_product_id');

            $table->string('production_number')->unique();

            $table->enum('status', [
                'draft',
                'approved',
                'materials_reserved',
                'materials_issued',
                'in_progress',
                'completed',
                'cancelled'
            ])->default('draft');

            $table->integer('batch_number')->nullable();
            $table->decimal('batch_size', 18, 4)->default(1);

            $table->decimal('planned_output_quantity', 18, 4);
            $table->decimal('actual_good_quantity', 18, 4)->default(0);
            $table->decimal('actual_waste_quantity', 18, 4)->default(0);
            $table->decimal('actual_reject_quantity', 18, 4)->default(0);

            $table->decimal('planned_raw_material_cost', 18, 4)->default(0);
            $table->decimal('planned_labour_cost', 18, 4)->default(0);
            $table->decimal('planned_overhead_cost', 18, 4)->default(0);
            $table->decimal('planned_total_cost', 18, 4)->default(0);
            $table->decimal('planned_unit_cost', 18, 6)->default(0);

            $table->decimal('actual_raw_material_cost', 18, 4)->default(0);
            $table->decimal('actual_labour_cost', 18, 4)->default(0);
            $table->decimal('actual_overhead_cost', 18, 4)->default(0);
            $table->decimal('actual_total_cost', 18, 4)->default(0);
            $table->decimal('actual_unit_cost', 18, 6)->default(0);

            $table->decimal('yield_variance_quantity', 18, 4)->default(0);
            $table->decimal('cost_variance_amount', 18, 4)->default(0);

            $table->string('currency', 10)->default('USD');

            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->uuid('approved_by')->nullable();
            $table->uuid('created_by')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id', 'status']);
            $table->index('finished_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
