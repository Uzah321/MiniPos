<?php

namespace Tests\Unit;

use Modules\Product\Http\Controllers\InventoryReportDownloadController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\StockReportController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class StockReportAverageCostContractTest extends TestCase
{
    public function test_stock_report_queries_are_average_cost_only_and_company_scoped(): void
    {
        $source = file_get_contents((new ReflectionClass(StockReportController::class))->getFileName());

        $this->assertDoesNotMatchRegularExpression(
            '/selling_(?:price|value)|potential_margin|margin_value|product_prices/i',
            $source
        );
        $this->assertStringNotContainsString('SUM(total_cost) as stock_value', $source);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count(
                $source,
                "SUM((stock_movements.quantity_in - stock_movements.quantity_out) * ' . \$this->costColumn('average_cost') . ') as stock_value"
            )
        );

        $joinSource = $this->methodSource(StockReportController::class, 'joinProductCosts');
        $this->assertStringContainsString("costs.company_id', '=', \$stockAlias . '.company_id", $joinSource);
        $this->assertStringContainsString("costs.branch_id', '=', \$stockAlias . '.branch_id", $joinSource);

        $costSource = $this->methodSource(StockReportController::class, 'costColumn');
        $preferredCheck = strpos($costSource, "Schema::hasColumn('product_costs', \$preferred)");
        $legacyCheck = strpos($costSource, "foreach (['average_cost', 'last_cost', 'unit_cost'] as \$legacyColumn)");
        $this->assertNotFalse($preferredCheck);
        $this->assertNotFalse($legacyCheck);
        $this->assertLessThan($legacyCheck, $preferredCheck, 'The requested average_cost column must win before legacy schema fallbacks.');
        $this->assertStringContainsString("return 'COALESCE(costs.' . \$preferred . ', 0)'", $costSource);

        $adjustmentsSource = $this->methodSource(StockReportController::class, 'adjustments');
        $adjustmentTotalsSource = $this->methodSource(StockReportController::class, 'adjustmentTotals');
        $this->assertStringContainsString("costColumn('average_cost')", $adjustmentsSource);
        $this->assertStringContainsString('quantity_in * average_cost', $adjustmentTotalsSource);
        $this->assertStringContainsString('quantity_out * average_cost', $adjustmentTotalsSource);
        $this->assertStringNotContainsString('total_cost', $adjustmentTotalsSource);
    }

    public function test_every_active_stock_export_has_aligned_cost_only_columns(): void
    {
        $controller = new InventoryReportDownloadController;
        $method = new ReflectionMethod($controller, 'stockReportDefinitions');
        $method->setAccessible(true);
        $definitions = $method->invoke($controller);

        $this->assertSame([
            'company-balance',
            'branch-balance',
            'current-balance',
            'as-of',
            'movements',
            'received',
            'adjustments',
            'valuation',
            'low-stock',
            'out-of-stock',
            'negative-stock',
            'category-summary',
            'supplier-summary',
        ], array_keys($definitions));

        $sample = $this->sampleRow();
        foreach ($definitions as $key => $definition) {
            $labels = implode(' ', $definition['headers']);
            $this->assertDoesNotMatchRegularExpression('/selling|margin/i', $labels, "{$key} contains a retail valuation column.");
            $this->assertCount(
                count($definition['headers']),
                ($definition['map'])($sample),
                "{$key} headers and row mapper are misaligned."
            );
        }

        foreach ([
            'company-balance',
            'branch-balance',
            'current-balance',
            'as-of',
            'valuation',
            'category-summary',
            'supplier-summary',
        ] as $key) {
            $this->assertContains('Stock Value at Average Cost', $definitions[$key]['headers']);
        }

        $this->assertContains('Average Cost', $definitions['adjustments']['headers']);
        $this->assertContains('Average Cost Value', $definitions['adjustments']['headers']);
        $this->assertContains('Recorded Cost Value', $definitions['received']['headers']);
        $this->assertNotContains('Last Cost', $definitions['valuation']['headers']);

        $dashboardSource = $this->methodSource(InventoryReportDownloadController::class, 'downloadStockDashboard');
        $this->assertStringContainsString('Stock Value at Average Cost', $dashboardSource);
        $this->assertDoesNotMatchRegularExpression('/selling|margin/i', $dashboardSource);
    }

    public function test_mixed_currency_summaries_never_publish_an_ambiguous_scalar_value(): void
    {
        $method = new ReflectionMethod(StockReportController::class, 'singleCurrencyScalars');
        $method->setAccessible(true);
        $controller = new StockReportController;

        $single = $method->invoke($controller, [[
            'currency_code' => 'USD',
            'stock_value' => 125.5,
        ]], ['stock_value']);
        $this->assertSame('USD', $single['currency_code']);
        $this->assertSame(125.5, $single['stock_value']);

        $mixed = $method->invoke($controller, [
            ['currency_code' => 'USD', 'stock_value' => 125.5],
            ['currency_code' => 'ZWG', 'stock_value' => 900],
        ], ['stock_value']);
        $this->assertNull($mixed['currency_code']);
        $this->assertNull($mixed['stock_value']);

        $unknown = $method->invoke($controller, [[
            'currency_code' => null,
            'stock_value' => 125.5,
        ]], ['stock_value']);
        $this->assertNull($unknown['currency_code']);
        $this->assertNull($unknown['stock_value']);

        $summaryRows = new ReflectionMethod(
            InventoryReportDownloadController::class,
            'summaryRows'
        );
        $summaryRows->setAccessible(true);
        $downloadRows = $summaryRows->invoke(
            new InventoryReportDownloadController,
            [
                'cost_value' => null,
                'currency_code' => null,
                'currency_totals' => [
                    ['currency_code' => 'USD', 'cost_value' => 12.5],
                    ['currency_code' => 'ZWG', 'cost_value' => 400],
                ],
            ],
            'adjustments'
        );

        $this->assertContains(['USD · Average Cost Value', '12.5000'], $downloadRows);
        $this->assertContains(['ZWG · Average Cost Value', '400.0000'], $downloadRows);
    }

    public function test_weighted_average_currency_buckets_and_summary_filters_are_wired_in_source(): void
    {
        foreach (['companyBalance', 'branchBalance'] as $methodName) {
            $source = $this->methodSource(StockReportController::class, $methodName);
            $this->assertStringContainsString(
                'SUM(product_stocks.quantity_on_hand * ',
                $source
            );
            $this->assertStringContainsString('/ SUM(product_stocks.quantity_on_hand)', $source);
            $this->assertStringNotContainsString('AVG(', $source);
        }

        foreach ([
            'currentStockTotals',
            'movementStockTotals',
            'stockReceivedTotals',
            'adjustmentTotals',
        ] as $methodName) {
            $source = $this->methodSource(StockReportController::class, $methodName);
            $this->assertStringContainsString("->groupBy('currency_code')", $source);
            $this->assertStringContainsString('currencyTotals', $source);
        }

        $movementBase = $this->methodSource(StockReportController::class, 'movementBalanceBase');
        $this->assertStringContainsString("filters['movement_type']", $movementBase);
        $this->assertStringContainsString("filters['q']", $movementBase);
        $this->assertStringContainsString("stock_movements.notes', 'like'", $movementBase);
    }

    public function test_adjustment_history_sources_and_exports_use_branch_scoped_average_cost(): void
    {
        $source = $this->methodSource(ProductController::class, 'stockAdjustmentHistory');

        $this->assertStringContainsString("leftJoin('product_costs as history_costs'", $source);
        $this->assertStringContainsString("history_costs.company_id', '=', 'stock_movements.company_id", $source);
        $this->assertStringContainsString("history_costs.branch_id', '=', 'stock_movements.branch_id", $source);
        $this->assertStringContainsString('history_costs.average_cost', $source);
        $this->assertStringContainsString('ABS(', $source);
        $this->assertStringContainsString('as cost_value', $source);
        $this->assertStringContainsString('as cost_currency_code', $source);
        $this->assertStringContainsString("'unit_cost'", $source);
        $this->assertStringContainsString("'total_cost'", $source);

        $export = $this->methodSource(
            InventoryReportDownloadController::class,
            'stockAdjustmentHistory'
        );
        $this->assertStringContainsString("Arr::get(\$record, 'average_cost')", $export);
        $this->assertStringContainsString("Arr::get(\$record, 'cost_value')", $export);
        $this->assertStringContainsString("Arr::get(\$record, 'cost_currency_code')", $export);
        $this->assertStringNotContainsString("Arr::get(\$record, 'unit_cost')", $export);
        $this->assertStringNotContainsString("Arr::get(\$record, 'total_cost')", $export);
    }

    private function methodSource(string $class, string $methodName): string
    {
        $method = new ReflectionMethod($class, $methodName);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    private function sampleRow(): array
    {
        return [
            'as_of_date' => '2026-10-02',
            'branch_name' => 'Main',
            'product_name' => 'Product',
            'sku' => 'SKU-1',
            'code' => 'P-1',
            'category_name' => 'Category',
            'supplier_name' => 'Supplier',
            'quantity_on_hand' => 2,
            'quantity_as_of' => 2,
            'reserved_quantity' => 0,
            'available_quantity' => 2,
            'total_in' => 3,
            'total_out' => 1,
            'average_cost' => 10,
            'stock_value' => 20,
            'cost_currency_code' => 'USD',
            'currency_code' => 'USD',
            'movement_date' => '2026-10-02 08:00:00',
            'last_movement_at' => '2026-10-02 08:00:00',
            'warehouse_name' => 'Main Warehouse',
            'movement_type' => 'adjustment_in',
            'quantity_in' => 2,
            'quantity_out' => 0,
            'balance_after' => 2,
            'unit_cost' => 10,
            'total_cost' => 20,
            'cost_value' => 20,
            'increased_quantity' => 2,
            'decreased_quantity' => 0,
            'reference_type' => 'test',
            'created_by_name' => 'Tester',
            'notes' => 'Test',
            'reorder_level' => 1,
            'product_count' => 1,
        ];
    }
}
