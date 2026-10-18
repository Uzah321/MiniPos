<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_days')) {
            return;
        }

        Schema::create('fiscal_days', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36);
            $table->char('branch_id', 36)->nullable();
            $table->char('fiscal_device_id', 36);
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('fiscal_day_no');
            $table->string('status')->default('opened');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('receipt_counter')->default(0);
            $table->string('open_operation_id')->nullable();
            $table->string('close_operation_id')->nullable();
            $table->json('open_response')->nullable();
            $table->json('close_response')->nullable();
            $table->json('last_status_response')->nullable();
            $table->json('server_signature')->nullable();
            $table->string('closing_error_code')->nullable();
            $table->char('opened_by', 36)->nullable();
            $table->char('closed_by', 36)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_device_id', 'fiscal_day_no']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            $table->foreign('fiscal_device_id')->references('id')->on('fiscal_devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_days');
    }
};
