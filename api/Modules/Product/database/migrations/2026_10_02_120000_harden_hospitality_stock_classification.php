<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'default_issue_destination')) {
            // An older backfill used a token that the API never accepted.
            DB::table('products')
                ->where('default_issue_destination', 'operating_expense')
                ->update(['default_issue_destination' => 'expense']);
        }

        if (
            DB::getDriverName() !== 'mysql'
            || ! Schema::hasTable('stock_movements')
            || ! Schema::hasColumn('stock_movements', 'movement_type')
        ) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM stock_movements LIKE 'movement_type'");
        $definition = (string) ($column->Type ?? '');
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $definition, $matches);
        $values = array_map(static fn (string $value) => stripcslashes($value), $matches[1] ?? []);

        if ($values === [] || in_array('wastage', $values, true)) {
            return;
        }

        $values[] = 'wastage';
        $enum = implode(',', array_map(
            static fn (string $value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            $values
        ));
        $nullable = strtoupper((string) ($column->Null ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column->Default === null
            ? ''
            : " DEFAULT '".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $column->Default)."'";

        DB::statement("ALTER TABLE stock_movements MODIFY movement_type ENUM({$enum}) {$nullable}{$default}");
    }

    public function down(): void
    {
        // Keep the enum value because historical stock-ledger rows may use it.
    }
};
