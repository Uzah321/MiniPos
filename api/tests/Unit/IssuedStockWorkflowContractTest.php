<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\StockIssuedToFrontReportController;
use Tests\TestCase;

class IssuedStockWorkflowContractTest extends TestCase
{
    public function test_enterprise_issue_uses_authenticated_requester_instead_of_client_input(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProductController::class))->getFileName());
        $methodStart = strrpos($source, 'public function issueBackStockEnterprise');
        $methodEnd = strpos($source, 'private function resolveStockUsageType', $methodStart);
        $method = substr($source, $methodStart, $methodEnd - $methodStart);

        $this->assertStringContainsString('$requestingUser = $request->user() ?? auth()->user();', $method);
        $this->assertStringContainsString("'requested_by' => \$requesterName", $method);
        $this->assertStringContainsString("'requested_by_id' => \$requestingUser?->id", $method);
        $this->assertStringNotContainsString("'requested_by' => 'nullable|string|max:120'", $method);
        $this->assertStringNotContainsString("'requested_by' => \$request->requested_by", $method);
    }

    public function test_issued_stock_register_detail_and_pdf_contract_is_routed(): void
    {
        $routes = file_get_contents(base_path('Modules/Product/routes/api.php'));

        $this->assertStringContainsString("Route::get('/issued-stock', [StockIssuedToFrontReportController::class, 'index'])", $routes);
        $this->assertStringContainsString("Route::get('/issued-stock/{issueId}', [StockIssuedToFrontReportController::class, 'show'])", $routes);
        $this->assertStringContainsString("Route::get('/issued-stock/{issueId}/pdf', [StockIssuedToFrontReportController::class, 'downloadIssuePdf'])", $routes);
        $this->assertStringContainsString("company.permission:boh_issue_stock,view_stock_reports,inventory_management", $routes);
    }

    public function test_register_exposes_human_issue_number_grouped_documents_and_legacy_day_totals(): void
    {
        $source = file_get_contents((new \ReflectionClass(StockIssuedToFrontReportController::class))->getFileName());

        $this->assertStringContainsString("'issue_number' =>", $source);
        $this->assertStringContainsString("'requested_by_id' =>", $source);
        $this->assertStringContainsString("'issues' => \$issues->values()->all()", $source);
        $this->assertStringContainsString("'product_summary' => \$productSummary", $source);
        $this->assertStringContainsString("'currency_totals' => \$currencyTotals", $source);
        $this->assertStringContainsString("->whereIn('sm.reference_type', ['enterprise_stock_issue', 'boh_issue_to_front'])", $source);
    }

    public function test_product_cost_currency_expression_supports_each_schema_generation(): void
    {
        $controller = app(StockIssuedToFrontReportController::class);
        $method = new \ReflectionMethod($controller, 'productCostCurrencyAggregateExpression');

        $this->assertSame(
            "COALESCE(MAX(currency_code), MAX(currency), 'USD')",
            $method->invoke($controller, true, true)
        );
        $this->assertSame(
            "COALESCE(MAX(currency_code), 'USD')",
            $method->invoke($controller, true, false),
            'Fresh product_costs schemas have currency_code but no legacy currency column.'
        );
        $this->assertSame(
            "COALESCE(MAX(currency), 'USD')",
            $method->invoke($controller, false, true)
        );
        $this->assertSame("'USD'", $method->invoke($controller, false, false));
    }

    public function test_issued_stock_scope_includes_only_constrained_legacy_front_issues(): void
    {
        $controller = app(StockIssuedToFrontReportController::class);
        $method = new \ReflectionMethod($controller, 'applyIssuedStockReferenceScope');

        $registerQuery = DB::table('stock_movements as sm');
        $method->invoke($controller, $registerQuery);

        $this->assertStringContainsString('reference_type', $registerQuery->toSql());
        $this->assertStringContainsString('reference_id', $registerQuery->toSql());
        $this->assertStringContainsString('movement_type', $registerQuery->toSql());
        $this->assertSame([
            'enterprise_stock_issue',
            'boh_issue_to_front',
            'boh_issue_to_front',
            '',
            '',
        ], $registerQuery->getBindings());

        $legacyMovementId = '00000000-0000-0000-0000-000000000123';
        $detailQuery = DB::table('stock_movements as sm');
        $method->invoke($controller, $detailQuery, $legacyMovementId);

        $this->assertSame([
            'enterprise_stock_issue',
            'boh_issue_to_front',
            $legacyMovementId,
            'boh_issue_to_front',
            '',
            '',
            $legacyMovementId,
        ], $detailQuery->getBindings());
    }
}
