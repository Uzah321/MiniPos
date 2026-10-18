<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissions = [
        ['inventory_dashboard', 'inventory', 'View enterprise inventory dashboard'],
        ['manage_warehouses', 'inventory', 'Create and maintain warehouses and locations'],
        ['view_inventory_batches', 'inventory', 'View inventory batches and expiry'],
        ['manage_inventory_batches', 'inventory', 'Manage inventory batches and quality state'],
        ['view_inventory_alerts', 'inventory', 'View inventory alerts'],
        ['manage_inventory_alerts', 'inventory', 'Refresh and resolve inventory alerts'],
        ['view_inventory_recalls', 'inventory', 'View product recalls and affected sales'],
        ['manage_inventory_recalls', 'inventory', 'Start, release and destroy recalled stock'],
        ['view_inventory_movements', 'inventory', 'View warehouse stock movement ledger'],
        ['view_purchase_receipts', 'purchases', 'View goods received notes'],
        ['view_supplier_payments', 'purchases', 'View supplier payment register'],
        ['supplier_payments', 'purchases', 'Post and allocate supplier payments'],
        ['reverse_supplier_payments', 'purchases', 'Reverse posted supplier payments'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as [$name, $group, $description]) {
            $existing = DB::table('permissions')->where('name', $name)->first();
            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update(['group' => $group, 'description' => $description, 'updated_at' => $now]);
            } else {
                DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'name' => $name, 'group' => $group, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_column($this->permissions, 0))->pluck('id');
        $superRoles = DB::table('roles')->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")->get();
        foreach ($superRoles as $role) {
            foreach ($permissionIds as $permissionId) {
                $pivot = DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->first();
                if ($pivot) {
                    DB::table('role_permissions')->where('id', $pivot->id)->update(['company_id' => $role->company_id, 'updated_at' => $now]);
                } else {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permissionId, 'company_id' => $role->company_id, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_column($this->permissions, 0))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
