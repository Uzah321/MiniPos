<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('product_categories', 'image_path')) {
                $table->string('image_path')->nullable()->after('description');
            }

            if (!Schema::hasColumn('product_categories', 'image_url')) {
                $table->string('image_url')->nullable()->after('image_path');
            }

            if (!Schema::hasColumn('product_categories', 'banner_image_path')) {
                $table->string('banner_image_path')->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('product_categories', 'banner_image_url')) {
                $table->string('banner_image_url')->nullable()->after('banner_image_path');
            }

            /*
             * front = show on POS/customer front screen
             * back = show only on backend/admin
             * both = show on both
             */
            if (!Schema::hasColumn('product_categories', 'display_area')) {
                $table->enum('display_area', ['front', 'back', 'both'])
                    ->default('both')
                    ->after('banner_image_url');
            }

            if (!Schema::hasColumn('product_categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('display_area');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $columns = [
                'image_path',
                'image_url',
                'banner_image_path',
                'banner_image_url',
                'display_area',
                'sort_order',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('product_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
