<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = 'reverse_credit_customer_payment';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permission = DB::table('permissions')->where('name', self::PERMISSION)->first();

        if ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'group' => 'Sales',
                'description' => 'Reverse a posted credit customer payment with a permanent audit record.',
                'updated_at' => $now,
            ]);
            $permissionId = $permission->id;
        } else {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permissionId,
                'name' => self::PERMISSION,
                'group' => 'Sales',
                'description' => 'Reverse a posted credit customer payment with a permanent audit record.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $superRoles = DB::table('roles')
            ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")
            ->get();

        foreach ($superRoles as $role) {
            if (DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permissionId)
                ->exists()) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permissionId,
                'company_id' => $role->company_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
