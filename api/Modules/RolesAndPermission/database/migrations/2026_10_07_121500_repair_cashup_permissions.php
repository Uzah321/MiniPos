<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<int, array{name: string, group: string, description: string}> */
    private array $permissions = [
        [
            'name' => 'cashup_shift',
            'group' => 'Sales',
            'description' => 'Cash up an open cashier shift.',
        ],
        [
            'name' => 'end_shift',
            'group' => 'Sales',
            'description' => 'Complete cashup and close a cashier shift.',
        ],
        [
            'name' => 'cashup_on_behalf',
            'group' => 'Sales',
            'description' => 'Cash up and close another cashier shift with a recorded reason.',
        ],
        [
            'name' => 'view_cashup_financial_amounts',
            'group' => 'Reports',
            'description' => 'View sensitive cashup financial amounts.',
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissions as $definition) {
            $existing = DB::table('permissions')->where('name', $definition['name'])->first();

            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update([
                    'group' => $definition['group'],
                    'description' => $definition['description'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $definition['name'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column($this->permissions, 'name'))
            ->pluck('id', 'name');

        $roles = DB::table('roles')->get();

        foreach ($roles as $role) {
            $normalisedRole = strtolower(str_replace([' ', '-'], '_', trim((string) $role->name)));
            $permissionNames = match (true) {
                in_array($normalisedRole, ['super_admin', 'super_administrator'], true) => array_keys($permissionIds->all()),
                // Operational till roles may cash up and close their own shifts.
                // On-behalf and financial visibility remain explicit grants.
                in_array($normalisedRole, ['cashier', 'manager', 'supervisor'], true) => ['cashup_shift', 'end_shift'],
                default => [],
            };

            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissionIds->get($permissionName);
                if (!$permissionId) {
                    continue;
                }

                $existingPivot = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permissionId)
                    ->first();

                $companyFields = Schema::hasColumn('role_permissions', 'company_id')
                    ? ['company_id' => $role->company_id]
                    : [];

                if ($existingPivot) {
                    DB::table('role_permissions')->where('id', $existingPivot->id)->update([
                        ...$companyFields,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                    ...$companyFields,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // This is an idempotent repair migration. The permission records may
        // predate it and may already be assigned to customer-defined roles, so a
        // rollback must not delete shared authorization data.
    }
};
