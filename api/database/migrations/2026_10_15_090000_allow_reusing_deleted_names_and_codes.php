<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted record no longer reserves its name or code. Validation already
 * ignores deleted rows; here each unique index gains deleted_at, as
 * currencies and payment types already do, so a live record can take the
 * name or code of a deleted one.
 *
 * Two kinds of value cannot simply share an index with deleted rows:
 * - POS PINs must stay strictly unique among live users, so a deleted user's
 *   PIN is cleared instead (they can never sign in again).
 * - Barcodes live in product_barcodes, which used to stay live after their
 *   product was deleted; they are now deleted with it.
 */
return new class extends Migration
{
    private const INDEXES = [
        ['pos_users', 'pos_users_company_username_unique', ['company_id', 'username']],
        ['price_levels', 'price_levels_company_id_code_unique', ['company_id', 'code']],
        ['price_levels', 'price_levels_company_id_name_unique', ['company_id', 'name']],
        ['product_categories', 'product_categories_company_id_code_unique', ['company_id', 'code']],
        ['product_categories', 'product_categories_company_id_name_unique', ['company_id', 'name']],
        ['product_sub_categories', 'product_sub_categories_company_id_category_id_code_unique', ['company_id', 'category_id', 'code']],
        ['product_sub_categories', 'product_sub_categories_company_id_category_id_name_unique', ['company_id', 'category_id', 'name']],
        ['taxes', 'taxes_company_id_name_unique', ['company_id', 'name']],
        ['suppliers', 'suppliers_company_id_code_unique', ['company_id', 'code']],
        ['suppliers', 'suppliers_company_id_name_unique', ['company_id', 'name']],
        ['products', 'products_company_id_barcode_unique', ['company_id', 'barcode']],
        ['products', 'products_company_id_code_unique', ['company_id', 'code']],
        ['products', 'products_company_id_name_unique', ['company_id', 'name']],
        ['products', 'products_company_id_sku_unique', ['company_id', 'sku']],
        ['product_barcodes', 'product_barcodes_company_id_barcode_unique', ['company_id', 'barcode']],
        ['product_modifiers', 'modifiers_unique_code', ['company_id', 'branch_id', 'code']],
        ['product_prep_messages', 'prep_messages_unique_code', ['company_id', 'branch_id', 'code']],
        ['inventory_warehouses', 'inventory_warehouses_company_code_unique', ['company_id', 'code']],
        ['inventory_locations', 'inventory_locations_company_warehouse_code_unique', ['company_id', 'warehouse_id', 'code']],
        ['countries', 'countries_code_unique', ['code']],
        ['countries', 'countries_iso_alpha_2_unique', ['iso_alpha_2']],
        ['countries', 'countries_iso_alpha_3_unique', ['iso_alpha_3']],
        ['companies', 'companies_company_code_unique', ['company_code']],
        ['companies', 'companies_domain_unique', ['domain']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $index, $columns]) {
            $this->replaceUnique($table, $index, $index.'_live', [...$columns, 'deleted_at']);
        }

        // Edge installations receive users and barcodes from Central.
        if (config('salexa_sync.deployment_mode') === 'edge') {
            return;
        }

        $this->releaseDeletedUserPins();
        $this->deleteBarcodesOfDeletedProducts();
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

    private function releaseDeletedUserPins(): void
    {
        if (! Schema::hasTable('pos_users') || ! Schema::hasColumn('pos_users', 'login_code')) {
            return;
        }

        $values = ['login_code' => null];
        foreach (['login_code_lookup', 'login_code_lookup_key_id'] as $column) {
            if (Schema::hasColumn('pos_users', $column)) {
                $values[$column] = null;
            }
        }

        DB::table('pos_users')
            ->whereNotNull('deleted_at')
            ->whereNotNull('login_code')
            ->update($values);
    }

    private function deleteBarcodesOfDeletedProducts(): void
    {
        if (! Schema::hasTable('product_barcodes') || ! Schema::hasColumn('product_barcodes', 'deleted_at')) {
            return;
        }

        $now = now();
        $values = ['deleted_at' => $now];
        if (Schema::hasColumn('product_barcodes', 'updated_at')) {
            $values['updated_at'] = $now;
        }

        DB::table('product_barcodes')
            ->whereNull('deleted_at')
            ->whereIn('product_id', DB::table('products')->whereNotNull('deleted_at')->select('id'))
            ->update($values);
    }
};
