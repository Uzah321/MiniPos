<?php

namespace Tests\Unit;

use Modules\Product\Http\Controllers\BackStockMonthEndStockTakeController;
use Modules\Product\Http\Controllers\FrontStockDailyClosingController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class HospitalityStockTakeBoundaryTest extends TestCase
{
    public function test_front_stock_snapshot_builder_rejects_every_non_front_area_before_querying_stock(): void
    {
        $controller = new FrontStockDailyClosingController;
        $method = new ReflectionMethod($controller, 'buildFrontStockSnapshotLines');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(
            $controller,
            'company-a',
            'branch-a',
            'back'
        ));
    }

    public function test_front_stock_daily_closing_is_pinned_to_front_stock_for_reads_and_writes(): void
    {
        $source = file_get_contents((new ReflectionClass(FrontStockDailyClosingController::class))->getFileName());

        $this->assertStringContainsString("private const STOCK_AREA = 'front';", $source);
        $this->assertStringContainsString("'stock_area' => ['nullable', 'in:front']", $source);
        $this->assertStringContainsString("'code' => 'FRONT_STOCK_AREA_REQUIRED'", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, "where('stock_area', self::STOCK_AREA)"));
        $this->assertStringContainsString('$stockArea = self::STOCK_AREA;', $source);
        $this->assertStringContainsString("Schema::hasColumn('product_costs', 'currency_code')", $source);
    }

    public function test_back_stock_month_end_count_is_pinned_to_back_stock_and_active_branch(): void
    {
        $source = file_get_contents((new ReflectionClass(BackStockMonthEndStockTakeController::class))->getFileName());

        $this->assertStringContainsString("private const STOCK_AREA = 'back';", $source);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $source);
        $this->assertGreaterThanOrEqual(5, substr_count($source, "'stock_area' => self::STOCK_AREA"));
        $this->assertStringContainsString("Schema::hasColumn('product_costs', 'currency_code')", $source);
    }
}
