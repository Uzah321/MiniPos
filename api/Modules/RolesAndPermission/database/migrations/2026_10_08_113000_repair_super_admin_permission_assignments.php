<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('role_permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $roles = DB::table('roles')
                ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")
                ->get(['id', 'company_id']);
            $permissions = DB::table('permissions')->get(['id']);
            $hasCompanyId = Schema::hasColumn('role_permissions', 'company_id');
            $now = now();

            foreach ($roles as $role) {
                $existing = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->get(['id', 'permission_id', ...($hasCompanyId ? ['company_id'] : [])])
                    ->keyBy(static fn (object $pivot): string => (string) $pivot->permission_id);

                foreach ($permissions as $permission) {
                    $pivot = $existing->get((string) $permission->id);

                    if ($pivot) {
                        if ($hasCompanyId && (string) $pivot->company_id !== (string) $role->company_id) {
                            DB::table('role_permissions')->where('id', $pivot->id)->update([
                                'company_id' => $role->company_id,
                                'updated_at' => $now,
                            ]);
                        }

                        continue;
                    }

                    $attributes = [
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if ($hasCompanyId) {
                        $attributes['company_id'] = $role->company_id;
                    }

                    DB::table('role_permissions')->insert($attributes);
                }
            }
        });
    }

    public function down(): void
    {
        // Non-destructive repair: these roles already bypass the same
        // permission checks server-side, and assignments may be in active use.
    }
};
