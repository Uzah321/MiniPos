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
        Schema::create('material_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');

            $table->uuid('production_plan_id')->nullable();
            $table->uuid('production_order_id')->nullable();

            $table->uuid('product_id');

            $table->decimal('required_quantity', 18, 4);
            $table->decimal('available_quantity', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0);
            $table->decimal('shortage_quantity', 18, 4)->default(0);

            $table->enum('status', [
                'draft',
                'reserved',
                'purchase_required',
                'fulfilled'
            ])->default('draft');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index('production_plan_id');
            $table->index('production_order_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_requirements');
    }
};
