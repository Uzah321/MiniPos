<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasTable('products')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM `products` LIKE 'product_type'");
        if (!$column) {
            throw new RuntimeException('products.product_type column was not found.');
        }
        if (!preg_match('/^enum\((.*)\)$/i', (string) $column->Type, $matches)) {
            // Existing installations may already use VARCHAR, which accepts these values.
            return;
        }

        $existing = str_getcsv($matches[1], ',', "'");
        $accepted = [
            'simple', 'parent', 'child', 'service',
            'raw_material', 'finished_good', 'semi_finished', 'packaging', 'consumable',
        ];
        $values = array_values(array_unique(array_merge($existing, $accepted)));
        $enum = implode(', ', array_map(
            fn (string $value) => "'".str_replace("'", "''", $value)."'",
            $values
        ));
        $nullable = strtoupper((string) $column->Null) === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column->Default === null
            ? ''
            : " DEFAULT '".str_replace("'", "''", (string) $column->Default)."'";

        DB::statement("ALTER TABLE `products` MODIFY `product_type` ENUM({$enum}) {$nullable}{$default}");
    }

    public function down(): void
    {
        // Historical products may use these types. Removing enum members would lose data.
    }
};
