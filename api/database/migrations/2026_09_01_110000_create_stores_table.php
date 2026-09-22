<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('complex_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('timezone')->default('UTC');
            $table->json('business_hours')->nullable();
            $table->string('status')->default('active');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });

        Schema::dropIfExists('stores');
    }
};
