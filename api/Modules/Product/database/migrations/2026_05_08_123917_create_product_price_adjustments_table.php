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
        Schema::create('product_price_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            $table->uuid('product_id');
            $table->uuid('product_price_id')->nullable();
            $table->uuid('price_level_id');

            $table->decimal('old_price', 18, 4)->nullable();
            $table->decimal('new_price', 18, 4);

            $table->string('currency', 10)->default('USD');
            $table->string('adjustment_type', 50)->default('manual');
            $table->text('reason')->nullable();

            $table->uuid('adjusted_by')->nullable();
            $table->timestamp('adjusted_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('product_id');
            $table->index('product_price_id');
            $table->index('price_level_id');
            $table->index('adjusted_by');
            $table->index('adjusted_at');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('product_price_id')
                ->references('id')
                ->on('product_prices')
                ->nullOnDelete();

            $table->foreign('price_level_id')
                ->references('id')
                ->on('price_levels')
                ->cascadeOnDelete();

            $table->foreign('adjusted_by')
                ->references('id')
                ->on('pos_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_adjustments');
    }
};
