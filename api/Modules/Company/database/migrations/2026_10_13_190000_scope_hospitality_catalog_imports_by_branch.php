<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hospitality_catalog_imports')) {
            return;
        }

        if (! Schema::hasColumn('hospitality_catalog_imports', 'branch_id')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->uuid('branch_id')->nullable()->after('brand_id');
            });
        }
        if (! Schema::hasColumn('hospitality_catalog_imports', 'scope_key')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->string('scope_key', 64)->default('brand')->after('branch_id');
            });
        }

        DB::table('hospitality_catalog_imports')
            ->where(function ($query): void {
                $query->whereNull('scope_key')->orWhere('scope_key', '');
            })
            ->update(['scope_key' => 'brand']);

        if ($this->hasIndex('hospitality_catalog_imports', 'catalog_import_source_uq')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropUnique('catalog_import_source_uq');
            });
        }
        if (! $this->hasIndex('hospitality_catalog_imports', 'catalog_import_scoped_source_uq')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->unique(
                    ['company_id', 'brand_id', 'source_sha256', 'dry_run', 'scope_key'],
                    'catalog_import_scoped_source_uq'
                );
            });
        }
        if (! $this->hasIndex('hospitality_catalog_imports', 'catalog_import_branch_idx')) {
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
        if (! Schema::hasTable('hospitality_catalog_imports')) {
            return;
        }

        if ($this->hasIndex('hospitality_catalog_imports', 'catalog_import_scoped_source_uq')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropUnique('catalog_import_scoped_source_uq');
            });
        }
        if ($this->hasForeignKey('hospitality_catalog_imports', 'catalog_import_branch_fk')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropForeign('catalog_import_branch_fk');
            });
        }
        if ($this->hasIndex('hospitality_catalog_imports', 'catalog_import_branch_idx')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropIndex('catalog_import_branch_idx');
            });
        }
        if (Schema::hasColumn('hospitality_catalog_imports', 'scope_key')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropColumn('scope_key');
            });
        }
        if (Schema::hasColumn('hospitality_catalog_imports', 'branch_id')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->dropColumn('branch_id');
            });
        }
        if (! $this->hasIndex('hospitality_catalog_imports', 'catalog_import_source_uq')) {
            Schema::table('hospitality_catalog_imports', function (Blueprint $table): void {
                $table->unique(
                    ['company_id', 'brand_id', 'source_sha256', 'dry_run'],
                    'catalog_import_source_uq'
                );
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp((string) ($index['name'] ?? ''), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (strcasecmp((string) ($foreignKey['name'] ?? ''), $name) === 0) {
                return true;
            }
        }

        return false;
    }
};
