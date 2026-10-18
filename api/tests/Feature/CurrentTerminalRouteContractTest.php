<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CurrentTerminalRouteContractTest extends TestCase
{
    public function test_pre_login_terminal_configuration_is_till_bound_without_a_user_session(): void
    {
        $middleware = Route::getRoutes()
            ->match(Request::create('/api/pos/configuration/bootstrap', 'GET'))
            ->gatherMiddleware();

        $this->assertContains(ResolveCompanyFromDomain::class, $middleware);
        $this->assertContains(CheckCompanyLicense::class, $middleware);
        $this->assertContains(ResolveTillFromToken::class, $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
        $this->assertNotContains('workspace:front-office', $middleware);
    }

    public function test_front_office_terminal_routes_are_till_bound(): void
    {
        foreach ([
            ['GET', '/api/pos/tills/current/settings'],
            ['PATCH', '/api/pos/tills/current/settings'],
            ['GET', '/api/pos/tills/current/printers'],
            ['POST', '/api/pos/tills/current/printers'],
            ['PUT', '/api/pos/tills/current/printers/printer-id'],
            ['DELETE', '/api/pos/tills/current/printers/printer-id'],
            ['GET', '/api/pos/printers/resolve'],
            ['GET', '/api/pos/tills/current/qz-certificate'],
            ['POST', '/api/pos/tills/current/qz-certificate'],
            ['DELETE', '/api/pos/tills/current/qz-certificate'],
        ] as [$method, $uri]) {
            $middleware = Route::getRoutes()->match(Request::create($uri, $method))->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "{$method} {$uri}");
            $this->assertContains(ResolveCompanyFromDomain::class, $middleware, "{$method} {$uri}");
            $this->assertContains(CheckCompanyLicense::class, $middleware, "{$method} {$uri}");
            $this->assertContains('workspace:front-office', $middleware, "{$method} {$uri}");
            $this->assertContains(ResolveTillFromToken::class, $middleware, "{$method} {$uri}");
        }
    }

    public function test_qz_runtime_routes_accept_both_workspaces_but_require_a_till_for_front_office(): void
    {
        foreach ([
            ['GET', '/api/qz/cert'],
            ['POST', '/api/qz/sign'],
        ] as [$method, $uri]) {
            $middleware = Route::getRoutes()->match(Request::create($uri, $method))->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "{$method} {$uri}");
            $this->assertContains('workspace:front-office,back-office', $middleware, "{$method} {$uri}");
            $this->assertContains(
                ResolveTillFromToken::class.':front-office-only',
                $middleware,
                "{$method} {$uri}"
            );
        }
    }

    public function test_existing_back_office_printer_crud_remains_back_office_only(): void
    {
        foreach ([
            ['GET', '/api/pos/printers'],
            ['POST', '/api/pos/printers'],
            ['GET', '/api/pos/printers/printer-id'],
            ['PUT', '/api/pos/printers/printer-id'],
            ['DELETE', '/api/pos/printers/printer-id'],
        ] as [$method, $uri]) {
            $middleware = Route::getRoutes()->match(Request::create($uri, $method))->gatherMiddleware();

            $this->assertContains('workspace:back-office', $middleware, "{$method} {$uri}");
            $this->assertNotContains('workspace:front-office', $middleware, "{$method} {$uri}");
            $this->assertNotContains(ResolveTillFromToken::class, $middleware, "{$method} {$uri}");
        }
    }
}
