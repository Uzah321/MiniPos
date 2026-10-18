<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockPosWhenDayEndRunning;
use App\Http\Middleware\RequireWorkspaceAbility;
use App\Http\Middleware\ResolveTillFromToken;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class WorkspaceRouteBoundaryTest extends TestCase
{
    public function test_workspace_middleware_rejects_the_opposite_token_ability(): void
    {
        $request = Request::create('/api/pos/products', 'POST');
        $request->setUserResolver(fn () => new class
        {
            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:front-office';
            }
        });

        $response = (new RequireWorkspaceAbility)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'back-office'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('WORKSPACE_TOKEN_MISMATCH', $response->getData(true)['code']);
        $this->assertSame(
            'This session does not have access to the requested workspace.',
            $response->getData(true)['message']
        );
    }

    public function test_workspace_middleware_accepts_only_the_declared_token_ability(): void
    {
        $request = Request::create('/api/pos/sales', 'POST');
        $request->setUserResolver(fn () => new class
        {
            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:front-office';
            }
        });

        $response = (new RequireWorkspaceAbility)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'front-office'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['ok']);
    }

    public function test_workspace_middleware_can_explicitly_accept_either_workspace_ability(): void
    {
        foreach (['front-office', 'back-office'] as $issuedWorkspace) {
            $request = Request::create('/api/pos/trading-day/current', 'GET');
            $request->setUserResolver(fn () => new class($issuedWorkspace)
            {
                public function __construct(private readonly string $issuedWorkspace) {}

                public function currentAccessToken(): object
                {
                    return $this;
                }

                public function can(string $ability): bool
                {
                    return $ability === 'workspace:'.$this->issuedWorkspace;
                }
            });

            $response = (new RequireWorkspaceAbility)->handle(
                $request,
                fn () => response()->json(['ok' => true]),
                'front-office',
                'back-office'
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($response->getData(true)['ok']);
        }
    }

    public function test_optional_till_resolution_skips_back_office_but_still_requires_a_front_office_till(): void
    {
        $middleware = new ResolveTillFromToken;

        $backOfficeRequest = Request::create('/api/pos/receipts/register', 'GET');
        $backOfficeRequest->setUserResolver(fn () => new class
        {
            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:back-office';
            }
        });

        $backOfficeResponse = $middleware->handle(
            $backOfficeRequest,
            fn () => response()->json(['ok' => true]),
            'front-office-only'
        );

        $this->assertSame(200, $backOfficeResponse->getStatusCode());

        $frontOfficeRequest = Request::create('/api/pos/receipts/register', 'GET');
        $frontOfficeRequest->setUserResolver(fn () => new class
        {
            public function currentAccessToken(): object
            {
                return $this;
            }

            public function can(string $ability): bool
            {
                return $ability === 'workspace:front-office';
            }
        });

        $frontOfficeResponse = $middleware->handle(
            $frontOfficeRequest,
            fn () => response()->json(['ok' => true]),
            'front-office-only'
        );

        $this->assertSame(403, $frontOfficeResponse->getStatusCode());
        $this->assertSame('TILL_NOT_REGISTERED', $frontOfficeResponse->getData(true)['code']);
    }

    public function test_workspace_middleware_rejects_stateful_transient_tokens_that_can_every_ability(): void
    {
        $request = Request::create('/api/pos/products', 'POST');
        $request->setUserResolver(fn () => new class
        {
            public function currentAccessToken(): TransientToken
            {
                return new TransientToken;
            }
        });

        $response = (new RequireWorkspaceAbility)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'back-office'
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('WORKSPACE_TOKEN_MISMATCH', $response->getData(true)['code']);
        $this->assertStringNotContainsString('token', strtolower($response->getData(true)['message']));
    }

    public function test_back_office_tenant_routes_declare_the_back_office_ability(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        foreach ([
            ['POST', '/api/pos/branches'],
            ['DELETE', "/api/pos/branches/{$id}"],
            ['POST', "/api/pos/branches/{$id}/contacts"],
            ['GET', '/api/pos-users'],
            ['POST', '/api/pos-users'],
            ['DELETE', "/api/pos-users/{$id}"],
            ['POST', '/api/pos/general-settings/sales-screen-layout'],
            ['POST', '/api/pos/product-categories'],
            ['POST', '/api/pos/products'],
            ['POST', "/api/pos/products/{$id}/adjust-stock"],
            ['POST', '/api/pos/stock/issue-to-front'],
            ['POST', '/api/pos/purchases/orders'],
            ['POST', '/api/pos/stock-transfers'],
            ['POST', '/api/pos/stock-wastages'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation/live'],
            ['POST', '/api/pos/receipts/cancel'],
            ['POST', '/api/pos/discounts'],
            ['POST', '/api/pos/credit-customers'],
            ['POST', '/api/pos/quotations'],
            ['PUT', '/api/pos/zimra/settings'],
            ['GET', '/api/pos/reports/tax/summary'],
            ['PUT', "/api/pos/branches/{$id}/settings/example"],
            ['POST', '/api/pos/shift-configurations'],
            ['POST', '/api/pos/tills'],
            ['POST', "/api/pos/tills/{$id}/activate"],
            ['POST', '/api/pos/printers'],
            ['POST', '/api/pos/qz-certificates'],
            ['POST', '/api/pos/roles'],
            ['PUT', "/api/pos/roles/{$id}/permissions/sync"],
            ['POST', '/api/pos/currencies'],
            ['POST', '/api/pos/payment-types'],
            ['POST', '/api/pos/food-manufacturing/recipes'],
            ['POST', '/api/v1/fiscaldevices'],
            ['POST', '/api/v1/zimraintegrations'],
            ['POST', '/api/v1/generalsettings'],
            ['POST', '/api/v1/rolesandpermissions'],
        ] as [$method, $uri]) {
            $this->assertWorkspace($method, $uri, 'back-office');
        }
    }

    public function test_front_office_operational_routes_declare_the_front_office_ability(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        foreach ([
            ['POST', '/api/v1/sales'],
            ['POST', '/api/pos/sales'],
            ['POST', '/api/pos-users/close-active-shift'],
            ['GET', '/api/pos/shift-configurations/current'],
            ['POST', '/api/pos/shift-configurations/detect-current-shift'],
            ['GET', '/api/pos/tills/current/resolve'],
            ['GET', '/api/pos/printers/resolve'],
            ['GET', '/api/pos/credit-customers/pos-lookup'],
            ['POST', "/api/pos/credit-customers/{$id}/pos-payments"],
            ['POST', '/api/pos/cashup'],
            ['POST', '/api/pos/cashup/end-shift'],
            ['POST', '/api/pos/cash-drawer-movements'],
            ['POST', '/api/pos/price-level-overrides/apply'],
            ['POST', '/api/pos/discounts/calculate'],
            ['POST', '/api/pos/quotations/code/QUOTE-1/convert'],
            ['GET', '/api/pos/front/quotations'],
            ['POST', '/api/pos/front/quotations'],
            ['GET', "/api/pos/front/quotations/{$id}"],
            ['PUT', "/api/pos/front/quotations/{$id}"],
            ['POST', "/api/pos/front/quotations/{$id}/issue"],
            ['POST', "/api/pos/front/quotations/{$id}/cancel"],
            ['GET', '/api/pos/front/quotations/code/QUOTE-1'],
            ['POST', '/api/pos/front/quotations/code/QUOTE-1/convert'],
        ] as [$method, $uri]) {
            $this->assertWorkspace($method, $uri, 'front-office');

            if (str_contains($uri, '/front/quotations')) {
                $middleware = $this->middlewareFor($method, $uri);
                $this->assertContains(ResolveTillFromToken::class, $middleware);
                $this->assertContains(BlockPosWhenDayEndRunning::class, $middleware);
            }
        }
    }

    public function test_current_trading_day_accepts_both_workspaces_while_day_end_stays_back_office(): void
    {
        $this->assertEitherWorkspace('GET', '/api/pos/trading-day/current');

        foreach ([
            ['GET', '/api/pos/trading-day/day-end/preflight'],
            ['GET', '/api/pos/trading-day/day-end/progress/day-end-run-1'],
            ['POST', '/api/pos/trading-day/day-end'],
            ['POST', '/api/pos/trading-day/day-end/catch-up'],
            ['POST', '/api/pos/trading-day/day-end/rollback'],
            ['POST', '/api/pos/trading-day/day-end/force-front-stock-closing'],
        ] as [$method, $uri]) {
            $this->assertWorkspace($method, $uri, 'back-office');
        }
    }

    public function test_front_office_day_end_routes_are_till_bound_without_changing_back_office_routes(): void
    {
        foreach ([
            ['GET', '/api/pos/front/trading-day/day-end/preflight'],
            ['GET', '/api/pos/front/trading-day/day-end/progress/day-end-run-1'],
            ['POST', '/api/pos/front/trading-day/day-end'],
            ['POST', '/api/pos/front/trading-day/day-end/catch-up'],
            ['POST', '/api/pos/front/trading-day/day-end/rollback'],
            ['POST', '/api/pos/front/trading-day/day-end/force-front-stock-closing'],
        ] as [$method, $uri]) {
            $middleware = $this->middlewareFor($method, $uri);

            $this->assertWorkspace($method, $uri, 'front-office');
            $this->assertContains(ResolveTillFromToken::class, $middleware);
            $this->assertContains('business.mode:hospitality', $middleware);
            $this->assertContains('company.permission:general_procedure', $middleware);
        }
    }

    public function test_read_only_receipt_register_accepts_both_workspaces_while_receipt_actions_stay_back_office(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        foreach ([
            ['GET', '/api/pos/receipts/register'],
            ['GET', "/api/pos/receipts/register/{$id}"],
            ['GET', "/api/pos/receipts/register/{$id}/pdf"],
            ['GET', "/api/pos/receipts/register/{$id}/pdf-file"],
        ] as [$method, $uri]) {
            $this->assertEitherWorkspace($method, $uri);
            $this->assertContains(
                ResolveTillFromToken::class.':front-office-only',
                $this->middlewareFor($method, $uri),
                "{$method} {$uri} must resolve a till for Front Office tokens."
            );
        }

        foreach ([
            ['GET', '/api/pos/receipts/cancelled'],
            ['GET', '/api/pos/receipts/find'],
            ['POST', '/api/pos/receipts/reprint'],
            ['POST', '/api/pos/receipts/cancel'],
        ] as [$method, $uri]) {
            $this->assertWorkspace($method, $uri, 'back-office');
        }
    }

    public function test_tenant_branch_mutations_require_branch_management_permission(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        foreach ([
            ['POST', '/api/pos/branches'],
            ['PUT', "/api/pos/branches/{$id}"],
            ['DELETE', "/api/pos/branches/{$id}"],
            ['POST', "/api/pos/branches/{$id}/contacts"],
            ['PUT', "/api/pos/branch-contacts/{$id}"],
            ['DELETE', "/api/pos/branch-contacts/{$id}"],
        ] as [$method, $uri]) {
            $this->assertContains(
                'company.permission:manage_branch_settings',
                $this->middlewareFor($method, $uri),
                "{$method} {$uri} must require branch-management permission."
            );
        }
    }

    public function test_shared_bootstrap_and_pos_lookup_routes_remain_available_to_both_workspaces(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        foreach ([
            ['GET', '/api/pos-auth/me'],
            ['GET', '/api/pos/workspace/bootstrap'],
            ['GET', '/api/pos/branches'],
            ['GET', '/api/pos/products'],
            ['GET', "/api/pos/products/{$id}"],
            ['GET', '/api/pos/product-categories'],
            ['GET', '/api/pos/product-sub-categories'],
            ['GET', "/api/pos/products/{$id}/visible-prices"],
            ['GET', '/api/pos/uoms'],
            ['GET', '/api/pos/pos/uoms'],
            ['GET', '/api/pos/taxes'],
            ['GET', '/api/pos/currencies'],
            ['POST', '/api/pos/currencies/convert'],
            ['GET', '/api/pos/payment-types'],
            ['GET', '/api/pos/general-settings/barcode-scan'],
            ['GET', '/api/pos/general-settings/touch-keyboard'],
            ['POST', '/api/pos/manager-approvals/verify'],
        ] as [$method, $uri]) {
            $middleware = $this->middlewareFor($method, $uri);

            $this->assertNotContains('workspace:back-office', $middleware, "{$method} {$uri} must remain shared.");
            $this->assertNotContains('workspace:front-office', $middleware, "{$method} {$uri} must remain shared.");
        }
    }

    public function test_every_authenticated_api_mutation_has_an_explicit_workspace_or_is_allowlisted_shared(): void
    {
        $sharedMutations = [
            'POST api/pos-auth/logout',
            'POST api/pos-auth/logout-all',
            'POST api/pos/manager-approvals/verify',
            'POST api/pos/currencies/convert',
        ];
        $dualWorkspaceMutations = [
            'POST api/qz/sign',
        ];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $methods = array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
            if ($methods === []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)
                || in_array('platform.admin', $middleware, true)
                || in_array('sync.node', $middleware, true)) {
                continue;
            }

            $hasBackOffice = in_array('workspace:back-office', $middleware, true);
            $hasFrontOffice = in_array('workspace:front-office', $middleware, true);
            $hasDualWorkspace = in_array('workspace:front-office,back-office', $middleware, true);

            foreach ($methods as $method) {
                $key = $method.' '.$route->uri();

                if (in_array($key, $dualWorkspaceMutations, true)) {
                    $this->assertTrue($hasDualWorkspace, "Dual-workspace mutation {$key} must declare both workspaces.");

                    continue;
                }

                if (in_array($key, $sharedMutations, true)) {
                    $this->assertFalse($hasBackOffice || $hasFrontOffice || $hasDualWorkspace, "Shared mutation {$key} must not be workspace locked.");

                    continue;
                }

                $this->assertTrue(
                    ! $hasDualWorkspace && ($hasBackOffice xor $hasFrontOffice),
                    "Authenticated mutation {$key} must declare exactly one workspace policy."
                );
            }
        }
    }

    public function test_legacy_v1_sales_surface_exposes_post_only(): void
    {
        $salesRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'api/v1/sales'
                || $route->uri() === 'api/v1/sales/{sale}');

        $this->assertCount(1, $salesRoutes);
        $this->assertSame(['POST'], $salesRoutes->first()->methods());
    }

    private function assertWorkspace(string $method, string $uri, string $workspace): void
    {
        $middleware = $this->middlewareFor($method, $uri);

        $this->assertContains(
            'workspace:'.$workspace,
            $middleware,
            "{$method} {$uri} must require the {$workspace} token ability."
        );
        $this->assertNotContains(
            'workspace:'.($workspace === 'back-office' ? 'front-office' : 'back-office'),
            $middleware,
            "{$method} {$uri} must not accept the opposite workspace token."
        );
    }

    private function assertEitherWorkspace(string $method, string $uri): void
    {
        $middleware = $this->middlewareFor($method, $uri);

        $this->assertContains(
            'workspace:front-office,back-office',
            $middleware,
            "{$method} {$uri} must explicitly accept either authenticated workspace."
        );
        $this->assertNotContains('workspace:front-office', $middleware);
        $this->assertNotContains('workspace:back-office', $middleware);
    }

    private function middlewareFor(string $method, string $uri): array
    {
        return app('router')->getRoutes()
            ->match(Request::create($uri, $method))
            ->gatherMiddleware();
    }
}
