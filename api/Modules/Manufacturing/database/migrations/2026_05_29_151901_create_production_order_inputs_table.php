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
        Schema::create('production_order_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('production_order_id');

            $table->uuid('product_id');
            $table->uuid('uom_id')->nullable();

            $table->decimal('planned_quantity', 18, 4)->default(0);
            $table->decimal('issued_quantity', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->decimal('wasted_quantity', 18, 4)->default(0);

            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);

            $table->string('currency', 10)->default('USD');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index('production_order_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_inputs');
    }
};
