<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sub_category_id')) {
                $table->uuid('sub_category_id')->nullable()->after('category_id');

                $table->index('sub_category_id');

                $table->foreign('sub_category_id')
                    ->references('id')
                    ->on('product_sub_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'sub_category_id')) {
                $table->dropForeign(['sub_category_id']);
                $table->dropIndex(['sub_category_id']);
                $table->dropColumn('sub_category_id');
            }
        });
    }
};
