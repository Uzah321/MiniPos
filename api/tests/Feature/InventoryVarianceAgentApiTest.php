<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Tests\TestCase;

class InventoryVarianceAgentApiTest extends TestCase
{
    private ?object $varianceMigration = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for the variance API isolation test.');
        }

        // These two deployment gates have their own tests. Keep authentication,
        // workspace ability, company permission, and real branch scoping active.
        $this->withoutMiddleware([ResolveCompanyFromDomain::class, CheckCompanyLicense::class]);

        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
        });
        Schema::create('pos_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id');
            $table->string('name');
            $table->string('user_type')->default('staff');
            $table->softDeletes();
        });
        Schema::create('user_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('pos_user_id');
            $table->uuid('branch_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
        });
        Schema::create('general_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('setting_key');
            $table->longText('setting_value')->nullable();
            $table->string('setting_type');
            $table->string('module')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->string('stock_area')->nullable();
        });

        $this->varianceMigration = require base_path('Modules/Product/database/migrations/2026_10_10_150000_create_inventory_variance_agent_tables.php');
        $this->varianceMigration->up();
    }

    protected function tearDown(): void
    {
        if ($this->varianceMigration) {
            $this->varianceMigration->down();
            foreach (['stock_movements', 'general_settings', 'role_permissions', 'permissions',
                'roles', 'user_branches', 'pos_users', 'products', 'company_branches', 'companies'] as $table) {
                Schema::dropIfExists($table);
            }
        }
        parent::tearDown();
    }

    public function test_viewer_sees_only_own_company_branch_cases_and_cannot_manage_agent(): void
    {
        [$companyA, $companyB, $branchA, $branchA2, $branchB] = $this->fixtureCompaniesAndBranches();
        $viewer = $this->user($companyA, $branchA, 'view_inventory_variance_agent');
        $ownCase = $this->variance($companyA, $branchA, 'Own stock');
        $otherBranchCase = $this->variance($companyA, $branchA2, 'Other branch stock');
        $otherCompanyCase = $this->variance($companyB, $branchB, 'Other company stock');

        app()->instance('company', Company::query()->findOrFail($companyA));
        Sanctum::actingAs($viewer, ['workspace:back-office']);
        $url = '/api/pos/management/inventory/variance-agent';

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.summary.open', 1)
            ->assertJsonPath('data.variances.0.id', $ownCase)
            ->assertJsonCount(1, 'data.branch_ids');
        $this->getJson($url.'/variances')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $ownCase);
        $this->getJson($url.'/variances/'.$ownCase)->assertOk()
            ->assertJsonPath('data.variance.id', $ownCase);
        $this->getJson($url.'/variances/'.$otherBranchCase)->assertNotFound();
        $this->getJson($url.'/variances/'.$otherCompanyCase)->assertNotFound();
        $this->getJson($url.'?branch_id='.$branchA2)
            ->assertForbidden()->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
        $this->getJson($url.'/variances?branch_id='.$branchB)
            ->assertForbidden()->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
        $this->patchJson($url.'/settings', ['scan_interval_minutes' => 11])
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->postJson($url.'/scan')
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_manager_can_change_only_own_company_settings_and_wrong_company_user_is_rejected(): void
    {
        [$companyA, $companyB, $branchA, , $branchB] = $this->fixtureCompaniesAndBranches();
        $manager = $this->user($companyA, $branchA, 'manage_inventory_variance_agent');
        $foreignViewer = $this->user($companyB, $branchB, 'view_inventory_variance_agent');
        app(InventoryVarianceAgentService::class)->updateSettings($companyB, ['scan_interval_minutes' => 9]);
        app()->instance('company', Company::query()->findOrFail($companyA));
        $url = '/api/pos/management/inventory/variance-agent';

        Sanctum::actingAs($manager, ['workspace:back-office']);
        $this->patchJson($url.'/settings', ['scan_interval_minutes' => 11])
            ->assertOk()->assertJsonPath('data.scan_interval_minutes', 11);
        $this->assertSame('11', DB::table('general_settings')
            ->where('company_id', $companyA)
            ->where('setting_key', 'inventory_variance_scan_interval_minutes')
            ->value('setting_value'));
        $this->assertSame('9', DB::table('general_settings')
            ->where('company_id', $companyB)
            ->where('setting_key', 'inventory_variance_scan_interval_minutes')
            ->value('setting_value'));

        Sanctum::actingAs($foreignViewer, ['workspace:back-office']);
        $this->getJson($url)
            ->assertForbidden()->assertJsonPath('code', 'COMPANY_ACCESS_DENIED');
    }

    private function fixtureCompaniesAndBranches(): array
    {
        $companies = [(string) Str::uuid(), (string) Str::uuid()];
        $branches = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        DB::table('companies')->insert([
            ['id' => $companies[0], 'company_name' => 'Company A'],
            ['id' => $companies[1], 'company_name' => 'Company B'],
        ]);
        DB::table('company_branches')->insert([
            ['id' => $branches[0], 'company_id' => $companies[0], 'branch_name' => 'A1', 'branch_code' => 'A1'],
            ['id' => $branches[1], 'company_id' => $companies[0], 'branch_name' => 'A2', 'branch_code' => 'A2'],
            ['id' => $branches[2], 'company_id' => $companies[1], 'branch_name' => 'B1', 'branch_code' => 'B1'],
        ]);

        return [$companies[0], $companies[1], ...$branches];
    }

    private function user(string $companyId, string $branchId, string $permissionName): PosUser
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $permissionId = (string) DB::table('permissions')->where('name', $permissionName)->value('id');
        DB::table('roles')->insert(['id' => $roleId, 'company_id' => $companyId, 'name' => 'Stock staff']);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'role_id' => $roleId, 'permission_id' => $permissionId,
        ]);
        DB::table('pos_users')->insert([
            'id' => $userId, 'company_id' => $companyId, 'branch_id' => $branchId,
            'role_id' => $roleId, 'name' => 'Stock staff',
        ]);

        return PosUser::query()->findOrFail($userId);
    }

    private function variance(string $companyId, string $branchId, string $productName): string
    {
        $productId = (string) Str::uuid();
        $caseId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $productId, 'company_id' => $companyId,
            'name' => $productName, 'code' => strtoupper(substr($productName, 0, 4)),
        ]);
        DB::table('inventory_variances')->insert([
            'id' => $caseId,
            'cycle_id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'stock_area' => 'front',
            'status' => 'open',
            'direction' => 'shortage',
            'severity' => 'medium',
            'expected_quantity' => 10,
            'actual_quantity' => 9,
            'variance_quantity' => -1,
            'absolute_variance_quantity' => 1,
            'unit_cost' => 2,
            'exposure_value' => 2,
            'currency_code' => 'USD',
            'started_at' => now(),
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'evidence' => json_encode(['baseline_reason' => 'stock_take']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $caseId;
    }
}
