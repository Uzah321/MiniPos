<?php

namespace Tests\Unit;

use Modules\Sales\Exports\FoodIndustrySalesSpreadsheetExport;
use Modules\Sales\Http\Controllers\FoodIndustrySalesReportController;
use ReflectionMethod;
use Tests\TestCase;

class FoodIndustrySalesReportProjectionTest extends TestCase
{
    public function test_spreadsheet_projects_tendered_amounts_and_receipt_line_counts(): void
    {
        $report = $this->reportFixture();
        $spreadsheet = (new FoodIndustrySalesSpreadsheetExport($report))->spreadsheet();

        $payments = $spreadsheet->getSheetByName('Payment Summary');
        $this->assertNotNull($payments);
        $this->assertSame('USD Amount', $payments->getCell('D3')->getValue());
        $this->assertSame('USD Tendered', $payments->getCell('E3')->getValue());
        $this->assertSame('USD Change', $payments->getCell('F3')->getValue());
        $this->assertSame(10, $payments->getCell('D4')->getValue());
        $this->assertSame(12, $payments->getCell('E4')->getValue());
        $this->assertSame(2, $payments->getCell('F4')->getValue());

        $receipts = $spreadsheet->getSheetByName('Receipt Detail');
        $this->assertNotNull($receipts);
        $this->assertSame('Lines', $receipts->getCell('C3')->getValue());
        $this->assertSame(3, $receipts->getCell('C4')->getValue());
        $this->assertSame('Cash', $receipts->getCell('D4')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_pdf_payment_summary_projects_received_tendered_and_change_amounts(): void
    {
        $method = new ReflectionMethod(FoodIndustrySalesReportController::class, 'pdfHtml');
        $method->setAccessible(true);
        $html = $method->invoke(app(FoodIndustrySalesReportController::class), $this->reportFixture());

        $this->assertStringContainsString('USD Amount', $html);
        $this->assertStringContainsString('USD Tendered', $html);
        $this->assertStringContainsString('USD Change', $html);
        $this->assertStringContainsString('USD 10.00', $html);
        $this->assertStringContainsString('USD 12.00', $html);
        $this->assertStringContainsString('USD 2.00', $html);
        $this->assertStringContainsString('Receipt Detail', $html);
        $this->assertStringContainsString('<th>Lines</th>', $html);
        $this->assertStringContainsString('<td class="num">3</td>', $html);
    }

    private function reportFixture(): array
    {
        return [
            'report_title' => 'FOOD INDUSTRY SALES REPORT',
            'generated_at' => '2026-10-03T12:00:00+02:00',
            'filters' => [
                'date_from' => '2026-10-03',
                'date_to' => '2026-10-03',
                'currency_codes' => ['USD'],
            ],
            'currency_summary' => [[
                'currency_code' => 'USD',
                'menu_count' => 1,
                'quantity_sold' => 3,
                'net_sales' => 10,
                'cost_of_sales' => 4,
                'gross_profit' => 6,
                'food_cost_percent' => 40,
                'gross_margin_percent' => 60,
            ]],
            'menu_profitability' => [],
            'category_profit' => [],
            'ingredient_consumption' => [],
            'daily_sales' => [],
            'payments' => [[
                'payment_name' => 'Cash',
                'money_code' => 'cash',
                'currency_code' => 'USD',
                'payment_count' => 1,
                'amount' => 10,
                'amount_tendered' => 12,
                'change_amount' => 2,
            ]],
            'receipt_detail' => [[
                'id' => 'sale-1',
                'invoice_number' => 'INV-1',
                'transaction_date' => '2026-10-03 11:00:00',
                'line_count' => 3,
                'payment_method' => 'Cash',
                'cashier_name' => 'Cashier One',
                'till_name' => 'Till One',
                'customer_name' => 'Walk In',
                'currency_code' => 'USD',
                'total_amount' => 10,
            ]],
            'insights' => [],
        ];
    }
}
