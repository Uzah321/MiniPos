<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zimra_company_settings')) {
            return;
        }

        Schema::create('zimra_company_settings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('environment')->default('testing');
            $table->string('base_url')->nullable();
            $table->boolean('auto_open_day')->default(false);
            $table->boolean('block_sales_without_open_day')->default(true);
            $table->boolean('block_sale_if_fiscalisation_fails')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimra_company_settings');
    }
};
