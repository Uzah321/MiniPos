<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('pos_user_id')->nullable()->index();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('approved_by')->nullable()->index();

            $table->string('quote_code')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();

            $table->string('currency', 20)->default('USD');
            $table->decimal('sub_total', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);

            $table->dateTime('valid_until')->nullable();
            $table->string('status')->default('draft'); // draft, issued, converted, cancelled, expired
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('converted_at')->nullable();
            $table->uuid('converted_sale_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'quote_code']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('quotation_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('product_price_id')->nullable()->index();
            $table->uuid('price_level_id')->nullable()->index();
            $table->uuid('category_id')->nullable()->index();

            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->string('sku')->nullable();
            $table->string('currency', 20)->default('USD');

            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('base_unit_price', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);

            $table->boolean('has_price_override')->default(false);
            $table->uuid('price_override_level_id')->nullable()->index();
            $table->uuid('price_override_approved_by')->nullable()->index();
            $table->uuid('price_override_approval_id')->nullable()->index();
            $table->json('price_override_payload')->nullable();

            $table->decimal('gross_amount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('net_amount', 15, 4)->default(0);

            $table->uuid('discount_id')->nullable()->index();
            $table->uuid('discount_type_id')->nullable()->index();
            $table->string('discount_code')->nullable();
            $table->string('discount_name')->nullable();
            $table->string('discount_basis')->nullable();
            $table->decimal('discount_value', 15, 4)->default(0);
            $table->uuid('discount_approved_by')->nullable()->index();
            $table->uuid('discount_approval_id')->nullable()->index();
            $table->json('discount_payload')->nullable();

            $table->boolean('taxable')->default(false);
            $table->decimal('tax_rate', 12, 6)->default(0);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();
        });

        Schema::create('quotation_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('quotation_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('action')->index();
            $table->string('status')->default('success');
            $table->string('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_audit_events');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
