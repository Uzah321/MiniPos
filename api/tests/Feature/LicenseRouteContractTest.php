<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LicenseRouteContractTest extends TestCase
{
    public function test_login_and_company_bootstrap_remain_reachable_when_license_is_expired(): void
    {
        foreach (['api/pos-auth/login', 'api/pos/company', 'api/pos/licenses/renew-offline'] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "Missing {$uri}");
            $this->assertContains(ResolveCompanyFromDomain::class, $route->gatherMiddleware());
            $this->assertNotContains(CheckCompanyLicense::class, $route->gatherMiddleware());
        }
    }

    public function test_authenticated_runtime_routes_still_enforce_the_license(): void
    {
        foreach (['api/pos-auth/me', 'api/pos-users', 'api/pos/license/status'] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "Missing {$uri}");
            $this->assertContains(CheckCompanyLicense::class, $route->gatherMiddleware());
        }
    }

    public function test_platform_company_management_has_super_admin_middleware(): void
    {
        foreach (['api/companies', 'api/companies/{company}/licenses/renew', 'api/company-branches/{branch}'] as $uri) {
            $routes = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === $uri);

            $this->assertNotEmpty($routes, "Missing {$uri}");
            foreach ($routes as $route) {
                $this->assertContains('platform.admin', $route->middleware());
            }
        }
    }
}
