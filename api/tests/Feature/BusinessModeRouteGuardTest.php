<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class BusinessModeRouteGuardTest extends TestCase
{
    public function test_hospitality_only_routes_have_the_hospitality_mode_guard(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $routes = [
            ['GET', '/api/pos/food-manufacturing/recipes'],
            ['POST', '/api/pos/food-manufacturing/batches'],
            ['GET', '/api/pos/stock-takes/month-end/back-stock/snapshot'],
            ['GET', '/api/pos/stock-takes/month-end/back-stock/download-balance-snapshot'],
            ['GET', '/api/pos/stock-takes/month-end/back-stock/download-trading-count-sheet'],
            ['POST', '/api/pos/stock-takes/month-end/back-stock/begin-trading'],
            ['POST', '/api/pos/stock-takes/month-end/back-stock/upload-trading-count-sheets'],
            ['GET', "/api/pos/stock-takes/month-end/back-stock/{$id}"],
            ['POST', '/api/pos/month-end/hospitality/run'],
            ['GET', '/api/pos/stock/front'],
            ['GET', '/api/pos/stock/back'],
            ['GET', '/api/pos/stock/front/download/pdf'],
            ['GET', '/api/pos/stock/back/download/xlsx'],
            ['POST', '/api/pos/stock/issue-enterprise'],
            ['GET', '/api/pos/front-stock/daily-closings'],
            ['GET', '/api/pos/front-stock/daily-closings/snapshot'],
            ['GET', "/api/pos/front-stock/daily-closings/{$id}"],
            ['POST', '/api/pos/front-stock/daily-closings'],
            ['POST', '/api/pos/trading-day/day-end'],
            ['GET', '/api/pos/trading-day/day-end/progress/day-end-run-1'],
            ['GET', '/api/pos/front/trading-day/day-end/preflight'],
            ['GET', '/api/pos/front/trading-day/day-end/progress/day-end-run-1'],
            ['POST', '/api/pos/front/trading-day/day-end'],
            ['POST', '/api/pos/front/trading-day/day-end/catch-up'],
            ['POST', '/api/pos/front/trading-day/day-end/rollback'],
            ['POST', '/api/pos/front/trading-day/day-end/force-front-stock-closing'],
            ['GET', '/api/pos/reports/menu-recipe-daily-stock-reconciliation/live'],
            ['POST', '/api/pos/stock-wastages'],
            ['GET', '/api/pos/fast-food/prep-messages'],
            ['POST', '/api/pos/fast-food/prep-messages'],
            ['PUT', "/api/pos/fast-food/prep-messages/{$id}"],
            ['DELETE', "/api/pos/fast-food/prep-messages/{$id}"],
            ['POST', "/api/pos/fast-food/prep-messages/{$id}/assign-products"],
            ['DELETE', "/api/pos/fast-food/prep-messages/{$id}/products/{$id}"],
            ['GET', '/api/pos/fast-food/modifiers'],
            ['POST', '/api/pos/fast-food/modifiers'],
            ['PUT', "/api/pos/fast-food/modifiers/{$id}"],
            ['DELETE', "/api/pos/fast-food/modifiers/{$id}"],
            ['POST', "/api/pos/fast-food/modifiers/{$id}/assign-products"],
            ['DELETE', "/api/pos/fast-food/modifiers/{$id}/products/{$id}"],
            ['GET', '/api/pos/reports/food-industry-sales/turnover'],
            ['GET', "/api/pos/fast-food/products/{$id}/options"],
            ['GET', "/api/pos/fast-food/products/{$id}/configuration-options"],
        ];

        foreach ($routes as [$method, $uri]) {
            $middleware = $this->middlewareFor($method, $uri);

            $this->assertContains(
                'business.mode:hospitality',
                $middleware,
                "Hospitality route {$method} {$uri} must reject retail branches."
            );
        }
    }

    public function test_legacy_retail_only_routes_are_not_registered(): void
    {
        foreach ([
            ['GET', '/api/v1/manufacturings'],
            ['GET', '/api/pos/manufacturing/recipes'],
            ['POST', '/api/pos/manufacturing/plans'],
            ['POST', '/api/pos/manufacturing/orders'],
            ['GET', '/api/pos/manufacturing/reports/production-summary'],
            ['GET', '/api/pos/stock-takes'],
            ['GET', '/api/pos/stock-takes/download-count-sheet'],
            ['POST', '/api/pos/stock-takes/begin-trading'],
        ] as [$method, $uri]) {
            try {
                app('router')->getRoutes()->match(Request::create($uri, $method));
                $this->fail("Legacy Retail route {$method} {$uri} must not be registered.");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_shared_product_and_inventory_routes_are_not_mode_locked(): void
    {
        foreach ([
            ['GET', '/api/pos/management/inventory/dashboard'],
            ['GET', '/api/pos/products'],
            ['GET', '/api/pos/product-categories'],
            ['GET', '/api/pos/product-sub-categories'],
            ['GET', '/api/pos/suppliers'],
            ['GET', '/api/pos/stock-transfers'],
            ['GET', '/api/pos/purchases/overview'],
        ] as [$method, $uri]) {
            $middleware = $this->middlewareFor($method, $uri);

            $this->assertFalse(
                collect($middleware)->contains(
                    fn ($entry) => str_starts_with((string) $entry, 'business.mode:')
                ),
                "Shared route {$method} {$uri} must remain available in both modes."
            );
        }
    }

    private function middlewareFor(string $method, string $uri): array
    {
        return app('router')->getRoutes()
            ->match(Request::create($uri, $method))
            ->gatherMiddleware();
    }
}
