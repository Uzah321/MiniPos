<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('iso_code', 2)->unique();
            $table->char('iso3_code', 3)->nullable()->unique();
            $table->string('name');
            $table->char('currency_code', 3);
            $table->string('timezone')->default('UTC');
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'code']);
        });

        Schema::create('complexes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('brand_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complexes');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('countries');
    }
};
