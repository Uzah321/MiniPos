<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view_hospitality_tables' => 'View hospitality table setup and availability',
        'manage_hospitality_tables' => 'Configure hospitality tables and assignment policy',
        'manage_open_tables' => 'Open, update, hang and recall hospitality table orders',
        'clear_open_tables' => 'Clear unpaid hospitality table orders',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $name => $description) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => DB::table('permissions')->where('name', $name)->value('id') ?: (string) Str::uuid(),
                    'group' => 'hospitality-tables',
                    'description' => $description,
                    'updated_at' => now(),
                    'created_at' => DB::table('permissions')->where('name', $name)->value('created_at') ?: now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Permission rows may already be assigned to dynamic roles. Keep them
        // during rollback to avoid destructive permission-pivot cascades.
    }
};
