<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use App\Services\Provisioning\PlatformAdministratorBootstrapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\PosUser;
use PDO;
use Tests\TestCase;

class PlatformAdministratorBootstrapTest extends TestCase
{
    private const USER_ID = '019fa300-0000-7000-8000-000000000004';

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

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', false);
        config()->set('license.platform_admin_pos_user_ids', [self::USER_ID]);
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

    public function test_command_creates_operator_company_and_platform_administrator_without_leaking_password(): void
    {
        $password = 'Installer-Only-Secret-94!';

        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(password: $password),
        ]));
        $output = Artisan::output();
        $this->assertStringNotContainsString($password, $output);
        $this->assertStringNotContainsString('PLATFORM_ADMIN_POS_USER_IDS', $output);

        $user = PosUser::query()->with('role')->findOrFail(self::USER_ID);
        $company = DB::table('companies')->where('id', $user->company_id)->first();
        $this->assertSame('SIMBISA-ZW', $company->company_code);
        $this->assertSame('Simbisa Brands Zimbabwe', $company->company_name);
        $this->assertSame('Zimbabwe', $company->country);
        $this->assertSame('Harare', $company->city);
        $this->assertDatabaseHas('countries', [
            'id' => $company->country_id,
            'code' => 'ZW',
            'currency_code' => 'USD',
            'timezone' => 'Africa/Harare',
        ]);
        $this->assertDatabaseHas('company_branches', [
            'id' => $user->branch_id,
            'company_id' => $user->company_id,
            'branch_code' => 'HQ',
            'is_main_branch' => true,
        ]);

        $this->assertSame('super_admin', $user->user_type);
        $this->assertSame(PlatformAdministratorBootstrapService::EMPLOYEE_CODE, $user->employee_code);
        $this->assertSame('Super Admin', $user->role?->name);
        $this->assertSame($user->company_id, $user->role?->company_id);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNull($user->login_code);
        $this->assertTrue($user->can_login_pos);
        $this->assertTrue($user->can_access_back_office);
        $this->assertFalse($user->can_access_front_office);
        $this->assertTrue(app(SuperAdminService::class)->isPlatformAdministrator($user));

        $this->assertDatabaseHas('user_branches', [
            'pos_user_id' => self::USER_ID,
            'branch_id' => $user->branch_id,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('user_access_assignments', [
            'pos_user_id' => self::USER_ID,
            'scope_type' => 'company',
            'scope_key' => 'company:'.$user->company_id,
        ]);

        $permissionCount = DB::table('permissions')->count();
        $this->assertGreaterThan(0, $permissionCount);
        $this->assertSame(
            $permissionCount,
            DB::table('role_permissions')->where('role_id', $user->role_id)->count()
        );
    }

    public function test_command_reuses_an_existing_home_country(): void
    {
        DB::table('countries')->insert([
            'id' => '019fa300-0000-7000-8000-000000000020',
            'code' => 'ZW',
            'iso_alpha_2' => 'ZW',
            'name' => 'Zimbabwe',
            'currency_code' => 'USD',
            'timezone' => 'Africa/Harare',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
        ]));

        $this->assertDatabaseCount('countries', 1);
        $this->assertSame(
            '019fa300-0000-7000-8000-000000000020',
            DB::table('companies')->value('country_id')
        );
    }

    public function test_second_bootstrap_requires_rotation_and_rotation_only_changes_the_designated_user(): void
    {
        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
        ]));

        $rotated = 'Rotated-Installer-Secret-88#';
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(password: $rotated),
        ]));
        $this->assertStringContainsString('--rotate-existing', Artisan::output());
        $this->assertDatabaseCount('companies', 1);

        // A password reset needs only the user section.
        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(password: $rotated, includeOrganisation: false),
            '--rotate-existing' => true,
        ]));
        $this->assertTrue(Hash::check(
            $rotated,
            (string) DB::table('pos_users')->where('id', self::USER_ID)->value('password')
        ));
        $this->assertSame(2, (int) DB::table('pos_users')->where('id', self::USER_ID)->value('credential_version'));

        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(
                password: $rotated,
                userId: '019fa300-0000-7000-8000-000000000099'
            ),
            '--rotate-existing' => true,
        ]));
        $this->assertStringContainsString('does not match', Artisan::output());
    }

    public function test_ensure_makes_a_retried_installation_idempotent(): void
    {
        // A first attempt that failed before the bootstrap ran: create.
        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
            '--ensure' => true,
        ]));
        $this->assertStringContainsString('created successfully', Artisan::output());

        // A retry after the bootstrap already succeeded: same administrator,
        // credentials set again, no second company.
        $retried = 'Retried-Installer-Secret-55$';
        $this->assertSame(0, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(password: $retried),
            '--ensure' => true,
        ]));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('pos_users', 1);
        $this->assertTrue(Hash::check(
            $retried,
            (string) DB::table('pos_users')->where('id', self::USER_ID)->value('password')
        ));

        // --ensure never adopts a different administrator.
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(userId: '019fa300-0000-7000-8000-000000000077'),
            '--ensure' => true,
        ]));
        $this->assertStringContainsString('does not match', Artisan::output());
    }

    public function test_command_rejects_edge_and_local_hub_deployments(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
        ]));
        $this->assertStringContainsString('only on a Central (cloud) Back Office', Artisan::output());

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
        ]));
        $this->assertDatabaseCount('pos_users', 0);
    }

    public function test_command_rejects_weak_passwords_and_taken_company_codes(): void
    {
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(password: 'alllowercasepassword'),
        ]));
        $this->assertStringContainsString('upper-case, lower-case, number and symbol', Artisan::output());

        // Creating (not rotating) still requires the country and company.
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(includeOrganisation: false),
        ]));
        $this->assertStringContainsString('country or company section is invalid', Artisan::output());

        DB::table('companies')->insert([
            'id' => '019fa300-0000-7000-8000-000000000030',
            'company_code' => 'SIMBISA-ZW',
            'company_name' => 'Existing',
            'location_address' => 'Somewhere',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(1, Artisan::call('servenest:bootstrap-platform-admin', [
            'credentials' => $this->credentialsFile(),
        ]));
        $this->assertStringContainsString('company code is already in use', Artisan::output());
        $this->assertDatabaseCount('pos_users', 0);
    }

    private function credentialsFile(
        string $password = 'Installer-Only-Secret-93!',
        string $userId = self::USER_ID,
        bool $includeOrganisation = true,
    ): string {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'servenest-platform-admin-'.bin2hex(random_bytes(6)).'.json';
        $document = [
            'schema_version' => 1,
            'user' => [
                'id' => $userId,
                'name' => 'Platform Owner',
                'username' => 'platform.owner',
                'password' => $password,
            ],
        ];
        if ($includeOrganisation) {
            $document['country'] = [
                'code' => 'zw',
                'name' => 'Zimbabwe',
                'currency_code' => 'USD',
                'timezone' => 'Africa/Harare',
            ];
            $document['company'] = [
                'code' => 'simbisa-zw',
                'name' => 'Simbisa Brands Zimbabwe',
                'city' => 'Harare',
            ];
        }
        // Windows installers write UTF-8 with a byte-order mark.
        file_put_contents($path, "\xEF\xBB\xBF".json_encode($document, JSON_THROW_ON_ERROR));
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function createTables(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();
            $table->char('iso_alpha_2', 2)->nullable()->unique();
            $table->char('iso_alpha_3', 3)->nullable()->unique();
            $table->string('name');
            $table->char('currency_code', 3)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('locale', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('country_id')->nullable();
            $table->string('company_code')->nullable();
            $table->string('company_name');
            $table->text('location_address');
            $table->string('city');
            $table->string('country');
            $table->string('domain')->nullable()->unique();
            $table->string('host_ip')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code')->nullable();
            $table->string('branch_address')->nullable();
            $table->string('branch_city')->nullable();
            $table->string('branch_country')->nullable();
            $table->string('level')->nullable();
            $table->boolean('is_main_branch')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('username')->unique();
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
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->timestamp('credentials_changed_at')->nullable();
            $table->rememberToken();
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
            $table->unique(['pos_user_id', 'branch_id']);
        });
        Schema::create('user_access_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->uuid('role_id');
            $table->string('scope_type', 32);
            $table->string('scope_key', 80);
            $table->uuid('country_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->uuid('granted_by')->nullable();
            $table->text('grant_reason')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'user_access_assignments',
            'user_branches',
            'pos_users',
            'role_permissions',
            'permissions',
            'roles',
            'company_branches',
            'companies',
            'countries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
