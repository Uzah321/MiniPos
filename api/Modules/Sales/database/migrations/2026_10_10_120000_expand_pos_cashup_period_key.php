<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retail period keys contain five UUIDs, the currency and two timestamps.
     * Their validated maximum is currently 228 characters, so the historical
     * VARCHAR(160) rejected a completed shift before it could reach Central.
     *
     * The column is not indexed. Expanding it in place preserves every
     * existing key as well as the exact key carried by an already queued
     * shift.cashup.completed event.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pos_cashups')
            || ! Schema::hasColumn('pos_cashups', 'cashup_period_key')) {
            return;
        }

        Schema::table('pos_cashups', function (Blueprint $table): void {
            $table->string('cashup_period_key', 512)->nullable()->change();
        });
    }

    /**
     * Deliberately do not shrink the column on rollback. A 161+ character key
     * may already have been stored after this migration, and reducing it to
     * 160 would either fail or destroy an immutable cash-up identity.
     */
    public function down(): void
    {
        // Safe, data-preserving no-op.
    }
};
