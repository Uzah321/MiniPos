<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('sales_transaction_id');
            $table->string('order_number', 50)->nullable();
            $table->string('target_printing', 50)->default('kitchen');
            $table->uuid('printer_id')->nullable();
            $table->string('printer_name')->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('payload')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'status'], 'kitchen_jobs_branch_status_idx');
            $table->index(['sales_transaction_id'], 'kitchen_jobs_sale_idx');
            $table->index(['order_number'], 'kitchen_jobs_order_number_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_print_jobs');
    }
};
