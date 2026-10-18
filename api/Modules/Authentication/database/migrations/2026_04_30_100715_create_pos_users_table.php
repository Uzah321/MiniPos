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
            Schema::create('pos_users', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->string('name');
                $table->string('username')->unique();
                $table->string('phone')->nullable();

                $table->string('password');

                $table->string('employee_code')->nullable();
                $table->string('user_type')->default('cashier');
                // super_admin, admin, manager, cashier, supervisor

                $table->boolean('can_login_pos')->default(true);
                $table->boolean('is_active')->default(true);

                $table->timestamp('last_login_at')->nullable();
                $table->rememberToken();

                $table->timestamps();
                $table->softDeletes();

                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('role_id');
            });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_users');
    }
};
