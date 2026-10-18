<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validation for these records already lets a code be reused once the record
 * is deleted, but their unique indexes still covered deleted rows, so adding a
 * till, brand, complex, shop or discount with a deleted one's code failed with
 * a database error. Include deleted_at in each index, as currencies and payment
 * types already do; validation keeps live rows unique.
 */
return new class extends Migration
{
    private const INDEXES = [
        ['pos_tills', 'pos_tills_company_branch_code_unique', ['company_id', 'branch_id', 'till_code']],
        ['brands', 'brands_company_country_code_uq', ['company_id', 'country_id', 'code']],
        ['complexes', 'complexes_company_code_uq', ['company_id', 'code']],
        ['shops', 'shops_company_code_uq', ['company_id', 'code']],
        ['discounts', 'discounts_company_id_code_unique', ['company_id', 'code']],
        ['discounts', 'discounts_company_id_name_unique', ['company_id', 'name']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $index, $columns]) {
            $this->replaceUnique($table, $index, $index.'_live', [...$columns, 'deleted_at']);
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $index, $columns]) {
            $this->replaceUnique($table, $index.'_live', $index, $columns);
        }
    }

    /** @param array<int, string> $columns */
    private function replaceUnique(string $table, string $from, string $to, array $columns): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'deleted_at')
            || ! Schema::hasIndex($table, $from, 'unique')
            || Schema::hasIndex($table, $to)) {
            return;
        }

        // Add the replacement first: a foreign key may rely on the old
        // index's leading columns.
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $to));
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($from));
    }
};
