<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\HospitalityCatalogImportController;
use Tests\TestCase;

final class HospitalityCatalogImportRouteContractTest extends TestCase
{
    public function test_scope_preview_and_commit_routes_use_the_guarded_back_office_contract(): void
    {
        foreach (['scope' => 'GET', 'preview' => 'POST', 'commit' => 'POST'] as $action => $method) {
            $route = Route::getRoutes()->match(
                Request::create("/api/pos/hospitality-imports/{$action}", $method)
            );
            $this->assertSame(
                HospitalityCatalogImportController::class.'@'.$action,
                $route->getActionName()
            );
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('workspace:back-office', $middleware);
            $this->assertContains('company.permission:import_products', $middleware);
        }
    }
}
