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
        Schema::create('user_branches', function (Blueprint $table) {


            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['pos_user_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_branches');
    }
};
