<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCompanyLicense;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use Illuminate\Http\Request;
use Modules\Product\Http\Controllers\InventoryReportDownloadController;
use Tests\TestCase;

class HistoryReportExportRoutesTest extends TestCase
{
    public function test_history_exports_are_authenticated_company_scoped_back_office_routes(): void
    {
        $productId = '00000000-0000-0000-0000-000000000001';
        $routes = [
            [
                "/api/pos/products/{$productId}/price-adjustments/export/pdf",
                'productPriceAdjustmentHistory',
            ],
            [
                '/api/pos/products/' . $productId . '/price-adjustments/export/xlsx',
                'productPriceAdjustmentHistory',
            ],
            ['/api/pos/stock-adjustment-history/export/pdf', 'stockAdjustmentHistory'],
            ['/api/pos/stock-adjustment-history/export/xlsx', 'stockAdjustmentHistory'],
        ];

        foreach ($routes as [$uri, $method]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
            $middleware = $route->gatherMiddleware();

            $this->assertSame(
                InventoryReportDownloadController::class . '@' . $method,
                $route->getActionName()
            );
            $this->assertContains('auth:sanctum', $middleware, "{$uri} must require authentication.");
            $this->assertContains(ResolveCompanyFromDomain::class, $middleware, "{$uri} must resolve its company.");
            $this->assertContains(CheckCompanyLicense::class, $middleware, "{$uri} must enforce the company licence.");
            $this->assertNotContains(
                ResolveTillFromToken::class,
                $middleware,
                "Back Office export {$uri} must not require a registered till."
            );
            $this->assertSame('pdf|xlsx', $route->wheres['format'] ?? null);
        }
    }
}
