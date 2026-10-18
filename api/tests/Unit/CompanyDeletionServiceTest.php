<?php

namespace Tests\Unit;

use App\Services\Auth\SuperAdminService;
use App\Services\Company\CompanyDeletionService;
use App\Services\License\LicenseAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Company;
use Tests\TestCase;

class CompanyDeletionServiceTest extends TestCase
{
    private const TABLES = ['companies', 'company_branches', 'pos_users', 'products', 'licenses', 'auth_sessions', 'sync_nodes', 'audit_trails'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for company deletion tests.');
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('companies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_name');
            $table->string('company_code')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('username');
            $table->string('login_code')->nullable();
            $table->string('login_code_lookup')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->nullable();
            $table->string('pos_user_id');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_nodes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_trails', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('event');
        });

        foreach (['company-a', 'company-b'] as $company) {
            DB::table('companies')->insert(['id' => $company, 'company_name' => $company]);
            DB::table('company_branches')->insert(['id' => "{$company}-branch", 'company_id' => $company, 'branch_name' => 'Main']);
            DB::table('pos_users')->insert(['id' => "{$company}-cashier", 'company_id' => $company, 'username' => 'cashier', 'login_code' => 'hash', 'login_code_lookup' => "{$company}-digest"]);
            DB::table('products')->insert(['id' => "{$company}-product", 'company_id' => $company, 'name' => 'Burger']);
            DB::table('licenses')->insert(['id' => "{$company}-license", 'company_id' => $company, 'status' => 'active']);
            DB::table('auth_sessions')->insert(['id' => "{$company}-session", 'company_id' => $company, 'pos_user_id' => "{$company}-cashier"]);
            DB::table('sync_nodes')->insert(['id' => "{$company}-node", 'company_id' => $company, 'status' => 'active']);
            DB::table('audit_trails')->insert(['id' => "{$company}-audit", 'company_id' => $company, 'event' => 'sale']);
        }
        DB::table('pos_users')->insert(['id' => 'platform-admin', 'company_id' => 'company-a', 'username' => 'owner']);
        DB::table('auth_sessions')->insert(['id' => 'admin-session', 'company_id' => 'company-a', 'pos_user_id' => 'platform-admin']);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_deleting_a_company_deletes_and_revokes_everything_under_it_only(): void
    {
        $this->service()->delete(Company::query()->findOrFail('company-a'));

        $this->assertNotNull(DB::table('companies')->where('id', 'company-a')->value('deleted_at'));
        foreach (['company_branches' => 'company-a-branch', 'pos_users' => 'company-a-cashier', 'products' => 'company-a-product'] as $table => $id) {
            $this->assertNotNull(DB::table($table)->where('id', $id)->value('deleted_at'), "{$table} row was not deleted");
        }
        $this->assertNull(DB::table('pos_users')->where('id', 'company-a-cashier')->value('login_code_lookup'));
        $this->assertSame('revoked', DB::table('licenses')->where('id', 'company-a-license')->value('status'));
        $this->assertNotNull(DB::table('auth_sessions')->where('id', 'company-a-session')->value('revoked_at'));
        $this->assertSame('revoked', DB::table('sync_nodes')->where('id', 'company-a-node')->value('status'));
        $this->assertSame(1, DB::table('audit_trails')->where('company_id', 'company-a')->count());

        // The platform administrator keeps their account and session.
        $this->assertNull(DB::table('pos_users')->where('id', 'platform-admin')->value('deleted_at'));
        $this->assertNull(DB::table('auth_sessions')->where('id', 'admin-session')->value('revoked_at'));

        // Another company is untouched.
        $this->assertNull(DB::table('companies')->where('id', 'company-b')->value('deleted_at'));
        $this->assertNull(DB::table('products')->where('id', 'company-b-product')->value('deleted_at'));
        $this->assertSame('company-b-digest', DB::table('pos_users')->where('id', 'company-b-cashier')->value('login_code_lookup'));
        $this->assertSame('active', DB::table('licenses')->where('id', 'company-b-license')->value('status'));
        $this->assertSame('active', DB::table('sync_nodes')->where('id', 'company-b-node')->value('status'));
    }

    private function service(): CompanyDeletionService
    {
        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('platformAdministratorIds')->willReturn(['platform-admin']);
        $licenseAccess = $this->createMock(LicenseAccessService::class);
        $licenseAccess->method('revokeNonPlatformAdministratorTokens')->willReturn(0);

        return new CompanyDeletionService($superAdmins, $licenseAccess);
    }
}
