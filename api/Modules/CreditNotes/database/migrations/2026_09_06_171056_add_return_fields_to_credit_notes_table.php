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
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->foreignId('store_id')->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('original_sale_id')->constrained()->nullOnDelete();
            $table->string('credit_note_number')->nullable()->unique()->after('status');
            $table->string('refund_reference')->nullable()->after('refund_method');
            $table->timestamp('issued_at')->nullable()->after('total');
            $table->unsignedInteger('reprint_count')->default(0)->after('issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['credit_note_number', 'refund_reference', 'issued_at', 'reprint_count']);
        });
    }
};
