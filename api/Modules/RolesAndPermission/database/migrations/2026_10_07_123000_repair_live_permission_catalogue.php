<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'manufacturing_view_recipes' => 'View manufacturing recipes',
        'manufacturing_manage_recipes' => 'Manage manufacturing recipes',
        'manufacturing_view_reports' => 'View manufacturing reports',
        'manufacturing_create_plan' => 'Create manufacturing production plans',
        'manufacturing_approve_plan' => 'Approve manufacturing production plans',
        'manufacturing_create_order' => 'Create manufacturing production orders',
        'manufacturing_approve_order' => 'Approve manufacturing production orders',
        'manufacturing_issue_materials' => 'Issue manufacturing materials',
        'manufacturing_complete_order' => 'Complete manufacturing production orders',
        'manufacturing_cancel_order' => 'Cancel manufacturing production orders',
        'manufacturing_add_overheads' => 'Add manufacturing production overheads',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $names = array_keys(self::PERMISSIONS);
            $completeRoles = collect();

            if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
                // Exclude this migration's rows from the denominator so a
                // retry remains idempotent. Only roles that held every prior
                // catalogue permission inherit newly introduced permissions.
                $legacyPermissionCount = DB::table('permissions')
                    ->whereNotIn('name', $names)
                    ->count();

                if ($legacyPermissionCount > 0) {
                    $completeRoles = DB::table('roles as roles')
                        ->join('role_permissions as assigned', 'assigned.role_id', '=', 'roles.id')
                        ->join('permissions as catalogue', 'catalogue.id', '=', 'assigned.permission_id')
                        ->whereNotIn('catalogue.name', $names)
                        ->groupBy('roles.id', 'roles.company_id')
                        ->havingRaw('COUNT(DISTINCT catalogue.id) = ?', [$legacyPermissionCount])
                        ->get(['roles.id', 'roles.company_id']);
                }
            }

            $now = now();
            foreach (self::PERMISSIONS as $name => $description) {
                if (DB::table('permissions')->where('name', $name)->exists()) {
                    DB::table('permissions')->where('name', $name)->update([
                        'group' => 'manufacturing',
                        'description' => $description,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'group' => 'manufacturing',
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($completeRoles->isEmpty()) {
                return;
            }

            $permissions = DB::table('permissions')
                ->whereIn('name', $names)
                ->get(['id']);
            $hasCompanyId = Schema::hasColumn('role_permissions', 'company_id');

            foreach ($completeRoles as $role) {
                foreach ($permissions as $permission) {
                    $existing = DB::table('role_permissions')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permission->id)
                        ->first(['id']);

                    if ($existing) {
                        $updates = ['updated_at' => $now];
                        if ($hasCompanyId) {
                            $updates['company_id'] = $role->company_id;
                        }
                        DB::table('role_permissions')->where('id', $existing->id)->update($updates);

                        continue;
                    }

                    $pivot = [
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if ($hasCompanyId) {
                        $pivot['company_id'] = $role->company_id;
                    }
                    DB::table('role_permissions')->insert($pivot);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Some installations may already have
        // these permission names and user-managed assignments; a rollback of
        // this repair must not revoke valid authorization data.
    }
};
