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
        Schema::table('terminals', function (Blueprint $table) {
            $table->json('peripheral_status')->nullable()->after('app_version');
            $table->timestamp('peripheral_checked_at')->nullable()->after('peripheral_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(['peripheral_status', 'peripheral_checked_at']);
        });
    }
};
