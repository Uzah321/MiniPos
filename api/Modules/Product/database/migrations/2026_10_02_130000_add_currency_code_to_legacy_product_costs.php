<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some deployed databases ran an older version of the currency migration
     * that created `currency`, while fresh databases create `currency_code`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_costs')) {
            return;
        }

        if (! Schema::hasColumn('product_costs', 'currency_code')) {
            Schema::table('product_costs', function (Blueprint $table) {
                $table->string('currency_code')->nullable();
            });
        }

        if (Schema::hasColumn('product_costs', 'currency')) {
            DB::table('product_costs')
                ->where(function ($query) {
                    $query->whereNull('currency_code')
                        ->orWhere('currency_code', '');
                })
                ->update(['currency_code' => DB::raw('currency')]);
        }
    }

    public function down(): void
    {
        // Intentionally preserve currency_code: this migration can run on both
        // legacy and already-canonical schemas, so dropping it is not reversible.
    }
};
