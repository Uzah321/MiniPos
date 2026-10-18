<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permission = DB::table('permissions')->where('name', 'cashup_on_behalf')->first();

        if ($permission) {
            DB::table('permissions')->where('id', $permission->id)->update([
                'group' => 'Sales',
                'description' => 'Cash up and close another cashier shift with a recorded reason.',
                'updated_at' => $now,
            ]);
            $permissionId = $permission->id;
        } else {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permissionId,
                'name' => 'cashup_on_behalf',
                'group' => 'Sales',
                'description' => 'Cash up and close another cashier shift with a recorded reason.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $superRoles = DB::table('roles')
            ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")
            ->get();

        foreach ($superRoles as $role) {
            if (!DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->exists()) {
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
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('name', 'cashup_on_behalf')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
