<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'hs_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('hs_code', 32)->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'hs_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('hs_code');
            });
        }
    }
};
