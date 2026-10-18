<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_items', 'subtotal_excl_tax')) {
                $table->decimal('subtotal_excl_tax', 15, 4)->default(0)->after('tax_amount');
            }
            if (!Schema::hasColumn('quotation_items', 'inclusive_tax_total')) {
                $table->decimal('inclusive_tax_total', 15, 4)->default(0)->after('subtotal_excl_tax');
            }
            if (!Schema::hasColumn('quotation_items', 'exclusive_tax_total')) {
                $table->decimal('exclusive_tax_total', 15, 4)->default(0)->after('inclusive_tax_total');
            }
            if (!Schema::hasColumn('quotation_items', 'tax_total')) {
                $table->decimal('tax_total', 15, 4)->default(0)->after('exclusive_tax_total');
            }
            if (!Schema::hasColumn('quotation_items', 'tax_details')) {
                $table->json('tax_details')->nullable()->after('tax_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            foreach (['subtotal_excl_tax', 'inclusive_tax_total', 'exclusive_tax_total', 'tax_total', 'tax_details'] as $column) {
                if (Schema::hasColumn('quotation_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
