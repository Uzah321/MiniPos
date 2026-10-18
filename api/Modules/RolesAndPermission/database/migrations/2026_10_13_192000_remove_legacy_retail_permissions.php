<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private const LEGACY_PERMISSIONS = [
        'product_management_retail' => 'View Product Management Retail',
        'manufacturing_management_retail' => 'View Manufacturing Management Retail',
        'report_management_retail' => 'View Reports Management Retail',
        'purchase_management_retail' => 'View Purchase Management Retail',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name', array_keys(self::LEGACY_PERMISSIONS))
            ->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::LEGACY_PERMISSIONS as $name => $description) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'group' => 'Modal Permissions',
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
