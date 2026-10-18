<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_clock_guards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_timezone')->nullable();
            $table->string('last_seen_source')->default('server');
            $table->unsignedInteger('rollback_tolerance_minutes')->default(10);
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_clock_guards');
    }
};
