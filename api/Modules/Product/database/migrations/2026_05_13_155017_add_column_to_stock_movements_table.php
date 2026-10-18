<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe upgrade for your existing stock_movements table.
 * It only adds enterprise report columns if they are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_movements', 'reference_type')) {
                $table->string('reference_type')->nullable()->after('total_cost');
            }

            if (!Schema::hasColumn('stock_movements', 'reference_id')) {
                $table->uuid('reference_id')->nullable()->after('reference_type');
            }

            if (!Schema::hasColumn('stock_movements', 'balance_after')) {
                $table->decimal('balance_after', 18, 4)->nullable()->after('quantity_out');
            }

            if (!Schema::hasColumn('stock_movements', 'currency')) {
                $table->string('currency', 10)->nullable()->after('total_cost');
            }

            if (!Schema::hasColumn('stock_movements', 'till_id')) {
                $table->uuid('till_id')->nullable()->after('branch_id');
            }

            if (!Schema::hasColumn('stock_movements', 'shift_session_id')) {
                $table->uuid('shift_session_id')->nullable()->after('till_id');
            }

            if (!Schema::hasColumn('stock_movements', 'metadata')) {
                $table->json('metadata')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        // Do not drop columns automatically because this upgrades a live ledger table.
    }
};
