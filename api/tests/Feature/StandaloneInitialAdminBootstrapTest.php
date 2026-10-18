<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\PosUser;
use PDO;
use Tests\TestCase;

class StandaloneInitialAdminBootstrapTest extends TestCase
{
    private const COMPANY_ID = '019fa200-0000-7000-8000-000000000001';

    private const MAIN_BRANCH_ID = '019fa200-0000-7000-8000-000000000002';

    private const OTHER_BRANCH_ID = '019fa200-0000-7000-8000-000000000003';

    private const USER_ID = '019fa200-0000-7000-8000-000000000004';

    private bool $databaseAvailable = false;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is required for this feature test.');
        }
        $this->databaseAvailable = true;

        $this->dropTables();
        $this->createTables();
        $this->seedProvisionedCompany();

        config()->set('salexa_sync.deployment_mode', 'standalone');
        config()->set('license.platform_admin_pos_user_ids', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        if ($this->databaseAvailable) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_command_creates_only_company_scoped_initial_admin_without_leaking_credentials(): void
    {
        $password = 'Installer-Only-Secret-94';
        $loginCode = '4826';
        $path = $this->credentialsFile(password: $password, loginCode: $loginCode);

        $this->assertSame(0, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $path,
        ]));
        $output = Artisan::output();
        $this->assertStringNotContainsString($password, $output);
        $this->assertStringNotContainsString($loginCode, $output);

        $user = PosUser::query()->with('role')->findOrFail(self::USER_ID);
        $this->assertSame(self::COMPANY_ID, $user->company_id);
        $this->assertSame(self::MAIN_BRANCH_ID, $user->branch_id);
        $this->assertSame('super_admin', $user->user_type);
        $this->assertSame('Super Admin', $user->role?->name);
        $this->assertSame(self::COMPANY_ID, $user->role?->company_id);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue(Hash::check($loginCode, $user->login_code));
        $this->assertTrue($user->can_access_front_office);
        $this->assertTrue($user->can_access_back_office);
        $this->assertFalse(app(SuperAdminService::class)->isPlatformAdministrator($user));

        $this->assertDatabaseHas('standalone_initial_admins', [
            'company_id' => self::COMPANY_ID,
            'pos_user_id' => self::USER_ID,
        ]);
        $this->assertDatabaseHas('user_branches', [
            'pos_user_id' => self::USER_ID,
            'branch_id' => self::MAIN_BRANCH_ID,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('user_branches', [
            'pos_user_id' => self::USER_ID,
            'branch_id' => self::OTHER_BRANCH_ID,
            'is_default' => false,
        ]);

        $permissionCount = DB::table('permissions')->count();
        $this->assertGreaterThan(0, $permissionCount);
        $this->assertSame(
            $permissionCount,
            DB::table('role_permissions')->where('role_id', $user->role_id)->count()
        );
    }

    public function test_command_bootstraps_a_verify_only_local_sync_hub_without_platform_access(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);
        config()->set('license.platform_admin_pos_user_ids', [self::USER_ID]);

        $this->assertSame(0, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(),
        ]));

        $user = PosUser::query()->findOrFail(self::USER_ID);
        $this->assertSame(self::COMPANY_ID, $user->company_id);
        $this->assertFalse(app(SuperAdminService::class)->isPlatformAdministrator($user));
        $this->assertDatabaseCount('standalone_initial_admins', 1);
    }

    public function test_command_fails_closed_when_company_user_data_already_exists(): void
    {
        DB::table('roles')->insert([
            'id' => '019fa200-0000-7000-8000-000000000010',
            'company_id' => self::COMPANY_ID,
            'name' => 'Cashier',
            'group' => null,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_users')->insert($this->posUserRow(
            '019fa200-0000-7000-8000-000000000011',
            'existing.user',
            '019fa200-0000-7000-8000-000000000010'
        ));

        $this->assertSame(1, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(),
        ]));
        $this->assertStringContainsString('company users already exist', Artisan::output());
        $this->assertDatabaseMissing('pos_users', ['id' => self::USER_ID]);
    }

    public function test_rotation_targets_only_recorded_user_and_stops_after_another_user_exists(): void
    {
        $this->assertSame(0, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(),
        ]));

        $rotatedPassword = 'Rotated-Installer-Secret-88';
        $this->assertSame(1, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(password: $rotatedPassword),
        ]));
        $this->assertStringContainsString('--rotate-existing', Artisan::output());

        $this->assertSame(0, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(password: $rotatedPassword),
            '--rotate-existing' => true,
        ]));
        $this->assertTrue(Hash::check(
            $rotatedPassword,
            (string) DB::table('pos_users')->where('id', self::USER_ID)->value('password')
        ));

        DB::table('pos_users')->insert($this->posUserRow(
            '019fa200-0000-7000-8000-000000000012',
            'second.user',
            (string) DB::table('pos_users')->where('id', self::USER_ID)->value('role_id')
        ));
        $this->assertSame(1, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(password: 'Another-Installer-Secret-77'),
            '--rotate-existing' => true,
        ]));
        $this->assertStringContainsString('no longer pristine', Artisan::output());
    }

    public function test_command_rejects_non_standalone_deployment_and_wrong_company_identity(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        $this->assertSame(1, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(),
        ]));
        $this->assertStringContainsString('only on a standalone or local Back Office installation', Artisan::output());

        config()->set('salexa_sync.deployment_mode', 'standalone');
        $this->assertSame(1, Artisan::call('salexa:standalone-bootstrap-admin', [
            'credentials' => $this->credentialsFile(companyId: '019fa299-0000-7000-8000-000000000001'),
        ]));
        $this->assertStringContainsString('exactly one local company', Artisan::output());
    }

    private function credentialsFile(
        string $password = 'Installer-Only-Secret-93',
        ?string $loginCode = null,
        string $companyId = self::COMPANY_ID,
    ): string {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'salexa-admin-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'company_id' => $companyId,
            'user' => array_filter([
                'id' => self::USER_ID,
                'name' => 'Customer Administrator',
                'username' => 'customer.admin',
                'password' => $password,
                'login_code' => $loginCode,
                'branch_id' => null,
            ], static fn ($value) => $value !== null),
        ], JSON_THROW_ON_ERROR));
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return array<string,mixed> */
    private function posUserRow(string $id, string $username, string $roleId): array
    {
        return [
            'id' => $id,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::MAIN_BRANCH_ID,
            'role_id' => $roleId,
            'name' => 'Existing User',
            'username' => $username,
            'phone' => null,
            'password' => Hash::make('Existing-User-Secret-12'),
            'employee_code' => null,
            'user_type' => 'cashier',
            'can_login_pos' => true,
            'can_access_front_office' => true,
            'can_access_back_office' => false,
            'is_active' => true,
            'last_login_at' => null,
            'login_code' => null,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    private function seedProvisionedCompany(): void
    {
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'company_code' => 'CMP-TEST',
            'company_name' => 'Test Company',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::table('company_branches')->insert([
            [
                'id' => self::MAIN_BRANCH_ID,
                'company_id' => self::COMPANY_ID,
                'branch_name' => 'Main',
                'branch_code' => 'MAIN',
                'is_main_branch' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => self::OTHER_BRANCH_ID,
                'company_id' => self::COMPANY_ID,
                'branch_name' => 'Second',
                'branch_code' => 'SECOND',
                'is_main_branch' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
        DB::table('standalone_provisioning_imports')->insert([
            'package_id' => '019fa200-0000-7000-8000-000000000099',
            'company_id' => self::COMPANY_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code');
            $table->string('company_name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->boolean('is_main_branch')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('standalone_provisioning_imports', function (Blueprint $table): void {
            $table->uuid('package_id')->primary();
            $table->uuid('company_id');
            $table->timestamps();
        });
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
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->uuid('company_id')->nullable();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id');
            $table->string('name');
            $table->string('username');
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('employee_code')->nullable();
            $table->string('user_type')->default('cashier');
            $table->boolean('can_login_pos')->default(true);
            $table->boolean('can_access_front_office')->default(false);
            $table->boolean('can_access_back_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('login_code')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'username']);
        });
        Schema::create('user_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['pos_user_id', 'branch_id']);
        });
        Schema::create('standalone_initial_admins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->uuid('pos_user_id')->unique();
            $table->timestamp('bootstrapped_at');
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'standalone_initial_admins',
            'user_branches',
            'pos_users',
            'role_permissions',
            'permissions',
            'roles',
            'standalone_provisioning_imports',
            'company_branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
