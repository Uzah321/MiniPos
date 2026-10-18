<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use App\Services\CompanySettingService;
use App\Services\License\LicenseAccessService;
use App\Services\License\LicenseService;
use App\Services\Shifts\ShiftPolicyService;
use App\Services\Shifts\ShiftResolverService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Http\Controllers\PosUserController;
use Modules\Authentication\Models\PosUserShiftSession;
use Modules\Authentication\Models\ShiftConfiguration;
use Modules\Authentication\Services\LoginCodeBlindIndex;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Models\PosTill;
use PDO;
use Tests\TestCase;

class PosCodeLoginShiftTest extends TestCase
{
    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';

    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';

    private const BRANCH_ID = '10000000-0000-7000-8000-000000000002';

    private const ROLE_ID = '10000000-0000-7000-8000-000000000003';

    private const USER_ID = '10000000-0000-7000-8000-000000000004';

    private const OTHER_USER_ID = '20000000-0000-7000-8000-000000000004';

    private const SHIFT_CONFIG_ID = '10000000-0000-7000-8000-000000000005';

    private const TILL_ID = '10000000-0000-7000-8000-000000000006';

    private const OTHER_TILL_ID = '10000000-0000-7000-8000-000000000009';

    private bool $databaseAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        if (! in_array($driver, PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("The PDO {$driver} driver is required for this database-backed assertion.");
        }

        $this->databaseAvailable = true;
        $this->createTables();
        $this->seedLoginContext();

        config()->set('salexa_sync.deployment_mode', 'standalone');
        config()->set('license.platform_admin_pos_user_ids', []);
        config()->set('authentication.security.login_code_blind_index.active_key_id', 'test-v1');
        config()->set('authentication.security.login_code_blind_index.keys', [
            'test-v1' => 'test-login-code-lookup-key-material',
        ]);

        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));

        $till = new PosTill;
        $till->forceFill([
            'id' => self::TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'is_registered' => true,
            'is_active' => true,
        ]);
        $till->exists = true;
        app()->instance('till', $till);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance('till');
        app()->forgetInstance(CompanySettingService::class);

        if ($this->databaseAvailable) {
            foreach ([
                'personal_access_tokens',
                'pos_user_shift_sessions',
                'pos_user_shift_assignments',
                'shiftconfigurations',
                'user_branches',
                'pos_users',
                'role_permissions',
                'permissions',
                'roles',
                'company_branches',
                'companies',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_valid_code_without_opening_float_opens_a_zero_float_till_shift(): void
    {
        $response = $this->controller()->login($this->loginRequest([
            'login_code' => '1234',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('Login successful', $payload['message']);
        $this->assertFalse($payload['is_company_super_admin']);
        $this->assertFalse($payload['user']['is_company_super_admin']);
        $this->assertFalse($payload['is_platform_admin']);
        $this->assertSame(self::TILL_ID, $payload['active_shift']['till_id']);
        $this->assertSame(0.0, (float) $payload['active_shift']['opening_float']);
        $this->assertSame(1, DB::table('pos_user_shift_sessions')->count());
        $this->assertNotNull(DB::table('pos_users')->where('id', self::USER_ID)->value('login_code_lookup'));
        $this->assertSame(
            'test-v1',
            DB::table('pos_users')->where('id', self::USER_ID)->value('login_code_lookup_key_id')
        );
        $this->assertDatabaseHas('pos_user_shift_sessions', [
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'pos_user_id' => self::USER_ID,
            'shift_configuration_id' => self::SHIFT_CONFIG_ID,
            'shift_name' => 'manual',
            'opening_float' => 0,
            'status' => 'open',
        ]);
    }

    public function test_company_super_admin_flag_is_separate_from_platform_ownership(): void
    {
        $response = $this->controller(companySuperAdmin: true)->login($this->loginRequest([
            'login_code' => '1234',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['is_company_super_admin']);
        $this->assertTrue($payload['user']['is_company_super_admin']);
        $this->assertFalse($payload['is_platform_admin']);
        $this->assertFalse($payload['user']['is_platform_admin']);
    }

    public function test_code_login_ignores_a_caller_supplied_opening_float(): void
    {
        $response = $this->controller()->login($this->loginRequest([
            'login_code' => '1234',
            'opening_float' => 99.00,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0.0, (float) $response->getData(true)['active_shift']['opening_float']);
        $this->assertSame(0.0, (float) DB::table('pos_user_shift_sessions')->value('opening_float'));
    }

    public function test_code_login_resumes_the_same_till_shift_without_replacing_its_float(): void
    {
        $shift = PosUserShiftSession::query()->create([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'shift_configuration_id' => self::SHIFT_CONFIG_ID,
            'pos_user_id' => self::USER_ID,
            'shift_name' => 'manual',
            'opened_at' => now()->subHour(),
            'opening_float' => 42.50,
            'status' => 'open',
        ]);

        $response = $this->controller()->login($this->loginRequest([
            'login_code' => '1234',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame($shift->id, $payload['active_shift']['id']);
        $this->assertSame('continue_existing_shift', $payload['shift_action_required']);
        $this->assertSame(1, DB::table('pos_user_shift_sessions')->count());
        $this->assertSame(
            42.50,
            (float) DB::table('pos_user_shift_sessions')->where('id', $shift->id)->value('opening_float')
        );
    }

    public function test_code_login_does_not_resume_an_open_shift_from_another_till(): void
    {
        $otherTillShift = PosUserShiftSession::query()->create([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::OTHER_TILL_ID,
            'shift_configuration_id' => self::SHIFT_CONFIG_ID,
            'pos_user_id' => self::USER_ID,
            'shift_name' => 'manual',
            'opened_at' => now()->subHour(),
            'opening_float' => 99.00,
            'status' => 'open',
        ]);

        $response = $this->controller()->login($this->loginRequest([
            'login_code' => '1234',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertNotSame($otherTillShift->id, $payload['active_shift']['id']);
        $this->assertSame(self::TILL_ID, $payload['active_shift']['till_id']);
        $this->assertSame(0.0, (float) $payload['active_shift']['opening_float']);
        $this->assertSame(2, DB::table('pos_user_shift_sessions')->count());
        $this->assertSame(
            99.00,
            (float) DB::table('pos_user_shift_sessions')
                ->where('id', $otherTillShift->id)
                ->value('opening_float')
        );
    }

    public function test_password_login_still_requires_and_preserves_an_explicit_opening_float(): void
    {
        $rejected = $this->controller()->login($this->loginRequest([
            'username' => 'cashier',
            'password' => 'password-secret',
        ]));

        $this->assertSame(422, $rejected->getStatusCode());
        $rejectedPayload = $rejected->getData(true);
        $this->assertSame('Opening float is required to open this shift', $rejectedPayload['message']);
        $this->assertSame('OPENING_FLOAT_REQUIRED', $rejectedPayload['code']);
        $this->assertSame('manual', $rejectedPayload['shift']);
        $this->assertSame(0, DB::table('pos_user_shift_sessions')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $accepted = $this->controller()->login($this->loginRequest([
            'username' => 'cashier',
            'password' => 'password-secret',
            'opening_float' => 37.25,
        ]));

        $this->assertSame(200, $accepted->getStatusCode());
        $this->assertSame(37.25, (float) $accepted->getData(true)['active_shift']['opening_float']);
        $this->assertSame(37.25, (float) DB::table('pos_user_shift_sessions')->value('opening_float'));
    }

    public function test_code_lookup_remains_scoped_to_the_resolved_company(): void
    {
        $response = $this->controller()->login($this->loginRequest([
            'login_code' => '9876',
        ]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid login credentials', $response->getData(true)['message']);
        $this->assertSame(0, DB::table('pos_user_shift_sessions')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_alphanumeric_code_can_be_created_and_used_when_effective_policy_allows_it(): void
    {
        config()->set(
            'authentication.security.policy_defaults.credential.login_code.numeric_only',
            false
        );
        $code = 'A1b2';
        $index = new LoginCodeBlindIndex([
            'test-v1' => 'test-login-code-lookup-key-material',
        ], 'test-v1');
        DB::table('pos_users')->where('id', self::USER_ID)->update([
            // POS-user creation writes both the slow verifier and this
            // company-scoped lookup digest from the same canonical code.
            'login_code' => Hash::make($code),
            'login_code_lookup' => $index->digestForCompany(self::COMPANY_ID, $code),
            'login_code_lookup_key_id' => 'test-v1',
        ]);

        $response = $this->controller()->login($this->loginRequest([
            'login_code' => $code,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('login_code', $response->getData(true)['authentication_method']);
    }

    private function loginRequest(array $credentials): Request
    {
        return Request::create('/api/pos-auth/login', 'POST', [
            ...$credentials,
            'branch_id' => self::BRANCH_ID,
            'device_name' => 'Android FOH test terminal',
            'workspace' => 'front-office',
        ]);
    }

    private function controller(
        bool $allowContinueUnclosedShift = true,
        bool $companySuperAdmin = false
    ): PosUserController {
        $license = $this->createMock(LicenseService::class);

        $resolver = $this->createMock(ShiftResolverService::class);
        $resolver->method('getConfig')->willReturn(
            ShiftConfiguration::query()->findOrFail(self::SHIFT_CONFIG_ID)
        );
        $resolver->method('detect')->willReturn('manual');

        $policy = $this->createMock(ShiftPolicyService::class);
        $policy->method('get')->willReturn([
            'allow_continue_unclosed_shift' => $allowContinueUnclosedShift,
        ]);

        $licenseAccess = $this->createMock(LicenseAccessService::class);
        $licenseAccess->method('enforce')->willReturn([
            'valid' => true,
            'code' => 'LICENSE_VALID',
        ]);

        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('isSuperAdmin')->willReturn($companySuperAdmin);
        $superAdmins->method('isPlatformAdministrator')->willReturn(false);
        $superAdmins->method('tokenAbilitiesForWorkspace')->willReturn(['workspace:front-office']);
        $superAdmins->method('hasPlatformSessionAccess')->willReturn(false);

        $settings = $this->createMock(CompanySettingService::class);
        $settings->method('get')->willReturn('standard');
        app()->instance(CompanySettingService::class, $settings);

        return new PosUserController(
            $license,
            $resolver,
            $policy,
            $licenseAccess,
            $superAdmins,
        );
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code')->nullable();
            $table->string('company_name');
            $table->string('status')->default('active');
            $table->string('license_support_phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
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
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('login_code')->nullable();
            $table->char('login_code_lookup', 64)->nullable();
            $table->string('login_code_lookup_key_id', 32)->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->timestamp('credentials_changed_at')->nullable();
            $table->string('user_type')->default('cashier');
            $table->boolean('can_login_pos')->default(true);
            $table->boolean('can_access_front_office')->default(false);
            $table->boolean('can_access_back_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
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
        Schema::create('shiftconfigurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->boolean('enable_shifts')->default(true);
            $table->string('shift_mode')->default('manual');
            $table->boolean('require_opening_float')->default(true);
            $table->boolean('allow_unscheduled_shifts')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('pos_user_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('pos_user_id');
            $table->uuid('shift_configuration_id');
            $table->string('shift_name');
            $table->timestamps();
        });
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('pos_user_id');
            $table->uuid('shift_configuration_id')->nullable();
            $table->string('shift_name');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 15, 2)->nullable();
            $table->decimal('closing_cash', 15, 2)->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedLoginContext(): void
    {
        $now = now();

        DB::table('companies')->insert([
            [
                'id' => self::COMPANY_ID,
                'company_code' => 'TENANT-A',
                'company_name' => 'Tenant A',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_COMPANY_ID,
                'company_code' => 'TENANT-B',
                'company_name' => 'Tenant B',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('company_branches')->insert([
            'id' => self::BRANCH_ID,
            'company_id' => self::COMPANY_ID,
            'branch_name' => 'Main Branch',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'company_id' => self::COMPANY_ID,
            'name' => 'Cashier',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shiftconfigurations')->insert([
            'id' => self::SHIFT_CONFIG_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'enable_shifts' => true,
            'shift_mode' => 'manual',
            'require_opening_float' => true,
            'allow_unscheduled_shifts' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pos_users')->insert([
            [
                'id' => self::USER_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'role_id' => self::ROLE_ID,
                'name' => 'Tenant Cashier',
                'username' => 'cashier',
                'password' => Hash::make('password-secret'),
                'login_code' => Hash::make('1234'),
                'user_type' => 'cashier',
                'can_login_pos' => true,
                'can_access_front_office' => true,
                'can_access_back_office' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => self::OTHER_USER_ID,
                'company_id' => self::OTHER_COMPANY_ID,
                'branch_id' => null,
                'role_id' => null,
                'name' => 'Foreign Cashier',
                'username' => 'foreign-cashier',
                'password' => Hash::make('foreign-password'),
                'login_code' => Hash::make('9876'),
                'user_type' => 'cashier',
                'can_login_pos' => true,
                'can_access_front_office' => true,
                'can_access_back_office' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('user_branches')->insert([
            'id' => '10000000-0000-7000-8000-000000000007',
            'company_id' => self::COMPANY_ID,
            'pos_user_id' => self::USER_ID,
            'branch_id' => self::BRANCH_ID,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pos_user_shift_assignments')->insert([
            'id' => '10000000-0000-7000-8000-000000000008',
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'pos_user_id' => self::USER_ID,
            'shift_configuration_id' => self::SHIFT_CONFIG_ID,
            'shift_name' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
