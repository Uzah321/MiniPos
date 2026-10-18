<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_initial_admins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->uuid('pos_user_id')->unique();
            // Keep this compatible with older strict-mode edge databases,
            // where multiple TIMESTAMP columns can receive invalid implicit
            // zero defaults.
            $table->dateTime('bootstrapped_at');
            $table->dateTime('last_rotated_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('pos_user_id')
                ->references('id')
                ->on('pos_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_initial_admins');
    }
};
