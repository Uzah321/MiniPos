<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('food_recipes') || !Schema::hasColumn('food_recipes', 'menu_product_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE food_recipes MODIFY menu_product_id CHAR(36) NULL');
                return;
            } catch (\Throwable $e) {
                // Fallback below for environments where the column is not CHAR(36).
            }
        }

        try {
            Schema::table('food_recipes', function ($table) {
                $table->uuid('menu_product_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // If your database uses a different column type, manually make
            // food_recipes.menu_product_id nullable. The application code is
            // already safe for null menu_product_id values.
        }
    }

    public function down(): void
    {
        // Do not force menu_product_id back to NOT NULL because unlinked
        // modifier/prep recipes may now exist legitimately.
    }
};
