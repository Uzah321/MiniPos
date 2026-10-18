<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_hs_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->string('hs_code', 8);
            $table->text('classification_basis');
            $table->string('source_url', 512);
            $table->uuid('approved_by');
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['company_id', 'product_id'], 'hs_approval_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_hs_approvals');
    }
};
