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
        Schema::create('product_break_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('parent_product_id');
            $table->uuid('child_product_id');

            $table->decimal('parent_quantity', 15, 4)->default(1);
            $table->decimal('units_per_parent', 15, 4);

            $table->boolean('is_auto_break')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('parent_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('child_product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->unique(
                ['company_id', 'parent_product_id', 'child_product_id'],
                'product_break_rule_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_break_rules');
    }
};
