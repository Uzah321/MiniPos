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
        Schema::create('production_recipe_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('production_recipe_id');

            $table->uuid('product_id'); // raw material
            $table->uuid('uom_id')->nullable();

            $table->decimal('quantity_required', 18, 4);
            $table->decimal('wastage_percent', 8, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'production_recipe_id']);
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_recipe_items');
    }
};
