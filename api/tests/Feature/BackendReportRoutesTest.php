<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTillFromToken;
use App\Http\Middleware\BlockPosWhenDayEndRunning;
use Illuminate\Http\Request;
use Modules\Product\Http\Controllers\TradingDayEndController;
use ReflectionMethod;
use Tests\TestCase;

class BackendReportRoutesTest extends TestCase
{
    public function test_inventory_downloads_are_authenticated_back_office_routes(): void
    {
        foreach ([
            '/api/pos/reports/stock/download/valuation/pdf',
            '/api/pos/product-price-list/download/xlsx',
            '/api/pos/stock/front/download/pdf',
            '/api/pos/stock/back/download/xlsx',
        ] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "Inventory download {$uri} must require authentication.");
            $this->assertNotContains(
                ResolveTillFromToken::class,
                $middleware,
                "Back Office inventory download {$uri} must not require a registered till."
            );
        }
    }

    public function test_tax_exports_and_credit_statements_are_back_office_routes(): void
    {
        foreach ([
            '/api/pos/reports/tax/export/pdf',
            '/api/pos/reports/tax/export/xlsx',
            '/api/pos/reports/tax/export/csv',
            '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/statement/pdf',
        ] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));

            $this->assertNotContains(
                ResolveTillFromToken::class,
                $route->gatherMiddleware(),
                "Back Office report route {$uri} must not require a registered till."
            );
        }
    }

    public function test_tax_exports_require_a_tax_reporting_permission(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/pos/reports/tax/export/pdf', 'GET')
        );
        $middleware = $route->gatherMiddleware();

        $this->assertTrue(
            collect($middleware)->contains(fn ($entry) =>
                str_contains((string) $entry, 'view_tax_reports')
                && str_contains((string) $entry, 'tax_management')
            ),
            'Tax report routes must require a tax reporting permission.'
        );
    }

    public function test_purchase_manufacturing_recipe_and_discount_downloads_are_backend_routes(): void
    {
        foreach ([
            '/api/pos/purchases/reports/orders/export/pdf',
            '/api/pos/food-manufacturing/recipes/cost-report/xlsx',
            '/api/pos/food-manufacturing/reports/production/excel',
            '/api/pos/food-manufacturing/reports/production/pdf',
            '/api/pos/food-manufacturing/planning/materials/excel',
            '/api/pos/food-manufacturing/planning/materials/pdf',
            '/api/pos/food-manufacturing/planning/plans/excel',
            '/api/pos/food-manufacturing/planning/plans/pdf',
            '/api/pos/discount-reports/export',
        ] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "Report download {$uri} must require authentication.");
            $this->assertNotContains(
                ResolveTillFromToken::class,
                $middleware,
                "Back Office report download {$uri} must not require a registered till."
            );
        }
    }

    public function test_hospitality_back_office_operations_do_not_require_a_browser_till(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $routes = [
            ['GET', '/api/pos/front-stock/daily-closings'],
            ['GET', '/api/pos/front-stock/daily-closings/snapshot'],
            ['GET', "/api/pos/front-stock/daily-closings/{$id}"],
            ['POST', '/api/pos/front-stock/daily-closings'],
            ['POST', '/api/pos/stock/issue-enterprise'],
            ['POST', '/api/pos/stock/issue-to-front'],
            ['GET', '/api/pos/issued-stock'],
            ['GET', "/api/pos/issued-stock/{$id}"],
            ['GET', "/api/pos/issued-stock/{$id}/pdf"],
            ['GET', '/api/pos/trading-day/current'],
            ['GET', '/api/pos/trading-day/day-end/preflight'],
            ['GET', '/api/pos/trading-day/day-end/progress/day-end-run-1'],
            ['POST', '/api/pos/trading-day/day-end'],
            ['POST', '/api/pos/trading-day/day-end/catch-up'],
            ['POST', '/api/pos/trading-day/day-end/rollback'],
            ['POST', '/api/pos/trading-day/day-end/force-front-stock-closing'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation/live'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation/live/excel'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation/live/pdf'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation'],
            ['GET', "/api/pos/reports/menu-recipe-daily-stock-reconciliation/{$id}"],
            ['GET', "/api/pos/reports/menu-recipe-daily-stock-reconciliation/{$id}/excel"],
            ['GET', "/api/pos/reports/menu-recipe-daily-stock-reconciliation/{$id}/pdf"],
            ['GET', '/api/pos/reports/closing-stock-movements/closings'],
            ['GET', '/api/pos/reports/closing-stock-movements/preview'],
            ['GET', '/api/pos/reports/closing-stock-movements/excel'],
            ['GET', '/api/pos/reports/closing-stock-movements/pdf'],
            ['GET', '/api/pos/stock/issue-enterprise/report'],
            ['GET', '/api/pos/stock/issue-enterprise/report/excel'],
            ['GET', '/api/pos/stock/issue-enterprise/report/pdf'],
            ['GET', '/api/pos/reports/stock-issued-to-front/preview'],
            ['GET', '/api/pos/reports/stock-issued-to-front/excel'],
            ['GET', '/api/pos/reports/stock-issued-to-front/pdf'],
            ['GET', '/api/pos/stock-wastages/products'],
            ['POST', '/api/pos/stock-wastages'],
            ['GET', "/api/pos/stock-wastages/{$id}"],
            ['GET', '/api/pos/reports/stock-wastages/preview'],
            ['GET', '/api/pos/reports/stock-wastages/excel'],
            ['GET', '/api/pos/reports/stock-wastages/pdf'],
        ];

        foreach ($routes as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "Back Office hospitality route {$method} {$uri} must require authentication.");
            $this->assertNotContains(
                ResolveTillFromToken::class,
                $middleware,
                "Back Office hospitality route {$method} {$uri} must not require a registered till."
            );
        }
    }

    public function test_day_end_mutations_require_general_procedure_but_status_remains_readable(): void
    {
        foreach ([
            ['POST', '/api/pos/trading-day/day-end'],
            ['POST', '/api/pos/trading-day/day-end/catch-up'],
            ['POST', '/api/pos/trading-day/day-end/rollback'],
            ['POST', '/api/pos/trading-day/day-end/force-front-stock-closing'],
        ] as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertContains('company.permission:general_procedure', $route->gatherMiddleware());
        }

        foreach ([
            ['GET', '/api/pos/trading-day/current'],
            ['GET', '/api/pos/trading-day/day-end/preflight'],
            ['GET', '/api/pos/trading-day/day-end/progress/day-end-run-1'],
        ] as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertNotContains('company.permission:general_procedure', $route->gatherMiddleware());
        }
    }

    public function test_stock_issue_load_and_post_permissions_are_aligned(): void
    {
        foreach (['/api/pos/stock/front', '/api/pos/stock/back'] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertContains(
                'company.permission:view_stock_reports,boh_issue_stock,inventory_management',
                $route->gatherMiddleware()
            );
        }

        foreach (['/api/pos/stock/issue-enterprise', '/api/pos/stock/issue-to-front'] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'POST'));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('company.permission:boh_issue_stock,inventory_management', $middleware);
            $this->assertNotContains(
                'company.permission:view_stock_reports,boh_issue_stock,inventory_management',
                $middleware,
                'Read-only report access must not permit posting a stock issue.'
            );
        }
    }

    public function test_till_is_resolved_before_the_day_end_lock_on_every_pos_route(): void
    {
        foreach ([
            ['POST', '/api/pos/sales'],
            ['POST', '/api/v1/sales'],
            ['GET', '/api/pos/credit-customers/pos-lookup'],
            ['GET', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-account'],
            ['POST', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-payments'],
            ['POST', '/api/pos/credit-customers/00000000-0000-0000-0000-000000000001/pos-payments/00000000-0000-0000-0000-000000000002/reverse'],
        ] as [$method, $uri]) {
            $middleware = app('router')->getRoutes()
                ->match(Request::create($uri, $method))
                ->gatherMiddleware();
            $tillPosition = array_search(ResolveTillFromToken::class, $middleware, true);
            $lockPosition = array_search(BlockPosWhenDayEndRunning::class, $middleware, true);

            $this->assertIsInt($tillPosition, "{$method} {$uri} must resolve its till.");
            $this->assertIsInt($lockPosition, "{$method} {$uri} must enforce the Day End lock.");
            $this->assertLessThan(
                $lockPosition,
                $tillPosition,
                "{$method} {$uri} must resolve the authoritative till before checking the branch Day End lock."
            );
        }
    }

    public function test_till_bound_day_end_controls_reject_body_and_header_branch_spoofing(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);
        app()->instance('till', (object) [
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
        ]);

        try {
            foreach (['body', 'header'] as $source) {
                $request = Request::create(
                    '/api/pos/trading-day/current',
                    'GET',
                    $source === 'body' ? ['branch_id' => 'branch-b'] : []
                );
                if ($source === 'header') {
                    $request->headers->set('X-Branch-ID', 'branch-b');
                }

                $middlewareResponse = (new BlockPosWhenDayEndRunning)->handle(
                    $request,
                    fn () => response()->json(['unexpected' => true])
                );
                $this->assertSame(403, $middlewareResponse->getStatusCode());
                $this->assertSame(
                    'TILL_BRANCH_MISMATCH',
                    json_decode($middlewareResponse->getContent(), true)['code']
                );

                $resolver = new ReflectionMethod(TradingDayEndController::class, 'resolveBranchId');
                $resolver->setAccessible(true);

                try {
                    $resolver->invoke(new TradingDayEndController, $request);
                    $this->fail("Expected {$source} branch spoofing to be rejected.");
                } catch (\ReflectionException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $response = method_exists($exception, 'getResponse') ? $exception->getResponse() : null;
                    $this->assertNotNull($response, $exception->getMessage());
                    $this->assertSame(403, $response->getStatusCode());
                    $this->assertSame(
                        'TILL_BRANCH_MISMATCH',
                        json_decode($response->getContent(), true)['code']
                    );
                }
            }
        } finally {
            app()->forgetInstance('till');
            app()->forgetInstance('company');
        }
    }

    public function test_day_end_rejects_a_missing_explicit_branch_context(): void
    {
        app()->instance('company', (object) ['id' => 'company-a']);

        try {
            $request = Request::create('/api/pos/trading-day/current', 'GET');
            $resolver = new ReflectionMethod(TradingDayEndController::class, 'resolveBranchId');
            $resolver->setAccessible(true);

            try {
                $resolver->invoke(new TradingDayEndController, $request);
                $this->fail('Expected a context-free Day End request to be rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $response = method_exists($exception, 'getResponse') ? $exception->getResponse() : null;
                $this->assertNotNull($response, $exception->getMessage());
                $this->assertSame(422, $response->getStatusCode());
                $this->assertSame(
                    'BRANCH_CONTEXT_REQUIRED',
                    json_decode($response->getContent(), true)['code']
                );
            }
        } finally {
            app()->forgetInstance('company');
            app()->forgetInstance('till');
        }
    }

    public function test_hospitality_configuration_fiscalisation_and_reports_are_till_free(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $routes = [
            ['GET', '/api/pos/fast-food/prep-messages'],
            ['POST', '/api/pos/fast-food/prep-messages'],
            ['GET', "/api/pos/fast-food/prep-messages/{$id}"],
            ['PUT', "/api/pos/fast-food/prep-messages/{$id}"],
            ['DELETE', "/api/pos/fast-food/prep-messages/{$id}"],
            ['POST', "/api/pos/fast-food/prep-messages/{$id}/assign-products"],
            ['DELETE', "/api/pos/fast-food/prep-messages/{$id}/products/{$id}"],
            ['GET', '/api/pos/fast-food/modifiers'],
            ['POST', '/api/pos/fast-food/modifiers'],
            ['GET', "/api/pos/fast-food/modifiers/{$id}"],
            ['PUT', "/api/pos/fast-food/modifiers/{$id}"],
            ['DELETE', "/api/pos/fast-food/modifiers/{$id}"],
            ['POST', "/api/pos/fast-food/modifiers/{$id}/assign-products"],
            ['DELETE', "/api/pos/fast-food/modifiers/{$id}/products/{$id}"],
            ['GET', "/api/pos/fast-food/products/{$id}/configuration-options"],
            ['GET', '/api/pos/quotations'],
            ['POST', '/api/pos/quotations'],
            ['GET', "/api/pos/quotations/{$id}"],
            ['PUT', "/api/pos/quotations/{$id}"],
            ['POST', "/api/pos/quotations/{$id}/issue"],
            ['POST', "/api/pos/quotations/{$id}/cancel"],
            ['GET', '/api/pos/zimra/settings'],
            ['PUT', '/api/pos/zimra/settings'],
            ['GET', '/api/pos/zimra/devices'],
            ['POST', '/api/pos/zimra/devices'],
            ['POST', "/api/pos/zimra/devices/{$id}/sync-config"],
            ['GET', "/api/pos/zimra/devices/{$id}/status"],
            ['GET', '/api/pos/zimra/fiscal-days'],
            ['POST', '/api/pos/zimra/fiscal-days/open'],
            ['POST', "/api/pos/zimra/fiscal-days/{$id}/close"],
            ['POST', "/api/pos/zimra/fiscal-days/{$id}/poll"],
            ['GET', '/api/pos/zimra/receipts'],
            ['GET', "/api/pos/zimra/receipts/{$id}"],
            ['POST', "/api/pos/zimra/sales/{$id}/retry"],
            ['GET', '/api/pos/reports/food-industry-sales/turnover'],
            ['GET', '/api/pos/reports/food-industry-sales/daily'],
            ['GET', '/api/pos/reports/food-industry-sales/category-sales'],
            ['GET', '/api/pos/reports/food-industry-sales/menu-profitability'],
            ['GET', '/api/pos/reports/food-industry-sales/excel'],
            ['GET', '/api/pos/reports/food-industry-sales/pdf'],
            ['GET', '/api/pos/reports/food-industry-sales/currencies'],
            ['GET', '/api/pos/reports/food-industry-sales/daily/excel'],
            ['GET', '/api/pos/reports/food-industry-sales/daily/pdf'],
            ['GET', '/api/pos/reports/food-industry-sales/business-dates'],
            ['GET', '/api/pos/receipts/find'],
            ['POST', '/api/pos/receipts/reprint'],
            ['POST', '/api/pos/receipts/cancel'],
        ];

        foreach ($routes as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "Back Office route {$method} {$uri} must require authentication.");
            $this->assertNotContains(
                ResolveTillFromToken::class,
                $middleware,
                "Back Office route {$method} {$uri} must not require a registered till."
            );
        }

        $posOptions = app('router')->getRoutes()->match(
            Request::create("/api/pos/fast-food/products/{$id}/options", 'GET')
        );

        $this->assertContains(ResolveTillFromToken::class, $posOptions->gatherMiddleware());

        $configurationOptions = app('router')->getRoutes()->match(
            Request::create("/api/pos/fast-food/products/{$id}/configuration-options", 'GET')
        );
        $this->assertNotContains(ResolveTillFromToken::class, $configurationOptions->gatherMiddleware());

        foreach ([
            ['GET', '/api/pos/quotations/code/QUOTE-001'],
            ['POST', '/api/pos/quotations/code/QUOTE-001/convert'],
            ['POST', '/api/pos/sales'],
            ['GET', '/api/pos/shift-compliance'],
            ['GET', '/api/pos/cashup/active-shift-summary'],
            ['POST', '/api/pos/cashup'],
            ['POST', '/api/pos/cashup/end-shift'],
            ['POST', '/api/pos/cash-drawer-movements'],
        ] as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertContains(ResolveTillFromToken::class, $route->gatherMiddleware());
        }
    }
}
