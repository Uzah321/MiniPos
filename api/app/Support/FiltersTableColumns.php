<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Keeps rolling-deploy writes compatible with a database whose migrations
 * have not reached the same release yet.
 *
 * The column list is cached only on the current object instance (normally one
 * controller/service per request), so a later request immediately sees newly
 * migrated columns without requiring a process restart.
 */
trait FiltersTableColumns
{
    /** @var array<string, array<int, string>> */
    private array $schemaCompatibleColumns = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function onlyExistingTableColumns(string $table, array $attributes): array
    {
        $columns = $this->existingTableColumns($table);

        return array_intersect_key($attributes, array_flip($columns));
    }

    protected function tableHasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->existingTableColumns($table), true);
    }

    /** @return array<int, string> */
    private function existingTableColumns(string $table): array
    {
        if (!array_key_exists($table, $this->schemaCompatibleColumns)) {
            $this->schemaCompatibleColumns[$table] = Schema::getColumnListing($table);
        }

        return $this->schemaCompatibleColumns[$table];
    }
}
