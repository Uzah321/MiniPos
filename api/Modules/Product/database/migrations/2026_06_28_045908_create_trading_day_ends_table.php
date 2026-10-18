<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_day_ends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->date('trading_date');
            $table->date('next_trading_date')->nullable();
            $table->string('status', 30)->default('running');
            $table->uuid('started_by')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('lock_token', 80)->nullable();
            $table->timestamp('lock_started_at')->nullable();
            $table->timestamp('lock_released_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id'], 'tde_company_branch_idx');
            $table->index(['company_id', 'branch_id', 'trading_date'], 'tde_company_branch_date_idx');
            $table->index(['company_id', 'branch_id', 'status'], 'tde_company_branch_status_idx');
            $table->unique(['company_id', 'branch_id', 'trading_date'], 'tde_company_branch_date_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_day_ends');
    }
};
