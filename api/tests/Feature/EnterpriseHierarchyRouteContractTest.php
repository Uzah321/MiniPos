<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Http\Request;
use Modules\Company\Http\Controllers\EnterpriseHierarchyController;
use Modules\Company\Http\Controllers\EnterpriseResourceController;
use ReflectionClass;
use Tests\TestCase;

class EnterpriseHierarchyRouteContractTest extends TestCase
{
    public function test_tenant_hierarchy_is_authenticated_back_office_and_scope_filtered(): void
    {
        $route = app('router')->getRoutes()
            ->match(Request::create('/api/pos/enterprise/hierarchy', 'GET'));

        $this->assertSame(EnterpriseHierarchyController::class.'@tenantTree', $route->getActionName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains(ResolveCompanyFromDomain::class, $middleware);
        $this->assertContains(CheckCompanyLicense::class, $middleware);
        $this->assertContains('workspace:back-office', $middleware);
        $this->assertNotContains('platform.admin', $middleware);

        $source = file_get_contents((new ReflectionClass(EnterpriseHierarchyController::class))->getFileName());
        $this->assertStringContainsString('accessibleBranchIds', $source);
        $this->assertStringContainsString("where('company_id', \$company->id)", $source);
        $this->assertStringContainsString("where('pos_user_id', \$user->getKey())", $source);
        $this->assertStringContainsString("\$assignments->where('scope_type', 'till')", $source);
    }

    public function test_cross_tenant_hierarchy_crud_remains_platform_owner_only(): void
    {
        $route = app('router')->getRoutes()
            ->match(Request::create('/api/enterprise/hierarchy', 'GET'));

        $this->assertSame(EnterpriseHierarchyController::class.'@tree', $route->getActionName());
        $this->assertContains('platform.admin', $route->gatherMiddleware());
    }

    public function test_each_enterprise_resource_has_flat_platform_managed_crud_routes(): void
    {
        foreach (['countries', 'companies', 'brands', 'complexes', 'shops', 'tills'] as $resource) {
            $index = app('router')->getRoutes()
                ->match(Request::create("/api/enterprise/{$resource}", 'GET'));
            $store = app('router')->getRoutes()
                ->match(Request::create("/api/enterprise/{$resource}", 'POST'));
            $update = app('router')->getRoutes()
                ->match(Request::create("/api/enterprise/{$resource}/resource-id", 'PATCH'));
            $destroy = app('router')->getRoutes()
                ->match(Request::create("/api/enterprise/{$resource}/resource-id", 'DELETE'));

            foreach ([$index, $store, $update, $destroy] as $route) {
                $this->assertStringStartsWith(EnterpriseResourceController::class.'@', $route->getActionName());
                $this->assertContains('platform.admin', $route->gatherMiddleware());
            }
        }
    }
}
