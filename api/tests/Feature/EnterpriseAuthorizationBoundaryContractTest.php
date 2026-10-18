<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnterpriseAuthorizationBoundaryContractTest extends TestCase
{
    public function test_pos_user_crud_is_permission_and_branch_scope_guarded(): void
    {
        $contracts = [
            ['GET', '/api/pos-users', 'company.permission:manage_user'],
            ['POST', '/api/pos-users', 'company.permission:create_user'],
            ['GET', '/api/pos-users/user-1', 'company.permission:manage_user'],
            ['PUT', '/api/pos-users/user-1', 'company.permission:manage_user'],
            ['DELETE', '/api/pos-users/user-1', 'company.permission:manage_user'],
        ];

        foreach ($contracts as [$method, $uri, $permission]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('workspace:back-office', $middleware, $uri);
            $this->assertContains($permission, $middleware, $uri);
        }

        $source = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/PosUserController.php'
        ));
        $this->assertStringContainsString('accessibleBranchIds(', $source);
        $this->assertStringContainsString('canManagePosUserTarget(', $source);
        $this->assertStringContainsString('A company-scope permission is required', $source);
        $this->assertStringContainsString('USER_SCOPE_DENIED', $source);
    }

    public function test_legacy_permission_helper_defers_to_canonical_assignments(): void
    {
        $source = file_get_contents(app_path('Helpers/ResponseHelper.php'));

        $this->assertStringContainsString('usesScopedAssignments($authenticatedUser)', $source);
        $this->assertStringContainsString('$authorization->can($authenticatedUser', $source);
        $this->assertStringContainsString('hasPlatformSessionAccess($authenticatedUser)', $source);
    }

    public function test_sales_and_cash_commands_have_explicit_scoped_permission_middleware(): void
    {
        $contracts = [
            ['POST', '/api/v1/sales', 'company.permission:create_sale'],
            ['POST', '/api/pos/sales', 'company.permission:create_sale'],
            ['GET', '/api/pos/cashup/active-shift-summary', 'company.permission:cashup_shift'],
            ['POST', '/api/pos/cashup', 'company.permission:cashup_shift'],
            ['POST', '/api/pos/cashup/end-shift', 'company.permission:end_shift'],
            ['POST', '/api/pos/cash-drawer-movements', 'company.permission:cashup_shift'],
        ];

        foreach ($contracts as [$method, $uri, $permission]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $this->assertContains($permission, $route->gatherMiddleware(), $uri);
        }
    }

    public function test_assignment_management_rejects_role_and_target_escalation(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/AccessAssignmentController.php'
        ));

        $this->assertStringContainsString("'user_q' => ['nullable', 'string', 'max:120']", $source);
        $this->assertStringContainsString('assertRoleCanBeGranted(', $source);
        $this->assertStringContainsString('canManageAssignmentTarget(', $source);
        $this->assertStringContainsString('permissions you do not hold at this scope', $source);
    }
}
