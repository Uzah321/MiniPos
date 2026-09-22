<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\Role;
use Modules\Authentication\Models\PasswordPolicy;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'shift.open',
            'till.open',
            'terminal.register',
            'manager.override',
            'countries.view', 'countries.create', 'countries.update',
            'brands.view', 'brands.create', 'brands.update',
            'complexes.view', 'complexes.create', 'complexes.update',
            'stores.view', 'stores.create', 'stores.update',
            'terminals.view', 'terminals.create', 'terminals.update',
            'users.view', 'users.create', 'users.update', 'users.assign',
            'roles.view', 'roles.create', 'roles.update',
            'permissions.view', 'permissions.create',
            'password-policies.view', 'password-policies.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['module' => str($permission)->before('.')->toString(), 'status' => 'active']
            );
        }

        $roles = [
            'cashier' => ['shift.open', 'till.open'],
            'server' => ['shift.open'],
            'supervisor' => ['shift.open', 'till.open', 'manager.override'],
            'manager' => ['shift.open', 'till.open', 'terminal.register', 'manager.override'],
            'admin' => $permissions,
            'super-admin' => $permissions,
        ];

        foreach ($roles as $role => $rolePermissions) {
            $model = Role::firstOrCreate(
                ['name' => $role, 'guard_name' => 'web'],
                ['status' => 'active', 'is_super_admin' => $role === 'super-admin']
            );
            $model->syncPermissions($rolePermissions);
        }

        PasswordPolicy::firstOrCreate(
            ['scope_type' => 'global', 'name' => 'Global secure default'],
            ['active' => true]
        );
    }
}
