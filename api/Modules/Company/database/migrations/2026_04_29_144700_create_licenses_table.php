<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('license_key')->unique();
            $table->string('license_type')->default('enterprise');
            $table->integer('max_branches')->default(1);
            $table->integer('max_users')->default(5);
            $table->integer('max_devices')->default(1);
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
