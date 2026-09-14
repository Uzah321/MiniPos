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
            $table->string('receipt_number')->nullable()->unique()->after('total');
            $table->timestamp('receipt_issued_at')->nullable()->after('receipt_number');
            $table->unsignedInteger('reprint_count')->default(0)->after('receipt_issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['receipt_number', 'receipt_issued_at', 'reprint_count']);
        });
    }
};
