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
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->decimal('coupon_discount_amount', 12, 4)->default(0)->after('discount_total');
            $table->decimal('loyalty_discount_amount', 12, 4)->default(0)->after('coupon_discount_amount');
            $table->unsignedInteger('loyalty_points_earned')->default(0)->after('loyalty_discount_amount');
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('loyalty_points_earned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_discount_amount', 'loyalty_discount_amount', 'loyalty_points_earned', 'loyalty_points_redeemed']);
        });
    }
};
