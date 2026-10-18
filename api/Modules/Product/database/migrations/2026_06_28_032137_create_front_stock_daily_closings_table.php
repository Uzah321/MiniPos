<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_stock_daily_closings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('till_id')->nullable()->index();
            $table->uuid('closed_by')->nullable()->index();
            $table->date('closing_date')->index();
            $table->string('stock_area', 30)->default('front')->index();
            $table->string('status', 30)->default('closed')->index();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('variance_lines')->default(0);
            $table->decimal('expected_total_quantity', 18, 6)->default(0);
            $table->decimal('counted_total_quantity', 18, 6)->default(0);
            $table->decimal('variance_total_quantity', 18, 6)->default(0);
            $table->decimal('variance_total_value', 18, 6)->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'branch_id', 'closing_date', 'stock_area'], 'front_stock_daily_closing_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('front_stock_daily_closings');
    }
};
