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
        Schema::create('production_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            $table->uuid('finished_product_id');

            $table->string('name');
            $table->string('code')->nullable();

            $table->enum('costing_basis', [
                'per_unit',
                'per_batch'
            ])->default('per_batch');

            $table->decimal('batch_output_quantity', 18, 4)->default(1);
            $table->decimal('standard_wastage_percent', 8, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id']);
            $table->index('finished_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_recipes');
    }
};
