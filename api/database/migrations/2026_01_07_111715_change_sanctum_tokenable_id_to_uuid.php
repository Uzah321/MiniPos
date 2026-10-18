<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // tokenable_id is indexed as part of (tokenable_type, tokenable_id)
        // Some MySQL versions require dropping index first, then changing column, then re-adding.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // drop the morph index if it exists (Laravel default name)
            $table->dropIndex(['tokenable_type', 'tokenable_id']);
        });

        // Change column type
        DB::statement("ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` CHAR(36) NOT NULL");

        // Recreate morph index
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['tokenable_type', 'tokenable_id']);
        });

        DB::statement("ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` BIGINT UNSIGNED NOT NULL");

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::enableForeignKeyConstraints();
    }
};
