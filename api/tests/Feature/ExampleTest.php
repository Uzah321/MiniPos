<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\ResolveTillFromToken;
use Illuminate\Http\Request;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_reports_require_authentication(): void
    {
        $response = $this->getJson('/api/pos/reports/profit-loss');

        $response->assertUnauthorized();
    }

    public function test_workspace_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/pos/workspace/bootstrap')->assertUnauthorized();
    }

    public function test_back_office_receipt_registers_do_not_require_a_browser_till(): void
    {
        foreach ([
            '/api/pos/receipts/register',
            '/api/pos/receipts/register/example-sale',
            '/api/pos/receipts/register/example-sale/pdf',
            '/api/pos/receipts/register/example-sale/pdf-file',
            '/api/pos/receipts/cancelled',
        ] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));

            $this->assertNotContains(
                ResolveTillFromToken::class,
                $route->gatherMiddleware(),
                "Back Office route {$uri} must not require a registered till."
            );
        }
    }

    public function test_discount_administration_is_till_free_but_pos_calculation_is_not(): void
    {
        foreach (['GET' => '/api/pos/discounts', 'POST' => '/api/pos/discounts'] as $method => $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertNotContains(ResolveTillFromToken::class, $route->gatherMiddleware());
        }

        $calculationRoute = app('router')->getRoutes()->match(
            Request::create('/api/pos/discounts/calculate', 'POST')
        );

        $this->assertContains(ResolveTillFromToken::class, $calculationRoute->gatherMiddleware());
    }
}
