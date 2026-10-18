<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = 'view_cashup_financial_amounts';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $existing = DB::table('permissions')->where('name', self::PERMISSION)->first();

        if ($existing) {
            DB::table('permissions')->where('id', $existing->id)->update([
                'group' => 'Reports',
                'description' => 'View Sensitive Cashup Financial Amounts',
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('permissions')->insert([
            'id' => (string) Str::uuid(),
            'name' => self::PERMISSION,
            'group' => 'Reports',
            'description' => 'View Sensitive Cashup Financial Amounts',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->pluck('id');

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
