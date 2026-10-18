<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trading_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->date('trading_date');
            $table->string('status', 40)->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('day_end_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('day_end_started_by')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->string('lock_token', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['company_id','branch_id','trading_date'], 'td_company_branch_date_uq');
            $table->index(['company_id','branch_id','status'], 'td_company_branch_status_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('trading_days');
    }
};
