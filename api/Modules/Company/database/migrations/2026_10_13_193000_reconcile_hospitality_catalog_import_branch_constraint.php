<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hospitality_catalog_imports')
            || ! Schema::hasTable('company_branches')
            || ! Schema::hasColumn('hospitality_catalog_imports', 'branch_id')) {
            return;
        }

        if (! Schema::hasIndex('hospitality_catalog_imports', 'catalog_import_branch_idx')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->index(['company_id', 'branch_id'], 'catalog_import_branch_idx');
            });
        }

        if (! $this->hasForeignKey('hospitality_catalog_imports', 'catalog_import_branch_fk')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->foreign('branch_id', 'catalog_import_branch_fk')
                    ->references('id')
                    ->on('company_branches')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // This corrective migration may be a no-op because the preceding
        // scope migration already owns the index and foreign key. Keep both
        // intact here so rolling back this reconciliation cannot remove
        // constraints created by that earlier migration.
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn (array $foreignKey): bool => strcasecmp(
                (string) ($foreignKey['name'] ?? ''),
                $name
            ) === 0
        );
    }
};
