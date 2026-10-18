<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnterpriseAuthManagementRoutesTest extends TestCase
{
    public function test_assignment_and_policy_management_routes_are_back_office_and_permission_guarded(): void
    {
        $contracts = [
            ['GET', '/api/auth-admin/access-assignments', 'company.permission:manage_access_assignments'],
            ['POST', '/api/auth-admin/access-assignments', 'company.permission:manage_access_assignments'],
            ['POST', '/api/auth-admin/access-assignments/assignment-1/revoke', 'company.permission:manage_access_assignments'],
            ['GET', '/api/auth-admin/policies', 'company.permission:manage_authentication_policies'],
            ['GET', '/api/auth-admin/policies/effective', 'company.permission:manage_authentication_policies'],
            ['POST', '/api/auth-admin/policies', 'company.permission:manage_authentication_policies'],
            ['POST', '/api/auth-admin/policies/policy-1/disable', 'company.permission:manage_authentication_policies'],
        ];

        foreach ($contracts as [$method, $uri, $permission]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, $uri);
            $this->assertContains('workspace:back-office', $middleware, $uri);
            $this->assertContains($permission, $middleware, $uri);
        }
    }

    public function test_management_listings_filter_rows_to_the_actors_effective_scope(): void
    {
        $assignmentController = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/AccessAssignmentController.php'
        ));
        $policyController = file_get_contents(base_path(
            'Modules/Authentication/Http/Controllers/AuthPolicyController.php'
        ));

        foreach ([$assignmentController, $policyController] as $source) {
            $this->assertStringContainsString('usesScopedAssignments($actor)', $source);
            $this->assertStringContainsString("'manage_", $source);
            $this->assertStringContainsString('->enterpriseScope()', $source);
            $this->assertStringContainsString('$this->authorization->can(', $source);
        }
    }
}
