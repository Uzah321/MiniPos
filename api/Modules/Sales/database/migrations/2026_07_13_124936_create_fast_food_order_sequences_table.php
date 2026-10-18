<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fast_food_order_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('trading_day_id')->nullable();
            $table->date('business_date');
            $table->string('prefix', 20)->default('ORD');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'till_id', 'business_date'], 'ff_order_seq_unique_till_day');
            $table->index(['company_id', 'branch_id', 'business_date'], 'ff_order_seq_company_branch_day_idx');
            $table->index(['trading_day_id'], 'ff_order_seq_trading_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fast_food_order_sequences');
    }
};
