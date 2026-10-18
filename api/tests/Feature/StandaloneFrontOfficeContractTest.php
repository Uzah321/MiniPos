<?php

namespace Tests\Feature;

use App\Services\License\LicenseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Http\Controllers\TillController;
use Modules\GeneralSettings\Models\PosTill;
use Tests\TestCase;

class StandaloneFrontOfficeContractTest extends TestCase
{
    private const COMPANY_ID = '10000000-0000-0000-0000-000000000001';

    private const BRANCH_ID = '10000000-0000-0000-0000-000000000002';

    private const TILL_ID = '10000000-0000-0000-0000-000000000003';

    private const ROLE_ID = '10000000-0000-0000-0000-000000000004';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('salexa_sync.deployment_mode', 'standalone');
        $this->dropTables();
        $this->createTables();
        $this->seedLocalInstallation();
    }

    protected function tearDown(): void
    {
        auth()->forgetGuards();
        app()->forgetInstance('company');
        $this->dropTables();

        parent::tearDown();
    }

    public function test_status_exposes_local_readiness_without_leaking_tenant_identity_before_till_authorization(): void
    {
        $this->getJson('/api/pos/sync/status')
            ->assertOk()
            ->assertJsonPath('data.deployment_mode', 'standalone')
            ->assertJsonPath('data.local_only', true)
            ->assertJsonPath('data.paired', true)
            ->assertJsonPath('data.pairing_required', false)
            ->assertJsonPath('data.configuration_pending', false)
            ->assertJsonPath('data.till_setup_required', true)
            ->assertJsonPath('data.sell_ready', false)
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.till_id');

        $rawCredential = 'standalone-browser-secret';
        PosTill::query()->findOrFail(self::TILL_ID)->forceFill([
            'registration_token' => hash('sha256', $rawCredential),
            'is_registered' => true,
            'registered_at' => now(),
        ])->save();

        $this->withHeader('X-Till-Token', $rawCredential)
            ->getJson('/api/pos/sync/status')
            ->assertOk()
            ->assertJsonPath('data.deployment_mode', 'standalone')
            ->assertJsonPath('data.till_setup_required', false)
            ->assertJsonPath('data.sell_ready', true)
            ->assertJsonPath('data.company_id', self::COMPANY_ID)
            ->assertJsonPath('data.branch_id', self::BRANCH_ID)
            ->assertJsonPath('data.till_id', self::TILL_ID)
            ->assertJsonMissingPath('data.registration_token');
    }

    public function test_local_one_time_code_redeems_without_sync_state_or_cloud_transport(): void
    {
        $activationCode = 'TERM-01234567-89ABCDEF-01234567-89ABCDEF';
        PosTill::query()->findOrFail(self::TILL_ID)->forceFill([
            'activation_code' => $this->activationDigest($activationCode),
            'activation_code_expires_at' => now()->addMinutes(10),
        ])->save();

        $response = $this->postJson('/api/pos/sync/terminal-activations/redeem', [
            'terminal_activation_code' => $activationCode,
        ])->assertOk()
            ->assertJsonPath('data.terminal.id', self::TILL_ID)
            ->assertJsonPath('data.terminal.company_id', self::COMPANY_ID)
            ->assertJsonPath('data.terminal.branch_id', self::BRANCH_ID);
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );

        $credential = (string) $response->json('data.terminal_credential');
        $this->assertNotSame('', $credential);
        $stored = PosTill::query()->findOrFail(self::TILL_ID);
        $this->assertSame(hash('sha256', $credential), $stored->getRawOriginal('registration_token'));
        $this->assertNull($stored->getRawOriginal('activation_code'));
        $this->assertNull($stored->activation_code_expires_at);
        $this->assertTrue((bool) $stored->is_registered);
        $this->assertFalse(Schema::hasTable('sync_local_states'));

        $this->postJson('/api/pos/sync/terminal-activations/redeem', [
            'terminal_activation_code' => $activationCode,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'TERMINAL_ACTIVATION_CODE_INVALID');
    }

    public function test_back_office_can_issue_only_a_digest_for_a_standalone_browser(): void
    {
        $licenseService = $this->mock(LicenseService::class);
        $licenseService->shouldReceive('isValid')->once()->andReturnTrue();

        $user = (new PosUser)->forceFill([
            'id' => '10000000-0000-0000-0000-000000000005',
            'company_id' => self::COMPANY_ID,
            'role_id' => self::ROLE_ID,
            'user_type' => 'super_admin',
            'is_active' => true,
        ]);
        $this->be($user);
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));

        $response = app(TillController::class)->issueStandaloneActivationCode(
            Request::create('/api/pos/tills/'.self::TILL_ID.'/activation-code', 'POST', [
                'device_label' => 'Reception browser',
            ]),
            self::TILL_ID
        );

        $this->assertSame(201, $response->getStatusCode());
        $payload = $response->getData(true);
        $rawCode = (string) data_get($payload, 'data.terminal_activation_code');
        $this->assertMatchesRegularExpression(
            '/^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/',
            $rawCode
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        $this->assertArrayNotHasKey('activation_code', (array) data_get($payload, 'data.terminal'));

        $stored = PosTill::query()->findOrFail(self::TILL_ID);
        $this->assertSame($this->activationDigest($rawCode), $stored->getRawOriginal('activation_code'));
        $this->assertNotSame($rawCode, $stored->getRawOriginal('activation_code'));
        $this->assertTrue($stored->activation_code_expires_at->isFuture());
        $this->assertSame('Reception browser', $stored->device_label);
    }

    public function test_local_activation_route_keeps_back_office_and_license_boundaries(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(
            fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/pos/tills/{id}/activation-code'
        );

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('workspace:back-office', $middleware);
        $this->assertContains('App\\Http\\Middleware\\CheckCompanyLicense', $middleware);
    }

    private function activationDigest(string $code): string
    {
        return substr(hash('sha256', strtoupper(trim($code))), 0, 30);
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code');
            $table->string('company_name');
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_code');
            $table->string('branch_name');
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();
            $table->string('registration_token', 128)->nullable()->unique();
            $table->string('activation_code', 30)->nullable();
            $table->timestamp('activation_code_expires_at')->nullable();
            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('status');
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
    }

    private function seedLocalInstallation(): void
    {
        $now = now();
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'company_code' => 'CMP-LOCAL',
            'company_name' => 'Standalone Company',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('company_branches')->insert([
            'id' => self::BRANCH_ID,
            'company_id' => self::COMPANY_ID,
            'branch_code' => 'MAIN',
            'branch_name' => 'Main Branch',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pos_tills')->insert([
            'id' => self::TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_code' => 'TILL-001',
            'till_name' => 'Reception',
            'is_registered' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('licenses')->insert([
            'id' => '10000000-0000-0000-0000-000000000006',
            'company_id' => self::COMPANY_ID,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'company_id' => self::COMPANY_ID,
            'name' => 'super_admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function dropTables(): void
    {
        foreach ([
            'role_permissions',
            'permissions',
            'roles',
            'licenses',
            'pos_tills',
            'company_branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
