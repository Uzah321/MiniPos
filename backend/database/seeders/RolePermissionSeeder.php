<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'shift.open',
            'till.open',
            'terminal.register',
            'manager.override',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $roles = [
            'cashier' => ['shift.open', 'till.open'],
            'server' => ['shift.open'],
            'supervisor' => ['shift.open', 'till.open', 'manager.override'],
            'manager' => ['shift.open', 'till.open', 'terminal.register', 'manager.override'],
            'admin' => $permissions,
        ];

        foreach ($roles as $role => $rolePermissions) {
            Role::firstOrCreate(['name' => $role])->syncPermissions($rolePermissions);
        }
    }
}
