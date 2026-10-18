<?php

namespace Tests\Unit;

use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\PosUser;
use Modules\RolesAndPermission\Http\Controllers\RolesAndPermissionController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class PermissionCatalogueRepairTest extends TestCase
{
    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';

    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';

    private const BRANCH_ID = '10000000-0000-7000-8000-000000000002';

    private const LIVE_MANUFACTURING_PERMISSIONS = [
        'manufacturing_view_recipes',
        'manufacturing_manage_recipes',
        'manufacturing_view_reports',
        'manufacturing_create_plan',
        'manufacturing_approve_plan',
        'manufacturing_create_order',
        'manufacturing_approve_order',
        'manufacturing_issue_materials',
        'manufacturing_complete_order',
        'manufacturing_cancel_order',
        'manufacturing_add_overheads',
    ];

    private const REMOVED_RETAIL_PERMISSIONS = [
        'product_management_retail',
        'manufacturing_management_retail',
        'report_management_retail',
        'purchase_management_retail',
    ];

    /**
     * Explicit compatibility contract for custom manufacturing roles. Each
     * nested list is an alternative all-of legacy requirement set.
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

    private bool $permissionSchemaPrepared = false;

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        app()->forgetInstance('company');
        if ($this->permissionSchemaPrepared) {
            Schema::dropIfExists('role_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('user_branches');
            Schema::dropIfExists('pos_users');
            Schema::dropIfExists('roles');
        }

        parent::tearDown();
    }

    public function test_every_static_route_and_controller_permission_exists_in_the_seeder_catalogue(): void
    {
        $seederSource = file_get_contents(database_path('seeders/PermissionSeeder.php'));
        preg_match_all("/['\"]name['\"]\s*=>\s*['\"]([a-z0-9_]+)['\"]/", $seederSource, $seedMatches);
        $catalogue = array_values(array_unique($seedMatches[1]));
        $used = [];

        foreach ($this->permissionSourceFiles() as $file) {
            $source = $this->withoutPhpComments(file_get_contents($file));

            preg_match_all(
                "/(?:hasPermission|cashupPermission)\s*\(\s*[^,]+,\s*['\"]([a-z0-9_]+)['\"]/s",
                $source,
                $direct
            );
            $used = [...$used, ...$direct[1]];

            preg_match_all('/company\.permission:([a-z0-9_,]+)/', $source, $middleware);
            foreach ($middleware[1] as $permissionList) {
                $used = [...$used, ...explode(',', $permissionList)];
            }

            // ProductionOrderController delegates this permission to a helper
            // before the eventual hasPermission() call.
            preg_match_all(
                "/performStatusAction\s*\(\s*[^,]+,\s*[^,]+,\s*['\"]([a-z0-9_]+)['\"]/s",
                $source,
                $delegated
            );
            $used = [...$used, ...$delegated[1]];
        }

        $missing = array_values(array_diff(array_unique($used), $catalogue));
        sort($missing);

        $this->assertSame([], $missing, 'Permissions used by live routes/controllers are missing from PermissionSeeder.');
        foreach (self::LIVE_MANUFACTURING_PERMISSIONS as $permission) {
            $this->assertContains($permission, $catalogue);
        }
        $this->assertContains('view_quotation_modal', $catalogue);
        $this->assertContains('view_qoutation_modal', $catalogue);
        foreach (self::REMOVED_RETAIL_PERMISSIONS as $permission) {
            $this->assertNotContains($permission, $catalogue);
        }
    }

    public function test_legacy_to_live_manufacturing_mapping_is_an_explicit_compatibility_contract(): void
    {
        $migration = require base_path(
            'Modules/RolesAndPermission/database/migrations/2026_10_07_124500_migrate_permission_alias_assignments.php'
        );

        $reflection = new ReflectionClass($migration);
        $mapping = $reflection->getConstant('LEGACY_MANUFACTURING_REQUIREMENTS');

        $this->assertSame(self::LEGACY_MANUFACTURING_REQUIREMENTS, $mapping);
        $this->assertSame(self::LIVE_MANUFACTURING_PERMISSIONS, array_keys($mapping));
        $this->assertSame('view_quotation_modal', $reflection->getConstant('QUOTATION_PERMISSION'));
        $this->assertSame('view_qoutation_modal', $reflection->getConstant('LEGACY_QUOTATION_PERMISSION'));

        $matcher = $reflection->getMethod('matchesAnyRequirementSet');
        $matcher->setAccessible(true);
        foreach ($mapping as $alternatives) {
            $this->assertFalse($matcher->invoke($migration, [], $alternatives));
            foreach ($alternatives as $requirements) {
                $assigned = array_fill_keys($requirements, true);
                $this->assertTrue($matcher->invoke($migration, $assigned, $alternatives));
            }
        }

        $this->assertFalse($matcher->invoke(
            $migration,
            array_fill_keys(['create_production_recipes', 'update_production_recipes'], true),
            $mapping['manufacturing_manage_recipes']
        ));
    }

    public function test_alias_migration_preserves_legacy_grants_and_only_adds_explicit_equivalents(): void
    {
        $this->preparePermissionSchema();

        $permissionNames = [
            ...self::LIVE_MANUFACTURING_PERMISSIONS,
            'view_qoutation_modal',
            'manage_payment_types',
        ];
        foreach (self::LEGACY_MANUFACTURING_REQUIREMENTS as $alternatives) {
            foreach ($alternatives as $requirements) {
                $permissionNames = [...$permissionNames, ...$requirements];
            }
        }
        $permissionNames = array_values(array_unique($permissionNames));

        $permissionIds = [];
        foreach ($permissionNames as $index => $permissionName) {
            $permissionId = sprintf('30000000-0000-7000-8000-%012d', $index + 100);
            $permissionIds[$permissionName] = $permissionId;
            $this->insertPermission($permissionId, $permissionName);
        }

        $expected = [];
        $roleCounter = 100;
        $pivotCounter = 100;
        foreach (self::LEGACY_MANUFACTURING_REQUIREMENTS as $target => $alternatives) {
            foreach ($alternatives as $requirements) {
                $roleId = sprintf('40000000-0000-7000-8000-%012d', $roleCounter++);
                $this->insertRole($roleId, 'Legacy '.str_replace('_', ' ', $target));
                foreach ($requirements as $requirement) {
                    $this->insertPivot(
                        sprintf('50000000-0000-7000-8000-%012d', $pivotCounter++),
                        $roleId,
                        $permissionIds[$requirement]
                    );
                }
                $expected[] = [$roleId, $target];
            }
        }

        $quotationRole = '40000000-0000-7000-8000-000000000200';
        $legacyQuotationPivot = '50000000-0000-7000-8000-000000000200';
        $this->insertRole($quotationRole, 'Legacy Quotation User');
        $this->insertPivot(
            $legacyQuotationPivot,
            $quotationRole,
            $permissionIds['view_qoutation_modal']
        );

        $partialRecipeRole = '40000000-0000-7000-8000-000000000201';
        $this->insertRole($partialRecipeRole, 'Partial Recipe Editor');
        foreach (['create_production_recipes', 'update_production_recipes'] as $requirement) {
            $this->insertPivot(
                sprintf('50000000-0000-7000-8000-%012d', $pivotCounter++),
                $partialRecipeRole,
                $permissionIds[$requirement]
            );
        }

        $unrelatedRole = '40000000-0000-7000-8000-000000000202';
        $this->insertRole($unrelatedRole, 'Legacy Payment Type Manager');
        $this->insertPivot(
            '50000000-0000-7000-8000-000000000202',
            $unrelatedRole,
            $permissionIds['manage_payment_types']
        );

        $migrationPath = base_path(
            'Modules/RolesAndPermission/database/migrations/2026_10_07_124500_migrate_permission_alias_assignments.php'
        );
        (require $migrationPath)->up();
        (require $migrationPath)->up();

        foreach ($expected as [$roleId, $target]) {
            $this->assertSame(
                1,
                DB::table('role_permissions as assigned')
                    ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                    ->where('assigned.role_id', $roleId)
                    ->where('assigned.company_id', self::COMPANY_ID)
                    ->where('permission.name', $target)
                    ->count(),
                "Legacy role was not migrated to {$target}."
            );
        }

        $this->assertDatabaseHas('role_permissions', ['id' => $legacyQuotationPivot]);
        $this->assertSame(
            1,
            DB::table('role_permissions as assigned')
                ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                ->where('assigned.role_id', $quotationRole)
                ->where('permission.name', 'view_quotation_modal')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('role_permissions as assigned')
                ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                ->where('assigned.role_id', $partialRecipeRole)
                ->where('permission.name', 'manufacturing_manage_recipes')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('role_permissions as assigned')
                ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                ->where('assigned.role_id', $unrelatedRole)
                ->whereIn('permission.name', [
                    'view_payment_type',
                    'create_payment_type',
                    'update_payment_type',
                    'delete_payment_type',
                ])
                ->count()
        );
    }

    public function test_catalogue_repair_is_idempotent_and_only_preserves_roles_that_were_already_complete(): void
    {
        $this->preparePermissionSchema();

        $legacyPermissions = [
            '30000000-0000-7000-8000-000000000001' => 'view_currency',
            '30000000-0000-7000-8000-000000000002' => 'cashup_shift',
            '30000000-0000-7000-8000-000000000003' => 'legacy_extra',
        ];
        foreach ($legacyPermissions as $id => $name) {
            $this->insertPermission($id, $name);
        }

        $completeRole = '40000000-0000-7000-8000-000000000001';
        $partialRole = '40000000-0000-7000-8000-000000000002';
        $this->insertRole($completeRole, 'Complete Manager');
        $this->insertRole($partialRole, 'Partial Manager');
        foreach (array_keys($legacyPermissions) as $index => $permissionId) {
            $this->insertPivot(
                sprintf('50000000-0000-7000-8000-%012d', $index + 1),
                $completeRole,
                $permissionId
            );
        }
        $this->insertPivot(
            '50000000-0000-7000-8000-000000000010',
            $partialRole,
            array_key_first($legacyPermissions)
        );

        $migrationPath = base_path(
            'Modules/RolesAndPermission/database/migrations/2026_10_07_123000_repair_live_permission_catalogue.php'
        );
        (require $migrationPath)->up();
        (require $migrationPath)->up();

        $this->assertSame(14, DB::table('permissions')->count());
        $this->assertSame(14, DB::table('role_permissions')->where('role_id', $completeRole)->count());
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $partialRole)->count());
        $this->assertSame(
            11,
            DB::table('role_permissions as assigned')
                ->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
                ->where('assigned.role_id', $completeRole)
                ->whereIn('permission.name', self::LIVE_MANUFACTURING_PERMISSIONS)
                ->where('assigned.company_id', self::COMPANY_ID)
                ->count()
        );

        $this->authenticateAs($completeRole);
        $this->assertTrue(\App\Helpers\hasPermission($completeRole, 'view_currency', self::COMPANY_ID));
        $this->assertTrue(\App\Helpers\hasPermission($completeRole, 'cashup_shift', self::COMPANY_ID));
        $wrongTenant = \App\Helpers\hasPermission($completeRole, 'view_currency', self::OTHER_COMPANY_ID);
        $this->assertSame(403, $wrongTenant->getStatusCode());
    }

    public function test_role_permission_sync_preserves_existing_pivot_ids_and_repairs_legacy_company_scope(): void
    {
        $this->preparePermissionSchema();

        $syncPermission = '30000000-0000-7000-8000-000000000011';
        $currencyPermission = '30000000-0000-7000-8000-000000000012';
        $cashupPermission = '30000000-0000-7000-8000-000000000013';
        $this->insertPermission($syncPermission, 'sync_role_permission');
        $this->insertPermission($currencyPermission, 'view_currency');
        $this->insertPermission($cashupPermission, 'cashup_shift');

        $actorRole = '40000000-0000-7000-8000-000000000011';
        $targetRole = '40000000-0000-7000-8000-000000000012';
        $this->insertRole($actorRole, 'Super Admin');
        $this->insertRole($targetRole, 'Manager');
        $existingPivot = '50000000-0000-7000-8000-000000000011';
        $this->insertPivot($existingPivot, $targetRole, $currencyPermission, null);

        $user = $this->authenticateAs($actorRole);
        app()->instance('company', (object) ['id' => self::COMPANY_ID]);
        $request = Request::create('/api/pos/roles/'.$targetRole.'/permissions/sync', 'PUT', [
            'permission_ids' => [$currencyPermission, $cashupPermission, $cashupPermission],
        ]);
        $request->setUserResolver(static fn () => $user);

        $controller = new RolesAndPermissionController;
        $this->assertSame(200, $controller->syncRolePermissions($request, $targetRole)->getStatusCode());
        $firstIds = DB::table('role_permissions')
            ->where('role_id', $targetRole)
            ->orderBy('permission_id')
            ->pluck('id', 'permission_id')
            ->all();

        $this->assertSame($existingPivot, $firstIds[$currencyPermission]);
        $this->assertSame(
            self::COMPANY_ID,
            DB::table('role_permissions')->where('id', $existingPivot)->value('company_id')
        );
        $this->assertCount(2, $firstIds);

        $this->assertSame(200, $controller->syncRolePermissions($request, $targetRole)->getStatusCode());
        $this->assertSame(
            $firstIds,
            DB::table('role_permissions')
                ->where('role_id', $targetRole)
                ->orderBy('permission_id')
                ->pluck('id', 'permission_id')
                ->all()
        );
    }

    public function test_edge_apply_reconciles_role_permission_by_natural_key(): void
    {
        $this->preparePermissionSchema();

        $permissionId = '30000000-0000-7000-8000-000000000021';
        $roleId = '40000000-0000-7000-8000-000000000021';
        $this->insertPermission($permissionId, 'view_currency');
        $this->insertRole($roleId, 'Manager');
        $this->insertPivot('50000000-0000-7000-8000-000000000021', $roleId, $permissionId);

        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $definitions = $reflection->getConstant('DATASETS');
        $datasets = array_fill_keys(array_keys($definitions), []);
        $datasets['permissions'] = [[
            'id' => $permissionId,
            'name' => 'view_currency',
            'group' => 'settings',
            'description' => 'View Currency',
        ]];
        $datasets['roles'] = [[
            'id' => $roleId,
            'company_id' => self::COMPANY_ID,
            'name' => 'Manager',
            'group' => 'operations',
            'description' => null,
        ]];
        $cloudPivotId = '50000000-0000-7000-8000-000000000022';
        $datasets['role_permissions'] = [[
            'id' => $cloudPivotId,
            'company_id' => self::COMPANY_ID,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]];

        $hasher = new SyncPayloadHasher;
        $state = new SyncLocalState([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
        ]);
        $service = new SyncConfigurationService($hasher);
        $service->applySnapshot($state, [
            'schema_version' => 1,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'content_hash' => $hasher->hash($datasets),
            'datasets' => $datasets,
            'tombstones' => [],
        ]);

        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $roleId)->count());
        $this->assertSame(
            $cloudPivotId,
            DB::table('role_permissions')->where('role_id', $roleId)->value('id')
        );
        $this->assertSame('global-catalogue', $definitions['permissions']['scope']);
    }

    public function test_complete_snapshot_prunes_edge_only_permissions_for_in_scope_roles_only(): void
    {
        $this->preparePermissionSchema();

        $keptPermission = '30000000-0000-7000-8000-000000000025';
        $edgeOnlyPermission = '30000000-0000-7000-8000-000000000026';
        $tenantRole = '40000000-0000-7000-8000-000000000025';
        $foreignRole = '40000000-0000-7000-8000-000000000026';
        $edgeOnlyPivot = '50000000-0000-7000-8000-000000000025';
        $foreignPivot = '50000000-0000-7000-8000-000000000026';
        $this->insertPermission($keptPermission, 'view_currency');
        $this->insertPermission($edgeOnlyPermission, 'cashup_shift');
        $this->insertRole($tenantRole, 'Tenant Manager');
        $this->insertRole($foreignRole, 'Foreign Manager', self::OTHER_COMPANY_ID);
        $this->insertPivot(
            '50000000-0000-7000-8000-000000000027',
            $tenantRole,
            $keptPermission
        );
        $this->insertPivot($edgeOnlyPivot, $tenantRole, $edgeOnlyPermission);
        // Deliberately use the paired company on this denormalized column: the
        // referenced role, not pivot.company_id, determines snapshot scope.
        $this->insertPivot($foreignPivot, $foreignRole, $edgeOnlyPermission, self::COMPANY_ID);

        $reflection = new ReflectionClass(SyncConfigurationService::class);
        $definitions = $reflection->getConstant('DATASETS');
        $datasets = array_fill_keys(array_keys($definitions), []);
        $datasets['permissions'] = [[
            'id' => $keptPermission,
            'name' => 'view_currency',
            'group' => 'settings',
            'description' => 'View Currency',
        ]];
        $datasets['roles'] = [[
            'id' => $tenantRole,
            'company_id' => self::COMPANY_ID,
            'name' => 'Tenant Manager',
            'group' => 'operations',
            'description' => null,
        ]];
        $cloudPivot = '50000000-0000-7000-8000-000000000028';
        $datasets['role_permissions'] = [[
            'id' => $cloudPivot,
            'company_id' => self::COMPANY_ID,
            'role_id' => $tenantRole,
            'permission_id' => $keptPermission,
        ]];

        $hasher = new SyncPayloadHasher;
        (new SyncConfigurationService($hasher))->applySnapshot(new SyncLocalState([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
        ]), [
            'schema_version' => 1,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'content_hash' => $hasher->hash($datasets),
            'datasets' => $datasets,
            'tombstones' => [],
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'id' => $cloudPivot,
            'role_id' => $tenantRole,
            'permission_id' => $keptPermission,
        ]);
        $this->assertDatabaseMissing('role_permissions', ['id' => $edgeOnlyPivot]);
        $this->assertDatabaseHas('role_permissions', [
            'id' => $foreignPivot,
            'role_id' => $foreignRole,
            'permission_id' => $edgeOnlyPermission,
        ]);
    }

    public function test_sync_projects_a_mismatched_legacy_pivot_only_through_its_tenant_scoped_role(): void
    {
        $this->preparePermissionSchema();

        $permissionId = '30000000-0000-7000-8000-000000000031';
        $tenantRole = '40000000-0000-7000-8000-000000000031';
        $foreignRole = '40000000-0000-7000-8000-000000000032';
        $legacyPivot = '50000000-0000-7000-8000-000000000031';
        $foreignPivot = '50000000-0000-7000-8000-000000000032';
        $this->insertPermission($permissionId, 'view_currency');
        $this->insertRole($tenantRole, 'Tenant Manager');
        $this->insertRole($foreignRole, 'Foreign Manager', self::OTHER_COMPANY_ID);
        $this->insertPivot($legacyPivot, $tenantRole, $permissionId, self::OTHER_COMPANY_ID);
        $this->insertPivot($foreignPivot, $foreignRole, $permissionId, self::COMPANY_ID);

        $datasets = (new SyncConfigurationService(new SyncPayloadHasher))->datasets(new SyncNode([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
        ]));
        $projectedPivots = collect($datasets['role_permissions'])->pluck('id')->all();

        $this->assertContains($legacyPivot, $projectedPivots);
        $this->assertNotContains($foreignPivot, $projectedPivots);
    }

    public function test_remove_permission_uses_the_tenant_role_and_natural_key_not_pivot_company_id(): void
    {
        $this->preparePermissionSchema();

        $permissionId = '30000000-0000-7000-8000-000000000041';
        $actorRole = '40000000-0000-7000-8000-000000000041';
        $tenantRole = '40000000-0000-7000-8000-000000000042';
        $foreignRole = '40000000-0000-7000-8000-000000000043';
        $tenantPivot = '50000000-0000-7000-8000-000000000041';
        $foreignPivot = '50000000-0000-7000-8000-000000000042';
        $this->insertPermission($permissionId, 'view_currency');
        $this->insertRole($actorRole, 'Super Admin');
        $this->insertRole($tenantRole, 'Tenant Manager');
        $this->insertRole($foreignRole, 'Foreign Manager', self::OTHER_COMPANY_ID);
        $this->insertPivot($tenantPivot, $tenantRole, $permissionId, self::OTHER_COMPANY_ID);
        $this->insertPivot($foreignPivot, $foreignRole, $permissionId, self::COMPANY_ID);

        $this->authenticateAs($actorRole);
        app()->instance('company', (object) ['id' => self::COMPANY_ID]);
        $controller = new RolesAndPermissionController;

        $this->assertSame(200, $controller->removeRolePermission($tenantRole, $permissionId)->getStatusCode());
        $this->assertFalse(DB::table('role_permissions')->where('id', $tenantPivot)->exists());
        $this->assertSame(404, $controller->removeRolePermission($foreignRole, $permissionId)->getStatusCode());
        $this->assertTrue(DB::table('role_permissions')->where('id', $foreignPivot)->exists());
    }

    /** @return array<int, string> */
    private function permissionSourceFiles(): array
    {
        $files = [];
        foreach ([app_path(), base_path('Modules')] as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $isApplicationFile = str_starts_with($path, str_replace('\\', '/', app_path()).'/');
                $isModuleContract = str_contains($path, '/Http/Controllers/')
                    || str_contains($path, '/routes/');
                if ($isApplicationFile || $isModuleContract) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function withoutPhpComments(string $source): string
    {
        $clean = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $clean .= $token[1];

                continue;
            }
            $clean .= $token;
        }

        return $clean;
    }

    private function preparePermissionSchema(): void
    {
        $driver = config('database.default');
        $pdoDriver = config("database.connections.{$driver}.driver");
        if (! in_array($pdoDriver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO driver {$pdoDriver} is not installed.");
        }

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('pos_users');
        Schema::dropIfExists('roles');

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('user_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
        $this->permissionSchemaPrepared = true;
    }

    private function insertPermission(string $id, string $name): void
    {
        DB::table('permissions')->insert([
            'id' => $id,
            'name' => $name,
            'group' => 'test',
            'description' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertRole(string $id, string $name, ?string $companyId = self::COMPANY_ID): void
    {
        DB::table('roles')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $name,
            'group' => 'test',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPivot(
        string $id,
        string $roleId,
        string $permissionId,
        ?string $companyId = self::COMPANY_ID
    ): void {
        DB::table('role_permissions')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authenticateAs(string $roleId): PosUser
    {
        $user = new PosUser;
        $user->forceFill([
            'id' => '60000000-0000-7000-8000-000000000001',
            'company_id' => self::COMPANY_ID,
            'role_id' => $roleId,
            'user_type' => 'cashier',
            'name' => 'Permission Test User',
        ]);
        $user->exists = true;
        Auth::setUser($user);

        return $user;
    }
}
