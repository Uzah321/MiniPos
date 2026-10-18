<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prep_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('message', 255);
            $table->string('code', 80)->nullable();
            $table->boolean('allow_free_text')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id', 'is_active'], 'prep_messages_company_branch_active_idx');
            $table->unique(['company_id', 'branch_id', 'code'], 'prep_messages_unique_code');
        });

        Schema::create('product_prep_message_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('product_id');
            $table->uuid('prep_message_id');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'branch_id', 'product_id', 'prep_message_id'], 'prep_assign_unique_product_message');
            $table->index(['company_id', 'product_id', 'is_active'], 'prep_assign_product_active_idx');
        });

        Schema::create('product_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name', 255);
            $table->string('code', 80)->nullable();
            $table->uuid('modifier_product_id')->nullable();
            $table->uuid('recipe_id')->nullable();
            $table->decimal('price', 18, 4)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->boolean('deduct_recipe_stock')->default(true);
            $table->boolean('print_on_kitchen')->default(true);
            $table->boolean('print_on_customer_receipt')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id', 'is_active'], 'modifiers_company_branch_active_idx');
            $table->index(['company_id', 'modifier_product_id'], 'modifiers_product_idx');
            $table->index(['recipe_id'], 'modifiers_recipe_idx');
            $table->unique(['company_id', 'branch_id', 'code'], 'modifiers_unique_code');
        });

        Schema::create('product_modifier_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('product_id');
            $table->uuid('modifier_id');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'branch_id', 'product_id', 'modifier_id'], 'modifier_assign_unique_product_modifier');
            $table->index(['company_id', 'product_id', 'is_active'], 'modifier_assign_product_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_modifier_assignments');
        Schema::dropIfExists('product_modifiers');
        Schema::dropIfExists('product_prep_message_assignments');
        Schema::dropIfExists('product_prep_messages');
    }
};
