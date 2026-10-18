<?php

namespace Tests\Feature;

use App\Services\CompanySettingService;
use App\Services\License\LicenseAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Authentication\Http\Controllers\PosUserController;
use Tests\TestCase;

class BackOfficeImplicitCompanyLoginTest extends TestCase
{
    private const OWNER_ID = '0199c7ee-0000-7000-8000-000000000005';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', false);
        config()->set('salexa_sync.allow_legacy_domain_resolution', false);
        config()->set('license.platform_admin_pos_user_ids', [self::OWNER_ID]);
        config()->set('authentication.security.audit_identifier_key', 'test-audit-key-that-is-at-least-32-bytes');
        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        app()->forgetInstance('till');

        $this->dropTables();
        $this->createTables();

        $licenseStatus = [
            'valid' => true,
            'bypass' => false,
            'code' => 'LICENSE_VALID',
            'message' => 'Company license is valid.',
        ];
        $licenseAccess = $this->createMock(LicenseAccessService::class);
        $licenseAccess->method('enforce')->willReturn($licenseStatus);
        $licenseAccess->method('status')->willReturn($licenseStatus);
        app()->instance(LicenseAccessService::class, $licenseAccess);

        $settings = $this->createMock(CompanySettingService::class);
        $settings->method('get')->willReturn('standard');
        $settings->method('businessMode')->willReturn('hospitality');
        $settings->method('branchIdsForMode')->willReturnCallback(
            static fn (string $companyId, string $mode, ?array $branchIds = null): array => $mode === 'hospitality' ? array_values($branchIds ?? []) : []
        );
        $settings->method('isBohEnabled')->willReturn(true);
        $settings->method('isFastFoodMode')->willReturn(true);
        $settings->method('enabled')->willReturn(true);
        app()->instance(CompanySettingService::class, $settings);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        app()->forgetInstance('till');
        $this->dropTables();

        parent::tearDown();
    }

    public function test_normal_back_office_identity_resolves_its_persisted_company_and_ambiguity_fails_closed(): void
    {
        $this->insertCompany('company-a', 'PRIVATE-A', 'Company A');
        $this->insertCompany('company-b', 'PRIVATE-B', 'Company B');
        $this->insertRole('role-a', 'company-a', 'Administrator');
        $this->insertRole('role-b', 'company-b', 'Administrator');
        $this->insertUser('user-a', 'company-a', 'role-a', 'shared.user', 'correct-password');

        $request = Request::create('/api/pos-auth/back-office/login', 'POST', [
            'workspace' => 'back-office',
            'username' => 'SHARED.USER',
            'password' => 'correct-password',
        ]);
        $method = new \ReflectionMethod(PosUserController::class, 'resolveBackOfficeLoginCompany');
        $resolved = $method->invoke(app(PosUserController::class), $request);

        $this->assertSame('company-a', $resolved->id);
        $this->assertSame('user-a', $request->attributes->get('resolved_back_office_user_id'));
        $this->assertSame('shared.user', $request->username);

        $this->insertUser('user-b', 'company-b', 'role-b', 'Shared.User', 'correct-password');
        $ambiguousRequest = Request::create('/api/pos-auth/back-office/login', 'POST', [
            'workspace' => 'back-office',
            'username' => 'shared.user',
            'password' => 'correct-password',
        ]);
        $ambiguous = $method->invoke(app(PosUserController::class), $ambiguousRequest);

        $this->assertSame(409, $ambiguous->getStatusCode());
        $this->assertSame('AMBIGUOUS_LOGIN_IDENTITY', $ambiguous->getData(true)['code']);
        $this->assertArrayNotHasKey('companies', $ambiguous->getData(true));
    }

    public function test_back_office_login_rejects_company_code_and_untrusted_company_id(): void
    {
        $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'nobody',
            'password' => 'incorrect',
        ], ['X-Company-Code' => 'PRIVATE-A'])
            ->assertStatus(422)
            ->assertJson(['code' => 'COMPANY_CODE_NOT_ACCEPTED']);

        $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'nobody',
            'password' => 'incorrect',
            'company_id' => 'company-a',
        ])->assertStatus(422)
            ->assertJson(['code' => 'COMPANY_SELECTION_TOKEN_REQUIRED']);
    }

    public function test_platform_selection_token_is_short_lived_and_exposes_no_company_code(): void
    {
        $this->seedPlatformOwner();
        $this->insertCompany('company-target', 'NEVER-EXPOSE', 'Target Company');

        $response = $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'Cashier1',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('company_selection_required', true)
            ->assertJsonPath('companies.0.id', 'company-home')
            ->assertJsonMissingPath('companies.0.company_code')
            ->assertJsonMissingPath('token');

        $plainTextToken = (string) $response->json('selection_token');
        $tokenId = (int) Str::before($plainTextToken, '|');
        $token = DB::table('personal_access_tokens')->where('id', $tokenId)->first();

        $this->assertNotNull($token);
        $this->assertLessThanOrEqual(301, now()->diffInSeconds($token->expires_at, false));
        $this->assertGreaterThan(0, now()->diffInSeconds($token->expires_at, false));
        $this->assertStringContainsString('platform:select-company', (string) $token->abilities);
    }

    public function test_platform_company_selection_rotates_token_and_me_uses_auth_session_scope(): void
    {
        $this->seedPlatformOwner();
        $this->insertCompany('company-target', 'NEVER-EXPOSE', 'Target Company');
        $this->insertBranch('branch-target', 'company-target', 'Target Branch');

        $login = $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'Cashier1',
            'password' => 'password123',
        ])->assertOk();
        $selectionToken = (string) $login->json('selection_token');

        $selected = $this->withToken($selectionToken)
            ->postJson('/api/pos-auth/back-office/select-company', [
                'company_id' => 'company-target',
            ])
            ->assertOk()
            ->assertJsonPath('company_id', 'company-target')
            ->assertJsonPath('branch_id', 'branch-target')
            ->assertJsonPath('company.company_name', 'Target Company')
            ->assertJsonMissingPath('company.company_code');

        $tenantToken = (string) $selected->json('token');
        $tenantTokenId = (int) Str::before($tenantToken, '|');
        $this->assertSame(
            'company-target',
            DB::table('auth_sessions')
                ->where('personal_access_token_id', $tenantTokenId)
                ->value('company_id')
        );
        $this->assertSame(
            'branch-target',
            DB::table('auth_sessions')
                ->where('personal_access_token_id', $tenantTokenId)
                ->value('branch_id')
        );

        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        $this->app['auth']->forgetGuards();
        $this->withToken($tenantToken)
            ->getJson('/api/pos-auth/me')
            ->assertOk()
            ->assertJsonPath('company_id', 'company-target')
            ->assertJsonPath('branch_id', 'branch-target')
            ->assertJsonPath('user.company_id', 'company-target')
            ->assertJsonPath('user.branch_id', 'branch-target')
            ->assertJsonMissingPath('company.company_code')
            ->assertJsonMissingPath('user.company.company_code');

        $this->app['auth']->forgetGuards();
        $this->withToken($selectionToken)
            ->postJson('/api/pos-auth/back-office/select-company', [
                'company_id' => 'company-home',
            ])
            ->assertUnauthorized();

        $this->assertDatabaseHas('authentication_events', [
            'event_type' => 'platform_company_selected',
            'outcome' => 'success',
            'company_id' => 'company-target',
            'branch_id' => 'branch-target',
        ]);
    }

    public function test_tenant_scoped_back_office_session_can_load_workspace_bootstrap(): void
    {
        $this->seedPlatformOwner();
        $this->insertCompany('company-target', 'NEVER-EXPOSE', 'Target Company');
        $this->insertBranch('branch-target', 'company-target', 'Target Branch');

        $login = $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'Cashier1',
            'password' => 'password123',
        ])->assertOk();

        $selected = $this->withToken((string) $login->json('selection_token'))
            ->postJson('/api/pos-auth/back-office/select-company', [
                'company_id' => 'company-target',
            ])
            ->assertOk();

        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        $this->app['auth']->forgetGuards();

        $this->withToken((string) $selected->json('token'))
            ->getJson('/api/pos/workspace/bootstrap')
            ->assertOk()
            ->assertJsonPath('message', 'Workspace configuration loaded successfully.')
            ->assertJsonPath('data.company.id', 'company-target')
            ->assertJsonPath('data.branch.id', 'branch-target')
            ->assertJsonPath('data.available_branches.0.id', 'branch-target')
            ->assertJsonPath('data.available_branches.0.business_mode', 'hospitality')
            ->assertJsonPath('data.user.username', 'Cashier1')
            ->assertJsonPath('data.user.workspace_access.back_office', true)
            ->assertJsonMissingPath('data.company.company_code');
    }

    public function test_tenant_scoped_back_office_session_can_load_current_company_without_a_company_code(): void
    {
        $this->seedPlatformOwner();
        $this->insertCompany('company-target', 'NEVER-EXPOSE', 'Target Company');
        $this->insertBranch('branch-target', 'company-target', 'Target Branch');

        $login = $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'Cashier1',
            'password' => 'password123',
        ])->assertOk();

        $selected = $this->withToken((string) $login->json('selection_token'))
            ->postJson('/api/pos-auth/back-office/select-company', [
                'company_id' => 'company-target',
            ])
            ->assertOk();

        app()->forgetInstance('company');
        app()->forgetInstance('license');
        app()->forgetInstance('license.status');
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $this->withToken((string) $selected->json('token'))
            ->getJson('/api/pos/company')
            ->assertOk()
            ->assertJsonPath('company.id', 'company-target')
            ->assertJsonPath('company.company_name', 'Target Company');
    }

    public function test_unauthenticated_front_office_company_discovery_still_uses_the_company_code(): void
    {
        $this->insertCompany('company-a', 'PRIVATE-A', 'Company A');
        $this->insertBranch('branch-a', 'company-a', 'Branch A');

        $this->getJson('/api/pos/company', [
            'X-Company-Code' => 'PRIVATE-A',
        ])->assertOk()
            ->assertJsonPath('company.id', 'company-a')
            ->assertJsonPath('company.company_name', 'Company A');

        app()->forgetInstance('company');

        $this->getJson('/api/pos/company')
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_CODE_REQUIRED');
    }

    public function test_expired_platform_selection_token_cannot_select_a_company(): void
    {
        $this->seedPlatformOwner();
        $login = $this->postJson('/api/pos-auth/back-office/login', [
            'username' => 'Cashier1',
            'password' => 'password123',
        ])->assertOk();
        $selectionToken = (string) $login->json('selection_token');
        $tokenId = (int) Str::before($selectionToken, '|');
        DB::table('personal_access_tokens')->where('id', $tokenId)->update([
            'expires_at' => now()->subSecond(),
        ]);

        $this->withToken($selectionToken)
            ->postJson('/api/pos-auth/back-office/select-company', [
                'company_id' => 'company-home',
            ])
            ->assertUnauthorized();
    }

    private function seedPlatformOwner(): void
    {
        $this->insertCompany('company-home', 'HOME-PRIVATE', 'Home Company');
        $this->insertRole('role-owner', 'company-home', 'Super Admin');
        $this->insertUser(
            self::OWNER_ID,
            'company-home',
            'role-owner',
            'Cashier1',
            'password123',
            'super_admin'
        );
    }

    private function insertCompany(string $id, string $code, string $name): void
    {
        DB::table('companies')->insert([
            'id' => $id,
            'company_code' => $code,
            'company_name' => $name,
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBranch(string $id, string $companyId, string $name): void
    {
        DB::table('company_branches')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertRole(string $id, string $companyId, string $name): void
    {
        DB::table('roles')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(
        string $id,
        string $companyId,
        string $roleId,
        string $username,
        string $password,
        string $userType = 'admin'
    ): void {
        DB::table('pos_users')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'role_id' => $roleId,
            'name' => $username,
            'username' => $username,
            'password' => Hash::make($password),
            'user_type' => $userType,
            'can_login_pos' => true,
            'can_access_front_office' => false,
            'can_access_back_office' => true,
            'is_active' => true,
            'credential_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code')->nullable();
            $table->string('company_name');
            $table->string('logo')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('status')->default('active');
            $table->string('domain')->nullable();
            $table->string('host_ip')->nullable();
            $table->string('license_support_phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branch_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('status')->default('active');
            $table->timestamps();
        });
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
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->uuid('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->string('name')->nullable();
            $table->string('username');
            $table->string('password');
            $table->string('user_type')->nullable();
            $table->boolean('can_login_pos')->default(true);
            $table->boolean('can_access_front_office')->default(false);
            $table->boolean('can_access_back_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->timestamp('credentials_changed_at')->nullable();
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
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('channel');
            $table->string('workspace')->nullable();
            $table->string('authentication_method');
            $table->uuid('country_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->char('policy_fingerprint', 64)->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->char('device_identifier_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('authentication_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->string('outcome');
            $table->uuid('pos_user_id')->nullable();
            $table->uuid('actor_pos_user_id')->nullable();
            $table->uuid('auth_session_id')->nullable();
            $table->string('channel')->nullable();
            $table->string('workspace')->nullable();
            $table->string('authentication_method')->nullable();
            $table->char('identifier_hash', 64)->nullable();
            $table->uuid('country_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('reason_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
        });
        Schema::create('pos_user_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('pos_user_id');
            $table->uuid('shift_configuration_id')->nullable();
            $table->string('shift_name')->nullable();
            $table->timestamps();
        });
        Schema::create('shiftconfigurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_configuration_id')->nullable();
            $table->uuid('pos_user_id');
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('target_printing')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('qz_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'qz_certificates',
            'printers',
            'pos_tills',
            'authentication_events',
            'auth_sessions',
            'personal_access_tokens',
            'pos_user_shift_sessions',
            'pos_user_shift_assignments',
            'shiftconfigurations',
            'user_branches',
            'role_permissions',
            'permissions',
            'pos_users',
            'roles',
            'licenses',
            'company_branch_contacts',
            'company_branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
