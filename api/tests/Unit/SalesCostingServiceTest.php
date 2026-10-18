<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use App\Services\Sales\SalesCostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mockery;
use Modules\Sales\Http\Controllers\FoodIndustrySalesReportController;
use Modules\Sales\Http\Controllers\SalesReportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SalesCostingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_hospitality_mode_uses_recipe_costing(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('isHospitalityMode')->with('company-1', 'branch-1')->andReturnTrue();
        $settings->shouldReceive('isBohEnabled')->with('company-1', 'branch-1')->andReturnTrue();
        $settings->shouldReceive('isFastFoodMode')->with('company-1', 'branch-1')->andReturnTrue();
        $settings->shouldReceive('enabled')->twice()->andReturnTrue();

        $mode = (new SalesCostingService($settings))->mode('company-1', 'branch-1');

        $this->assertSame('hospitality', $mode['business_mode']);
        $this->assertSame('recipe_ingredient_consumption', $mode['cost_source']);
    }

    public function test_retail_line_cost_prefers_immutable_sale_movement_with_cost_fallback(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $service = new SalesCostingService($settings);

        $expression = $service->lineCostExpression(false);

        $this->assertStringContainsString('retail_line_costs.movement_cost', $expression);
        $this->assertStringContainsString('product_costs.average_cost', $expression);
        $this->assertStringContainsString('product_costs.last_cost', $expression);
        $this->assertStringNotContainsString('recipe_line_costs', $expression);
        $this->assertStringContainsString(
            'sales_transactions.base_currency_code',
            $service->costCurrencyExpression(false)
        );
        $this->assertStringContainsString('retail_line_costs.cost_currency_code', $service->costCurrencyExpression(false));
    }

    public function test_product_cost_currency_expression_supports_every_deployed_schema_shape(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $service = new SalesCostingService($settings);

        $this->assertSame(
            'product_costs.currency',
            $service->productCostCurrencyExpression('product_costs', ['currency'])
        );
        $this->assertSame(
            'product_costs.currency_code',
            $service->productCostCurrencyExpression('product_costs', ['currency_code'])
        );
        $this->assertSame(
            'COALESCE(pc.currency_code, pc.currency)',
            $service->productCostCurrencyExpression('pc', ['currency', 'currency_code'])
        );
        $this->assertNull($service->productCostCurrencyExpression('product_costs', []));
    }

    public function test_all_branches_report_keeps_selected_workspace_branch_for_costing_mode_only(): void
    {
        $request = Request::create('/reports/sales', 'GET');
        $request->headers->set('X-Branch-ID', 'hospitality-branch');
        $controller = new SalesReportController;

        $filtersMethod = new ReflectionMethod($controller, 'filters');
        $filtersMethod->setAccessible(true);
        $filters = $filtersMethod->invoke($controller, $request, false);

        $this->assertNull($filters['branch_id']);
        $this->assertSame([], $filters['branch_ids']);
        $this->assertSame('hospitality-branch', $filters['costing_context_branch_id']);

        $contextMethod = new ReflectionMethod($controller, 'costingContextBranchId');
        $contextMethod->setAccessible(true);
        $this->assertSame('hospitality-branch', $contextMethod->invoke($controller, $filters));
    }

    public function test_explicit_report_branch_controls_both_scope_and_costing_context(): void
    {
        $request = Request::create('/reports/sales', 'GET', ['branch_id' => 'report-branch']);
        $request->headers->set('X-Branch-ID', 'header-branch');
        $controller = new SalesReportController;

        $filtersMethod = new ReflectionMethod($controller, 'filters');
        $filtersMethod->setAccessible(true);
        $filters = $filtersMethod->invoke($controller, $request, false);

        $this->assertSame('report-branch', $filters['branch_id']);
        $this->assertSame(['report-branch'], $filters['branch_ids']);
        $this->assertSame('header-branch', $filters['costing_context_branch_id']);
    }

    public function test_monthly_profit_comparison_uses_previous_full_month_and_current_month_to_date(): void
    {
        $controller = new SalesReportController;
        $method = new ReflectionMethod($controller, 'monthlyProfitComparisonPeriods');
        $method->setAccessible(true);

        $periods = $method->invoke($controller, Carbon::parse('2026-10-02 12:00:00'));

        $this->assertSame([
            [
                'key' => 'previous_month',
                'label' => 'September 2026',
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ],
            [
                'key' => 'current_month',
                'label' => 'October 2026 MTD',
                'date_from' => '2026-10-01',
                'date_to' => '2026-10-02',
            ],
        ], $periods);

        $completedMonth = $method->invoke($controller, Carbon::parse('2026-09-30 12:00:00'));
        $this->assertSame('September 2026', $completedMonth[1]['label']);
        $this->assertSame('2026-09-30', $completedMonth[1]['date_to']);
    }

    public function test_hospitality_line_cost_prefers_recorded_consumption_then_recipe_snapshot(): void
    {
        $settings = Mockery::mock(CompanySettingService::class);
        $service = new SalesCostingService($settings);

        $expression = $service->lineCostExpression(true);

        $this->assertStringContainsString('recipe_line_costs.recipe_cost', $expression);
        $this->assertStringContainsString('active_recipe_costs.recipe_unit_cost', $expression);
        $this->assertStringNotContainsString('product_costs.average_cost', $expression);
    }

    public function test_recipe_cost_query_includes_tagged_and_legacy_batch_ingredient_movements(): void
    {
        $method = new ReflectionMethod(SalesCostingService::class, 'recipeMovementCostsByReceiptLine');
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString("'sale_recipe', 'sale_modifier_recipe'", $source);
        $this->assertStringContainsString("reference_type', 'sale'", $source);
        $this->assertStringContainsString("whereNotNull('stock_movements.batch_id')", $source);
        $this->assertStringContainsString('costing_receipt_lines.product_id', $source);
    }

    public function test_legacy_recipe_fallback_is_joined_to_the_sale_branch_in_every_report(): void
    {
        $salesReport = file_get_contents((new \ReflectionClass(SalesReportController::class))->getFileName());
        $foodIndustryReport = file_get_contents((new \ReflectionClass(FoodIndustrySalesReportController::class))->getFileName());

        $this->assertGreaterThanOrEqual(
            5,
            substr_count($salesReport, "active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id"),
            'Every general sales report recipe fallback must join the sale branch.'
        );
        $this->assertStringContainsString(
            "recipe_estimates.branch_id', '=', 'st.branch_id",
            $foodIndustryReport
        );
        $this->assertStringContainsString(
            "activeRecipeUnitCosts(\$companyId, \$filters['branch_id'])",
            $foodIndustryReport
        );
    }

    public function test_report_cost_conversion_uses_the_sale_historical_rate_in_the_correct_direction(): void
    {
        $controller = new SalesReportController;

        $saleCurrencyMethod = new ReflectionMethod($controller, 'costInSaleCurrencyExpression');
        $saleCurrencyMethod->setAccessible(true);
        $saleCurrencyExpression = $saleCurrencyMethod->invoke(
            $controller,
            'recorded_cost',
            'recorded_currency',
            'sale'
        );

        $baseCurrencyMethod = new ReflectionMethod($controller, 'costInBaseCurrencyExpression');
        $baseCurrencyMethod->setAccessible(true);
        $baseCurrencyExpression = $baseCurrencyMethod->invoke(
            $controller,
            'recorded_cost',
            'recorded_currency',
            'sale'
        );

        $this->assertStringContainsString('recorded_cost) * COALESCE(NULLIF(sale.exchange_rate, 0), 1)', $saleCurrencyExpression);
        $this->assertStringContainsString('recorded_cost) / COALESCE(NULLIF(sale.exchange_rate, 0), 1)', $baseCurrencyExpression);
    }

    public function test_food_industry_summary_exposes_cost_of_sales_without_removing_ingredient_cost(): void
    {
        $controller = new FoodIndustrySalesReportController;
        $summaryMethod = new ReflectionMethod($controller, 'summary');
        $summaryMethod->setAccessible(true);

        $summary = $summaryMethod->invoke(
            $controller,
            collect([(object) [
                'quantity_sold' => 2,
                'net_sales' => 30,
                'ingredient_cost' => 12,
                'base_net_sales' => 30,
                'base_ingredient_cost' => 12,
                'currency_code' => 'USD',
                'base_currency_code' => 'USD',
            ]]),
            collect([(object) ['id' => 'sale-a']]),
            ['currency_codes' => ['USD']]
        );

        $this->assertSame(12.0, $summary['ingredient_cost']);
        $this->assertSame(12.0, $summary['cost_of_sales']);
        $this->assertSame(18.0, $summary['gross_profit']);
    }

    public function test_food_industry_report_sections_apply_operational_filters_consistently(): void
    {
        $class = new \ReflectionClass(FoodIndustrySalesReportController::class);
        $sourceLines = file($class->getFileName());

        foreach ([
            'ingredientConsumptionRows',
            'categorySalesRows',
            'hourlySalesRows',
            'paymentRows',
            'cashierRows',
            'receiptRows',
        ] as $methodName) {
            $method = $class->getMethod($methodName);
            $source = implode('', array_slice(
                $sourceLines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $this->assertStringContainsString('applyBranchScope', $source, "{$methodName} must apply the resolved mode branch scope.");

            foreach (['till_id', 'cashier_id', 'shift_session_id', 'currency_codes'] as $filter) {
                $this->assertStringContainsString("filters['{$filter}']", $source, "{$methodName} must apply {$filter}.");
            }

            if ($methodName === 'ingredientConsumptionRows') {
                $this->assertStringContainsString("'sale_recipe', 'sale_modifier_recipe'", $source);
                $this->assertStringContainsString("reference_type', 'sale'", $source);
                $this->assertStringContainsString("whereNotNull('sm.batch_id')", $source);
                $this->assertStringContainsString('movement_receipt_line.product_id', $source);
            }
        }

        $currencies = $class->getMethod('currencies');
        $currencySource = implode('', array_slice(
            $sourceLines,
            $currencies->getStartLine() - 1,
            $currencies->getEndLine() - $currencies->getStartLine() + 1
        ));

        $this->assertGreaterThanOrEqual(3, substr_count($currencySource, 'applyBranchScope'));
        $this->assertStringContainsString("orWhereIn('branch_id', \$filters['branch_ids'])", $currencySource);

        foreach (['till_id', 'cashier_id', 'shift_session_id'] as $filter) {
            $this->assertGreaterThanOrEqual(3, substr_count($currencySource, "filters['{$filter}']"));
        }
    }
}
