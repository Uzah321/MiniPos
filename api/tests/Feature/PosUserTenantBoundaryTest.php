<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\TransientToken;
use Modules\Authentication\Http\Controllers\PosUserController;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\RolesAndPermission\Models\Role;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

class PosUserTenantBoundaryTest extends TestCase
{
    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';

    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';

    private const ROLE_ID = '10000000-0000-7000-8000-000000000002';

    private const OTHER_ROLE_ID = '20000000-0000-7000-8000-000000000002';

    private const PLATFORM_ROLE_ID = '20000000-0000-7000-8000-000000000003';

    private const BRANCH_ID = '10000000-0000-7000-8000-000000000003';

    private const OTHER_BRANCH_ID = '20000000-0000-7000-8000-000000000003';

    private const SHIFT_ID = '10000000-0000-7000-8000-000000000004';

    private const OTHER_SHIFT_ID = '20000000-0000-7000-8000-000000000004';

    private const USER_ID = '10000000-0000-7000-8000-000000000005';

    private const OTHER_USER_ID = '20000000-0000-7000-8000-000000000005';

    private const PLATFORM_USER_ID = '20000000-0000-7000-8000-000000000006';

    private bool $databaseAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $company = new Company;
        $company->forceFill(['id' => self::COMPANY_ID]);
        $company->exists = true;
        app()->instance('company', $company);

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('license.platform_admin_pos_user_ids', [self::PLATFORM_USER_ID]);

        $actor = new PosUser;
        $actor->forceFill([
            'id' => self::PLATFORM_USER_ID,
            'company_id' => self::OTHER_COMPANY_ID,
            'role_id' => self::PLATFORM_ROLE_ID,
            'name' => 'Platform Owner',
            'user_type' => 'super_admin',
            'is_active' => true,
        ]);
        $actor->exists = true;
        $actor->withAccessToken(new TransientToken);
        $actor->setRelation('role', new Role([
            'company_id' => self::OTHER_COMPANY_ID,
            'name' => 'Super Admin',
        ]));
        Auth::setUser($actor);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        app()->forgetInstance('company');

        if ($this->databaseAvailable) {
            foreach ([
                'pos_user_shift_assignments',
                'user_branches',
                'pos_users',
                'shiftconfigurations',
                'company_branches',
                'role_permissions',
                'permissions',
                'roles',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_store_rejects_foreign_company_assignment_identifiers_and_company_override(): void
    {
        $this->prepareDatabase();

        $response = app(PosUserController::class)->store(Request::create('/api/pos-users', 'POST', [
            'company_id' => self::OTHER_COMPANY_ID,
            'branch_id' => self::OTHER_BRANCH_ID,
            'role_id' => self::OTHER_ROLE_ID,
            'name' => 'Foreign Assignment Attempt',
            'username' => 'foreign-assignment',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'branch_ids' => [self::OTHER_BRANCH_ID],
            'default_branch_id' => self::OTHER_BRANCH_ID,
            'shift_assignments' => [[
                'shift_configuration_id' => self::OTHER_SHIFT_ID,
                'shift_name' => 'manual',
            ]],
        ]));

        $this->assertSame(422, $response->getStatusCode(), json_encode($response->getData(true)));
        $errors = $response->getData(true)['errors'];
        foreach ([
            'company_id',
            'branch_id',
            'role_id',
            'branch_ids.0',
            'default_branch_id',
            'shift_assignments.0.shift_configuration_id',
        ] as $field) {
            $this->assertArrayHasKey($field, $errors, "{$field} accepted a foreign-company identifier.");
        }
        $this->assertDatabaseMissing('pos_users', ['username' => 'foreign-assignment']);
    }

    public function test_store_validation_accepts_same_company_assignment_identifiers(): void
    {
        $this->prepareDatabase();

        $response = app(PosUserController::class)->store(Request::create('/api/pos-users', 'POST', [
            'branch_id' => self::BRANCH_ID,
            'role_id' => self::ROLE_ID,
            'username' => 'same-company-assignment',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'branch_ids' => [self::BRANCH_ID],
            'default_branch_id' => self::BRANCH_ID,
            'shift_assignments' => [[
                'shift_configuration_id' => self::SHIFT_ID,
                'shift_name' => 'manual',
            ]],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $errors = $response->getData(true)['errors'];
        $this->assertArrayHasKey('name', $errors);
        foreach ([
            'branch_id',
            'role_id',
            'branch_ids.0',
            'default_branch_id',
            'shift_assignments.0.shift_configuration_id',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $errors, "{$field} rejected a same-company identifier.");
        }
    }

    public function test_update_rejects_company_id_even_when_the_target_is_in_scope(): void
    {
        $this->prepareDatabase();

        $response = app(PosUserController::class)->update(
            Request::create('/api/pos-users/'.self::USER_ID, 'PUT', [
                'company_id' => self::OTHER_COMPANY_ID,
            ]),
            self::USER_ID
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('company_id', $response->getData(true)['errors']);
        $this->assertDatabaseHas('pos_users', [
            'id' => self::USER_ID,
            'company_id' => self::COMPANY_ID,
        ]);
    }

    public function test_show_update_and_delete_cannot_resolve_a_foreign_company_user(): void
    {
        $this->prepareDatabase();

        $controller = app(PosUserController::class);
        $operations = [
            fn () => $controller->show(self::OTHER_USER_ID),
            fn () => $controller->update(
                Request::create('/api/pos-users/'.self::OTHER_USER_ID, 'PUT', ['name' => 'Hijacked']),
                self::OTHER_USER_ID
            ),
            fn () => $controller->destroy(self::OTHER_USER_ID),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A foreign-company POS user was resolved by a tenant endpoint.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('pos_users', [
            'id' => self::OTHER_USER_ID,
            'company_id' => self::OTHER_COMPANY_ID,
            'name' => 'Foreign User',
            'deleted_at' => null,
        ]);
    }

    public function test_platform_administrator_can_still_manage_a_user_in_the_selected_company(): void
    {
        $this->prepareDatabase();

        $this->assertTrue(app(SuperAdminService::class)->isPlatformAdministrator(Auth::user()));

        $response = app(PosUserController::class)->destroy(self::USER_ID);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSoftDeleted('pos_users', ['id' => self::USER_ID]);
    }

    public function test_user_routes_pass_raw_ids_for_controller_side_tenant_resolution(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === 'api/pos-users/{posUserId}'
                    && in_array($method, $route->methods(), true)
            );

            $this->assertNotNull($route, "The {$method} POS user route must use the tenant-safe ID binding.");
        }
    }

    public function test_assignment_validation_contract_is_scoped_to_the_resolved_company(): void
    {
        $store = $this->methodSource('store');
        $update = $this->methodSource('update');

        foreach ([$store, $update] as $source) {
            $this->assertStringContainsString("'company_id' => ['prohibited']", $source);
            $this->assertStringContainsString(
                "Rule::exists('roles', 'id')->where('company_id', \$company->id)",
                $source
            );
            $this->assertStringContainsString(
                "Rule::exists('company_branches', 'id')->where('company_id', \$company->id)",
                $source
            );
            $this->assertStringContainsString(
                "Rule::exists('shiftconfigurations', 'id')->where('company_id', \$company->id)",
                $source
            );
        }

        $this->assertStringNotContainsString("'company_id',\n            'branch_id'", $update);
    }

    public function test_show_update_and_delete_contract_re_resolves_the_user_in_company_scope(): void
    {
        $resolver = $this->methodSource('posUserForCompany');
        $this->assertStringContainsString("->where('company_id', \$companyId)", $resolver);
        $this->assertStringContainsString('->whereKey($posUserId)', $resolver);
        $this->assertStringContainsString('->firstOrFail()', $resolver);

        foreach (['show', 'update', 'destroy'] as $method) {
            $this->assertStringContainsString(
                '$this->posUserForCompany($posUserId, (string) $company->id)',
                $this->methodSource($method)
            );
        }
    }

    private function prepareDatabase(): void
    {
        $connection = (string) config('database.default');
        $pdoDriver = (string) config("database.connections.{$connection}.driver");
        if (! in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("The PDO {$pdoDriver} driver is required for this database-backed assertion.");
        }

        if ($this->databaseAvailable) {
            return;
        }

        $this->databaseAvailable = true;
        $this->createTables();
        $this->seedTenantData();
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(PosUserController::class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    private function createTables(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shiftconfigurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->boolean('enable_shifts')->default(true);
            $table->string('shift_mode')->default('manual');
            $table->boolean('allow_unscheduled_shifts')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id');
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('user_type')->default('cashier');
            $table->boolean('can_login_pos')->default(true);
            $table->boolean('can_access_front_office')->default(false);
            $table->boolean('can_access_back_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('login_code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('pos_user_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('pos_user_id');
            $table->uuid('shift_configuration_id');
            $table->string('shift_name');
            $table->timestamps();
        });
    }

    private function seedTenantData(): void
    {
        $now = now();
        DB::table('roles')->insert([
            [
                'id' => self::ROLE_ID,
                'company_id' => self::COMPANY_ID,
                'name' => 'Manager',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_ROLE_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'name' => 'Foreign Manager',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::PLATFORM_ROLE_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'name' => 'Super Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('company_branches')->insert([
            [
                'id' => self::BRANCH_ID,
                'company_id' => self::COMPANY_ID,
                'branch_name' => 'Tenant Branch',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_BRANCH_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'branch_name' => 'Foreign Branch',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('shiftconfigurations')->insert([
            [
                'id' => self::SHIFT_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_SHIFT_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'branch_id' => self::OTHER_BRANCH_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('pos_users')->insert([
            [
                'id' => self::USER_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'role_id' => self::ROLE_ID,
                'name' => 'Tenant User',
                'username' => 'tenant-user',
                'password' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_USER_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'branch_id' => self::OTHER_BRANCH_ID,
                'role_id' => self::OTHER_ROLE_ID,
                'name' => 'Foreign User',
                'username' => 'foreign-user',
                'password' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
