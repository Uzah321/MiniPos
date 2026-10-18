<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sub_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('category_id');

            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();

            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();

            $table->string('banner_image_path')->nullable();
            $table->string('banner_image_url')->nullable();

            /*
             * front = show on frontend/POS
             * back = show only in backend/admin
             * both = show everywhere
             */
            $table->enum('display_area', ['front', 'back', 'both'])->default('both');

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'category_id']);
            $table->unique(['company_id', 'category_id', 'name']);
            $table->unique(['company_id', 'category_id', 'code']);

            $table->foreign('category_id')
                ->references('id')
                ->on('product_categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sub_categories');
    }
};
