<?php

namespace Tests\Unit;

use Modules\Product\Http\Controllers\EnterpriseInventoryController;
use ReflectionMethod;
use Tests\TestCase;

class EnterpriseInventoryDashboardModeTest extends TestCase
{
    public function test_retail_dashboard_omits_hospitality_stock_area_payload(): void
    {
        $dashboard = $this->modePayload(false);

        $this->assertSame(['stocked_products' => 12], $dashboard['summary']);
        $this->assertArrayNotHasKey('stock_areas', $dashboard);
        $this->assertArrayNotHasKey('non_turnover_stock', $dashboard);
    }

    public function test_hospitality_dashboard_includes_stock_area_payload(): void
    {
        $dashboard = $this->modePayload(true);

        $this->assertSame(12, $dashboard['summary']['stocked_products']);
        $this->assertSame(8, $dashboard['summary']['back_stock_products']);
        $this->assertSame([['stock_area' => 'back']], $dashboard['stock_areas']);
        $this->assertSame(['summary' => ['product_count' => 2]], $dashboard['non_turnover_stock']);
    }

    private function modePayload(bool $isHospitality): array
    {
        $method = new ReflectionMethod(EnterpriseInventoryController::class, 'withHospitalityStockAreaData');

        return $method->invoke(
            new EnterpriseInventoryController,
            ['summary' => ['stocked_products' => 12], 'branches' => []],
            $isHospitality,
            [
                'summary' => ['back_stock_products' => 8],
                'stock_areas' => [['stock_area' => 'back']],
                'non_turnover_stock' => ['summary' => ['product_count' => 2]],
            ]
        );
    }
}
