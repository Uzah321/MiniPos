<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('food_manufacturing_production_plans')) {
            Schema::create('food_manufacturing_production_plans', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->string('plan_number', 80)->nullable();
                $table->uuid('food_recipe_id');
                $table->uuid('produced_product_id')->nullable();
                $table->string('source_stock_area', 20)->default('back');
                $table->string('destination_stock_area', 20)->default('back');
                $table->decimal('planned_quantity', 18, 6)->default(0);
                $table->decimal('actual_quantity', 18, 6)->nullable();
                $table->string('status', 30)->default('planned');
                $table->string('currency', 10)->default('USD');
                $table->decimal('required_value', 18, 6)->default(0);
                $table->decimal('shortage_value', 18, 6)->default(0);
                $table->unsignedInteger('shortage_line_count')->default(0);
                $table->boolean('can_produce')->default(false);
                $table->date('planned_for')->nullable();
                $table->text('notes')->nullable();
                $table->json('summary')->nullable();
                $table->json('lines')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();

            });
        }

        // MariaDB/MySQL limit index identifiers to 64 characters. The
        // generated names exceed that limit for this long table name. Add
        // each index separately so interrupted DDL can be resumed safely.
        foreach ([
            'food_mfg_plans_company_branch_date_idx' => ['company_id', 'branch_id', 'planned_for'],
            'food_mfg_plans_company_recipe_idx' => ['company_id', 'food_recipe_id'],
            'food_mfg_plans_company_product_idx' => ['company_id', 'produced_product_id'],
        ] as $name => $columns) {
            if (! Schema::hasIndex('food_manufacturing_production_plans', $name)) {
                Schema::table('food_manufacturing_production_plans', function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('food_manufacturing_production_plans');
    }
};
