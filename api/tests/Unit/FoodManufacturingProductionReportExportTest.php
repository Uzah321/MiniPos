<?php

namespace Tests\Unit;

use Modules\FoodManufacturing\Exports\FoodManufacturingProductionReportExport;
use Tests\TestCase;

class FoodManufacturingProductionReportExportTest extends TestCase
{
    private function payload(): array
    {
        return [
            'summary' => [
                'plan_count' => 1,
                'ready_count' => 1,
                'shortage_count' => 0,
                'produced_count' => 0,
                'planned_quantity' => 25,
                'required_value' => 72.5,
                'shortage_value' => 0,
            ],
            'plans' => [[
                'id' => 'plan-a',
                'plan_number' => 'PLAN-001',
                'created_at' => '2026-10-02 09:00:00',
                'planned_for' => '2026-10-03',
                'branch_name' => 'Main Branch',
                'recipe_name' => 'Chicken Portion Prep',
                'produced_product_name' => 'Prepared Chicken',
                'source_stock_area' => 'back',
                'destination_stock_area' => 'back',
                'planned_quantity' => 25,
                'actual_quantity' => 0,
                'status' => 'ready',
                'can_produce' => true,
                'required_value' => 72.5,
                'shortage_value' => 0,
            ]],
        ];
    }

    public function test_saved_plan_export_builders_render_the_backend_payload(): void
    {
        $excel = FoodManufacturingProductionReportExport::buildSavedPlansExcelXml($this->payload());
        $pdfHtml = FoodManufacturingProductionReportExport::buildSavedPlansPdfHtml($this->payload());

        $this->assertStringContainsString('PLAN-001', $excel);
        $this->assertStringContainsString('Chicken Portion Prep', $excel);
        $this->assertStringContainsString('PLAN-001', $pdfHtml);
        $this->assertStringContainsString('Prepared Chicken', $pdfHtml);
    }

    public function test_saved_plan_download_methods_return_attachments(): void
    {
        $excel = FoodManufacturingProductionReportExport::downloadSavedPlansExcel(
            $this->payload(),
            'saved-production-plans.xls'
        );

        $this->assertSame(200, $excel->getStatusCode());
        $this->assertSame(
            'attachment; filename="saved-production-plans.xls"',
            $excel->headers->get('Content-Disposition')
        );

        $this->assertTrue(method_exists(
            FoodManufacturingProductionReportExport::class,
            'downloadSavedPlansPdf'
        ));
    }
}
