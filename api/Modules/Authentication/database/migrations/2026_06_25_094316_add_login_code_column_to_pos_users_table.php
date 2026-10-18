<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_users', 'login_code')) {
                $table->string('login_code')->nullable()->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_users', function (Blueprint $table) {
            if (Schema::hasColumn('pos_users', 'login_code')) {
                $table->dropColumn('login_code');
            }
        });
    }
};
