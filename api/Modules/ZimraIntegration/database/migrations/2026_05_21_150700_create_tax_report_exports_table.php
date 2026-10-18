<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_report_exports')) return;

        Schema::create('tax_report_exports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36)->index();
            $table->char('branch_id', 36)->nullable()->index();
            $table->char('requested_by', 36)->nullable()->index();
            $table->string('report_type')->default('tax_summary');
            $table->string('format')->default('xlsx');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status')->default('completed');
            $table->string('file_path')->nullable();
            $table->json('filters')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'report_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_report_exports');
    }
};
