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
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
            $table->decimal('promotion_discount_amount', 12, 4)->default(0)->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('promotion_discount_amount');
        });
    }
};
