<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            if (!Schema::hasColumn('product_stocks', 'stock_area')) {
                $table->string('stock_area', 20)->default('front')->after('product_id');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_movements', 'stock_area')) {
                $table->string('stock_area', 20)->nullable()->after('product_id');
            }

            if (!Schema::hasColumn('stock_movements', 'from_stock_area')) {
                $table->string('from_stock_area', 20)->nullable()->after('stock_area');
            }

            if (!Schema::hasColumn('stock_movements', 'to_stock_area')) {
                $table->string('to_stock_area', 20)->nullable()->after('from_stock_area');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'to_stock_area')) {
                $table->dropColumn('to_stock_area');
            }

            if (Schema::hasColumn('stock_movements', 'from_stock_area')) {
                $table->dropColumn('from_stock_area');
            }

            if (Schema::hasColumn('stock_movements', 'stock_area')) {
                $table->dropColumn('stock_area');
            }
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('product_stocks', 'stock_area')) {
                $table->dropColumn('stock_area');
            }
        });
    }
};
