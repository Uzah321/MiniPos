<?php

namespace Tests\Unit;

use App\Services\Inventory\HospitalityFrontStockReadinessService;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use Modules\Product\Http\Controllers\TradingDayEndController;
use Modules\Sales\Http\Controllers\SalesController;
use Modules\Sales\Support\FastFoodOrderSupport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class HospitalityDeferredFrontStockContractTest extends TestCase
{
    public function test_menu_item_permission_controls_recipe_negative_front_stock(): void
    {
        $sales = $this->methodSource(SalesController::class, 'deductMenuRecipeIngredientsFromFrontStock');
        $modifier = $this->methodSourceFromTrait(FastFoodOrderSupport::class, 'deductRecipeObjectFromFrontStock');

        $this->assertStringContainsString('$allowNegativeFrontStock = (bool) $menuProduct->allow_negative_stock', $sales);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$allowNegativeFrontStock\s*&&\s*\(float\)\s*\$stock->available_quantity\s*<\s*\$requiredQty\s*\)/',
            $sales,
        );
        $this->assertStringNotContainsString('$ingredient->allow_negative_stock', $sales);
        $this->assertStringContainsString("'menu_allows_negative_front_stock' => \$allowNegativeFrontStock", $sales);
        $this->assertStringContainsString('$deferredFrontStockIssue ? false : app(InventoryAllocationService::class)->allocateSale', $sales);
        $this->assertStringContainsString("'negative_quantity_created'", $sales);

        $this->assertStringContainsString('bool $allowNegativeFrontStock = false', $modifier);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$allowNegativeFrontStock\s*&&\s*\(float\)\s*\$stock->available_quantity\s*<\s*\$requiredQty\s*\)/',
            $modifier,
        );
        $this->assertStringNotContainsString('$ingredient->allow_negative_stock', $modifier);
        $this->assertStringContainsString('$deferredFrontStockIssue ? false : app(InventoryAllocationService::class)->allocateSale', $modifier);
    }

    public function test_day_end_and_front_reconciliation_both_block_unresolved_negative_front_stock(): void
    {
        $dayEnd = $this->methodSource(TradingDayEndController::class, 'runDayEnd');
        $preflight = $this->methodSource(TradingDayEndController::class, 'preflight');
        $closing = $this->methodSource(FrontStockDailyClosingController::class, 'store');

        $this->assertStringContainsString('HospitalityFrontStockReadinessService::class', $preflight);
        $this->assertStringContainsString("'blocking_front_stock_issues'", $preflight);
        $this->assertStringContainsString('HospitalityFrontStockReadinessService::class', $dayEnd);
        $this->assertStringContainsString("'code' => 'FRONT_STOCK_ISSUE_REQUIRED'", $dayEnd);
        $this->assertStringContainsString("'allow_without_manual_closing' => ['prohibited']", $dayEnd);
        $this->assertStringContainsString('if (!$manualClosingExists)', $dayEnd);
        $this->assertStringContainsString('HospitalityFrontStockReadinessService::class', $closing);
        $this->assertStringContainsString("'code' => 'FRONT_STOCK_ISSUE_REQUIRED'", $closing);

        $closingExists = $this->methodSource(TradingDayEndController::class, 'frontStockClosingExists');
        $freshness = $this->methodSource(TradingDayEndController::class, 'frontStockClosingIsFresh');
        $calendar = $this->methodSource(TradingDayEndController::class, 'frontStockClosingCalendar');
        $this->assertStringContainsString('frontStockClosingIsFresh', $closingExists);
        $this->assertStringContainsString("->where('created_at', '>', \$closing->updated_at)", $freshness);
        $this->assertStringContainsString("->orWhere('reference_type', '!=', 'front_stock_daily_closing')", $freshness);
        $this->assertStringContainsString("'stale_reconciliation'", $calendar);
    }

    public function test_readiness_is_company_branch_front_area_and_menu_recipe_scoped(): void
    {
        $source = $this->source(HospitalityFrontStockReadinessService::class);

        foreach ([
            "->where('ps.company_id', \$companyId)",
            "->where('ps.branch_id', \$branchId)",
            "LOWER(COALESCE(ps.stock_area, '')) = ?",
            "->where('recipe.company_id', \$companyId)",
            "LOWER(COALESCE(recipe.recipe_type, '')) = ?",
            "'issue_quantity_required'",
            "'menu_product_name'",
            "'ingredient_name'",
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((new ReflectionClass($class))->getFileName());
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    private function methodSourceFromTrait(string $trait, string $method): string
    {
        return $this->methodSource($trait, $method);
    }
}
