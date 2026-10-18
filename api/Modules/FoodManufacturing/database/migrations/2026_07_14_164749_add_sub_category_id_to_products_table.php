<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_manufacturing_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('food_manufacturing_batches', 'batch_number')) {
                $table->string('batch_number', 40)->nullable()->after('id')->index();
            }
        });

        Schema::table('food_recipes', function (Blueprint $table) {
            if (!Schema::hasColumn('food_recipes', 'produced_product_id')) {
                $table->uuid('produced_product_id')->nullable()->after('menu_product_id')->index();
            }
            if (!Schema::hasColumn('food_recipes', 'production_output_type')) {
                $table->string('production_output_type', 40)->nullable()->after('recipe_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('food_manufacturing_batches', function (Blueprint $table) {
            if (Schema::hasColumn('food_manufacturing_batches', 'batch_number')) {
                $table->dropColumn('batch_number');
            }
        });

        Schema::table('food_recipes', function (Blueprint $table) {
            if (Schema::hasColumn('food_recipes', 'produced_product_id')) {
                $table->dropColumn('produced_product_id');
            }
            if (Schema::hasColumn('food_recipes', 'production_output_type')) {
                $table->dropColumn('production_output_type');
            }
        });
    }
};
