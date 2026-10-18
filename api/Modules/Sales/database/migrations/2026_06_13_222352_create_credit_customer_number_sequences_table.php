<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_customer_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('sequence_type', 50)->default('credit_customer_account');
            $table->string('prefix', 30)->default('CR');
            $table->date('sequence_date')->nullable();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'sequence_type', 'prefix'], 'credit_customer_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_customer_number_sequences');
    }
};
