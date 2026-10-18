<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('menu_product_id')->index();
            $table->string('name');
            $table->string('recipe_type', 30)->default('menu')->index(); // menu, manufacturing, prep
            $table->unsignedInteger('version_no')->default(1);
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->string('currency', 3)->default('USD');
            $table->string('source_stock_area', 20)->default('front')->index();
            $table->string('destination_stock_area', 20)->nullable()->index();
            $table->decimal('ingredient_cost', 18, 6)->default(0);
            $table->decimal('wastage_cost', 18, 6)->default(0);
            $table->decimal('packaging_cost', 18, 6)->default(0);
            $table->decimal('labour_cost', 18, 6)->default(0);
            $table->decimal('overhead_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('cost_per_unit', 18, 6)->default(0);
            $table->timestamp('last_costed_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'menu_product_id', 'recipe_type', 'version_no'], 'food_recipes_company_menu_type_version_unique');
        });

        Schema::create('food_recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('food_recipe_id')->index();
            $table->uuid('ingredient_product_id')->index();
            $table->decimal('quantity', 18, 6);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['food_recipe_id', 'ingredient_product_id'], 'food_recipe_ingredient_unique');
        });

        Schema::create('food_manufacturing_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('food_recipe_id')->index();
            $table->uuid('produced_product_id')->index();
            $table->string('source_stock_area', 20)->default('back')->index();
            $table->string('destination_stock_area', 20)->default('back')->index();
            $table->decimal('planned_yield_quantity', 18, 6)->default(1);
            $table->decimal('actual_yield_quantity', 18, 6)->default(1);
            $table->decimal('ingredient_cost', 18, 6)->default(0);
            $table->decimal('wastage_cost', 18, 6)->default(0);
            $table->decimal('packaging_cost', 18, 6)->default(0);
            $table->decimal('labour_cost', 18, 6)->default(0);
            $table->decimal('overhead_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->boolean('is_reversed')->default(false)->index();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('food_manufacturing_batch_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('food_manufacturing_batch_id')->index();
            $table->uuid('ingredient_product_id')->index();
            $table->string('stock_area', 20)->default('back')->index();
            $table->decimal('net_quantity', 18, 6)->default(0);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->decimal('wastage_quantity', 18, 6)->default(0);
            $table->decimal('gross_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('line_cost', 18, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_manufacturing_batch_lines');
        Schema::dropIfExists('food_manufacturing_batches');
        Schema::dropIfExists('food_recipe_ingredients');
        Schema::dropIfExists('food_recipes');
    }
};
