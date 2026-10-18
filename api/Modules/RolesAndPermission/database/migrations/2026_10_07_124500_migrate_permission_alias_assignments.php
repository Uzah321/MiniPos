<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const QUOTATION_PERMISSION = 'view_quotation_modal';

    private const LEGACY_QUOTATION_PERMISSION = 'view_qoutation_modal';

    /**
     * Each target is granted only when a role satisfies every permission in
     * one of its alternative legacy requirement sets. A one-item set is a
     * direct semantic alias; recipe management deliberately requires the
     * complete legacy create/update/delete capability instead of broadening a
     * single narrow grant into full recipe management.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private const LEGACY_MANUFACTURING_REQUIREMENTS = [
        'manufacturing_view_recipes' => [
            ['view_production_recipes'],
            ['view_manufacturing_recipes'],
        ],
        'manufacturing_manage_recipes' => [
            ['create_production_recipes', 'update_production_recipes', 'delete_production_recipes'],
            ['create_manufacturing_recipes', 'update_manufacturing_recipes', 'delete_manufacturing_recipes'],
        ],
        'manufacturing_view_reports' => [
            ['view_manufacturing_reports'],
        ],
        'manufacturing_create_plan' => [
            ['create_production_plans'],
        ],
        'manufacturing_approve_plan' => [
            ['approve_production_plans'],
        ],
        'manufacturing_create_order' => [
            ['create_production_orders'],
        ],
        'manufacturing_approve_order' => [
            ['approve_production_orders'],
        ],
        'manufacturing_issue_materials' => [
            ['issue_raw_materials'],
        ],
        'manufacturing_complete_order' => [
            ['complete_production_orders'],
        ],
        'manufacturing_cancel_order' => [
            ['cancel_production_orders'],
        ],
        'manufacturing_add_overheads' => [
            ['add_production_overheads'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            $canonicalQuotation = DB::table('permissions')
                ->where('name', self::QUOTATION_PERMISSION)
                ->first(['id']);

            if ($canonicalQuotation) {
                DB::table('permissions')
                    ->where('id', $canonicalQuotation->id)
                    ->update([
                        'group' => 'modal-permissions',
                        'description' => 'View Quotation Modal',
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => self::QUOTATION_PERMISSION,
                    'group' => 'modal-permissions',
                    'description' => 'View Quotation Modal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
                return;
            }

            $legacyNames = [self::LEGACY_QUOTATION_PERMISSION];
            foreach (self::LEGACY_MANUFACTURING_REQUIREMENTS as $alternatives) {
                foreach ($alternatives as $requirements) {
                    $legacyNames = [...$legacyNames, ...$requirements];
                }
            }

            $targetNames = [
                self::QUOTATION_PERMISSION,
                ...array_keys(self::LEGACY_MANUFACTURING_REQUIREMENTS),
            ];
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_values(array_unique([...$legacyNames, ...$targetNames])))
                ->pluck('id', 'name');

            $assignedByRole = DB::table('role_permissions as assigned')
                ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                ->whereIn('permission.name', array_values(array_unique($legacyNames)))
                ->get(['assigned.role_id', 'permission.name'])
                ->groupBy('role_id')
                ->map(static fn ($rows): array => array_fill_keys($rows->pluck('name')->all(), true));

            if ($assignedByRole->isEmpty()) {
                return;
            }

            $roles = DB::table('roles')
                ->whereIn('id', $assignedByRole->keys()->all())
                ->get(['id', 'company_id'])
                ->keyBy('id');
            $hasCompanyId = Schema::hasColumn('role_permissions', 'company_id');

            foreach ($assignedByRole as $roleId => $assignedNames) {
                $role = $roles->get($roleId);
                if (! $role) {
                    continue;
                }

                if (isset($assignedNames[self::LEGACY_QUOTATION_PERMISSION])) {
                    $this->grantPermission(
                        $role,
                        $permissionIds->get(self::QUOTATION_PERMISSION),
                        $hasCompanyId,
                        $now
                    );
                }

                foreach (self::LEGACY_MANUFACTURING_REQUIREMENTS as $target => $alternatives) {
                    if (! $this->matchesAnyRequirementSet($assignedNames, $alternatives)) {
                        continue;
                    }

                    $this->grantPermission(
                        $role,
                        $permissionIds->get($target),
                        $hasCompanyId,
                        $now
                    );
                }
            }
        });
    }

    /**
     * @param  array<string, bool>  $assignedNames
     * @param  array<int, array<int, string>>  $alternatives
     */
    private function matchesAnyRequirementSet(array $assignedNames, array $alternatives): bool
    {
        foreach ($alternatives as $requirements) {
            $matches = true;
            foreach ($requirements as $requiredPermission) {
                if (! isset($assignedNames[$requiredPermission])) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function grantPermission(object $role, ?string $permissionId, bool $hasCompanyId, $now): void
    {
        if (! $permissionId) {
            return;
        }

        $existing = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $permissionId)
            ->first(['id']);

        $companyFields = $hasCompanyId ? ['company_id' => $role->company_id] : [];

        if ($existing) {
            DB::table('role_permissions')->where('id', $existing->id)->update([
                ...$companyFields,
                'updated_at' => $now,
            ]);

            return;
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

    public function down(): void
    {
        // Intentionally non-destructive: canonical assignments may have been
        // used or edited after migration, and the legacy aliases remain valid
        // throughout the transition.
    }
};
