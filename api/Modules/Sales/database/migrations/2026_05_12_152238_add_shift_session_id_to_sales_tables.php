<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'shift_session_id')) {
                $table->uuid('shift_session_id')->nullable()->after('shift_id');
                $table->index('shift_session_id');
            }
        });

        Schema::table('sales_transaction_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transaction_payments', 'shift_session_id')) {
                $table->uuid('shift_session_id')->nullable()->after('shift_id');
                $table->index('shift_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_transaction_payments', function (Blueprint $table) {
            if (Schema::hasColumn('sales_transaction_payments', 'shift_session_id')) {
                $table->dropIndex(['shift_session_id']);
                $table->dropColumn('shift_session_id');
            }
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('sales_transactions', 'shift_session_id')) {
                $table->dropIndex(['shift_session_id']);
                $table->dropColumn('shift_session_id');
            }
        });
    }
};
