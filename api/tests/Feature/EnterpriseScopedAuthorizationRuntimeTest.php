<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Tests\TestCase;

class EnterpriseScopedAuthorizationRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_effective_assignment_drives_permission_and_branch_scope(): void
    {
        DB::table('companies')->insert(['id' => 'company-1', 'country_id' => 'country-1']);
        DB::table('company_branches')->insert([
            ['id' => 'branch-1', 'company_id' => 'company-1'],
            ['id' => 'branch-2', 'company_id' => 'company-1'],
        ]);
        DB::table('shops')->insert([
            ['id' => 'shop-1', 'company_id' => 'company-1', 'country_id' => 'country-1', 'brand_id' => 'brand-1', 'complex_id' => 'complex-1', 'branch_id' => 'branch-1'],
            ['id' => 'shop-2', 'company_id' => 'company-1', 'country_id' => 'country-1', 'brand_id' => 'brand-1', 'complex_id' => 'complex-1', 'branch_id' => 'branch-2'],
        ]);
        DB::table('roles')->insert(['id' => 'role-1', 'company_id' => 'company-1', 'name' => 'Cashier']);
        DB::table('permissions')->insert(['id' => 'permission-1', 'name' => 'view_sales_reports']);
        DB::table('role_permissions')->insert(['role_id' => 'role-1', 'permission_id' => 'permission-1']);
        DB::table('pos_users')->insert([
            'id' => 'user-1', 'company_id' => 'company-1', 'role_id' => 'role-1',
            'is_active' => true,
        ]);
        DB::table('user_access_assignments')->insert([
            'id' => 'assignment-1',
            'pos_user_id' => 'user-1',
            'role_id' => 'role-1',
            'scope_type' => 'shop',
            'scope_key' => 'shop:shop-1',
            'country_id' => 'country-1',
            'company_id' => 'company-1',
            'brand_id' => 'brand-1',
            'complex_id' => 'complex-1',
            'shop_id' => 'shop-1',
            'status' => 'active',
        ]);

        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('isPlatformAdministrator')->willReturn(false);
        $authorization = new EnterpriseAuthorizationService($superAdmins);
        $user = PosUser::query()->findOrFail('user-1');
        $scope = new AuthorizationScope(
            countryId: 'country-1',
            companyId: 'company-1',
            brandId: 'brand-1',
            complexId: 'complex-1',
            shopId: 'shop-1',
            branchId: 'branch-1',
        );

        $this->assertTrue($authorization->usesScopedAssignments($user));
        $this->assertTrue($authorization->hasScopeAccess($user, $scope));
        $this->assertTrue($authorization->can($user, 'view_sales_reports', $scope));
        $this->assertFalse($authorization->can($user, 'manage_user', $scope));
        $this->assertSame(
            ['view_sales_reports'],
            $authorization->effectivePermissionNames($user, $scope)
        );
        $this->assertSame(['Cashier'], $authorization->effectiveRoleNames($user, $scope));
        $this->assertSame(
            ['branch-1'],
            $authorization->accessibleBranchIds($user, 'company-1', ['branch-1', 'branch-2'])
        );

        DB::table('user_access_assignments')->where('id', 'assignment-1')->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        $this->assertTrue($authorization->usesScopedAssignments($user));
        $this->assertFalse($authorization->hasScopeAccess($user, $scope));
        $this->assertSame([], $authorization->effectivePermissionNames($user, $scope));
        $this->assertSame([], $authorization->effectiveRoleNames($user, $scope));
        $this->assertSame([], $authorization->accessibleBranchIds(
            $user,
            'company-1',
            ['branch-1', 'branch-2']
        ));
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('country_id')->nullable();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id')->nullable();
            $table->string('brand_id')->nullable();
            $table->string('complex_id')->nullable();
            $table->string('branch_id');
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->nullable();
            $table->string('name');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->string('role_id');
            $table->string('permission_id');
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('role_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('user_access_assignments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('pos_user_id');
            $table->string('role_id');
            $table->string('scope_type');
            $table->string('scope_key');
            $table->string('country_id')->nullable();
            $table->string('company_id')->nullable();
            $table->string('brand_id')->nullable();
            $table->string('complex_id')->nullable();
            $table->string('shop_id')->nullable();
            $table->string('till_id')->nullable();
            $table->string('status');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }
}
