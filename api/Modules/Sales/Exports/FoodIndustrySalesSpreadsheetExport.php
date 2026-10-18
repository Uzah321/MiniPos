<?php

namespace Modules\Sales\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class FoodIndustrySalesSpreadsheetExport
{
    public function __construct(private array $report) {}

    public function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS')
            ->setTitle('Food Industry Sales Report')
            ->setDescription('Food industry sales turnover, daily sales, category sales, menu item profitability and ingredient cost report.');

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Executive Summary');
        $this->summarySheet($summary);

        $menu = $spreadsheet->createSheet();
        $menu->setTitle('Menu Profitability');
        $this->menuSheet($menu);

        $category = $spreadsheet->createSheet();
        $category->setTitle('Category Sales');
        $this->categorySheet($category);

        $ingredients = $spreadsheet->createSheet();
        $ingredients->setTitle('Ingredient Cost');
        $this->ingredientSheet($ingredients);

        $daily = $spreadsheet->createSheet();
        $daily->setTitle('Daily Turnover');
        $this->dailySheet($daily);

        $payments = $spreadsheet->createSheet();
        $payments->setTitle('Payment Summary');
        $this->paymentSheet($payments);

        $receipts = $spreadsheet->createSheet();
        $receipts->setTitle('Receipt Detail');
        $this->receiptSheet($receipts);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private function summarySheet(Worksheet $sheet): void
    {
        $filters = $this->report['filters'] ?? [];
        $currencyCodes = $this->currencyCodes();
        $lastColumn = $this->lastColumn(max(8, 1 + count($currencyCodes)));
        $sheet->setCellValue('A1', 'FOOD INDUSTRY SALES TURNOVER REPORT');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->setCellValue('A2', 'Date Range');
        $sheet->setCellValue('B2', ($filters['date_from'] ?? '-') . ' to ' . ($filters['date_to'] ?? '-'));
        $sheet->setCellValue('D2', 'Generated At');
        $sheet->setCellValue('E2', $this->report['generated_at'] ?? '');

        $row = 4;
        $sheet->fromArray(array_merge(['Metric'], $currencyCodes), null, 'A' . $row);
        $this->header($sheet, 'A' . $row . ':' . $lastColumn . $row);
        $row++;
        $summaryByCurrency = collect($this->report['currency_summary'] ?? [])->keyBy(fn ($item) => strtoupper((string) $this->value($item, 'currency_code', '')));
        foreach (['Menu Items' => 'menu_count', 'Qty Sold' => 'quantity_sold', 'Sales Value' => 'net_sales', 'Cost of Sales (Ingredients)' => 'cost_of_sales', 'Gross Profit' => 'gross_profit', 'Food Cost %' => 'food_cost_percent', 'Gross Margin %' => 'gross_margin_percent'] as $label => $field) {
            $sheet->fromArray(array_merge([$label], collect($currencyCodes)->map(fn ($currencyCode) => $this->value($summaryByCurrency->get($currencyCode), $field, 0))->all()), null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A4:' . $lastColumn . max(4, $row - 1));

        $row += 2;
        $sheet->setCellValue('A' . $row, 'MANAGEMENT HIGHLIGHTS');
        $sheet->mergeCells('A' . $row . ':' . $lastColumn . $row);
        $this->section($sheet, 'A' . $row . ':' . $lastColumn . $row);
        $row++;
        $insights = $this->report['insights'] ?? [];
        $highlights = [
            ['Best Profit Menu', $insights['best_profit_menu']->menu_item_name ?? '-', $insights['best_profit_menu']->gross_profit ?? 0, $insights['best_profit_menu']->currency_code ?? ''],
            ['Highest Food Cost Menu', $insights['highest_food_cost_menu']->menu_item_name ?? '-', $insights['highest_food_cost_menu']->food_cost_percent ?? 0, '%'],
            ['Best Category', $insights['best_category']['category_name'] ?? '-', $insights['best_category']['gross_profit'] ?? 0, $insights['best_category']['currency_code'] ?? ''],
            ['Highest Cost Ingredient', $insights['highest_cost_ingredient']->ingredient_name ?? '-', $insights['highest_cost_ingredient']->consumed_cost ?? 0, $insights['highest_cost_ingredient']->currency_code ?? ''],
        ];
        $sheet->fromArray(['Highlight', 'Name', 'Value', 'Unit'], null, 'A' . $row);
        $this->header($sheet, 'A' . $row . ':D' . $row);
        $row++;
        foreach ($highlights as $h) {
            $sheet->fromArray($h, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A' . ($row - count($highlights) - 1) . ':D' . ($row - 1));
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function menuSheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Menu Item', 'Code', 'Category', 'Receipts', 'Lines', 'Qty Sold'], collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Avg Selling', $currencyCode . ' Net Sales', $currencyCode . ' Cost of Sales', $currencyCode . ' Gross Profit', $currencyCode . ' Food Cost %', $currencyCode . ' Profit / Item'])->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'MENU ITEM PROFITABILITY');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach ($this->pivotGroups($this->report['menu_profitability'] ?? [], ['menu_item_id', 'menu_item_name']) as $group) {
            $first = $group->first();
            $byCurrency = $this->byCurrency($group);
            $values = [$this->value($first, 'menu_item_name'), $this->value($first, 'menu_item_code'), $this->value($first, 'category_name'), $group->sum(fn ($item) => (float) $this->value($item, 'receipt_count', 0)), $group->sum(fn ($item) => (float) $this->value($item, 'line_count', 0)), $group->sum(fn ($item) => (float) $this->value($item, 'quantity_sold', 0))];
            foreach ($currencyCodes as $currencyCode) {
                $item = $byCurrency->get($currencyCode);
                foreach (['average_selling_price', 'net_sales', 'cost_of_sales', 'gross_profit', 'food_cost_percent', 'profit_per_item'] as $field) $values[] = $this->value($item, $field, 0);
            }
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $sheet->freezePane('A4');
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function categorySheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Category', 'Menu Count', 'Qty Sold'], collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Net Sales', $currencyCode . ' Cost of Sales', $currencyCode . ' Gross Profit', $currencyCode . ' Food Cost %', $currencyCode . ' Gross Margin %'])->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'CATEGORY SALES AND GROSS PROFIT');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach ($this->pivotGroups($this->report['category_profit'] ?? [], ['category_name']) as $group) {
            $first = $group->first();
            $byCurrency = $this->byCurrency($group);
            $values = [$this->value($first, 'category_name'), $group->sum(fn ($item) => (float) $this->value($item, 'menu_count', 0)), $group->sum(fn ($item) => (float) $this->value($item, 'quantity_sold', 0))];
            foreach ($currencyCodes as $currencyCode) {
                $item = $byCurrency->get($currencyCode);
                foreach (['net_sales', 'cost_of_sales', 'gross_profit', 'food_cost_percent', 'gross_margin_percent'] as $field) $values[] = $this->value($item, $field, 0);
            }
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $sheet->freezePane('A4');
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function ingredientSheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Date', 'Ingredient', 'Code', 'UOM', 'Receipts', 'Consumed Qty'], collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Avg Unit Cost', $currencyCode . ' Consumed Cost'])->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'INGREDIENT CONSUMPTION AND COST');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach ($this->pivotGroups($this->report['ingredient_consumption'] ?? [], ['sale_date', 'ingredient_id', 'ingredient_name']) as $group) {
            $first = $group->first();
            $byCurrency = $this->byCurrency($group);
            $values = [$this->value($first, 'sale_date'), $this->value($first, 'ingredient_name'), $this->value($first, 'ingredient_code'), $this->value($first, 'uom_code'), $group->sum(fn ($item) => (float) $this->value($item, 'receipt_count', 0)), $group->sum(fn ($item) => (float) $this->value($item, 'consumed_quantity', 0))];
            foreach ($currencyCodes as $currencyCode) {
                $item = $byCurrency->get($currencyCode);
                $values[] = $this->value($item, 'average_unit_cost', 0);
                $values[] = $this->value($item, 'consumed_cost', 0);
            }
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $sheet->freezePane('A4');
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function dailySheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Date', 'Menu Items', 'Qty Sold'], collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Net Sales', $currencyCode . ' Cost of Sales', $currencyCode . ' Gross Profit', $currencyCode . ' Food Cost %'])->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'DAILY SALES TURNOVER');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach ($this->pivotGroups($this->report['daily_sales'] ?? [], ['sale_date']) as $group) {
            $first = $group->first();
            $byCurrency = $this->byCurrency($group);
            $values = [$this->value($first, 'sale_date'), $group->sum(fn ($item) => (float) $this->value($item, 'menu_count', 0)), $group->sum(fn ($item) => (float) $this->value($item, 'quantity_sold', 0))];
            foreach ($currencyCodes as $currencyCode) {
                $item = $byCurrency->get($currencyCode);
                foreach (['net_sales', 'cost_of_sales', 'gross_profit', 'food_cost_percent'] as $field) $values[] = $this->value($item, $field, 0);
            }
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function paymentSheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Payment', 'Money Code', 'Count'], collect($currencyCodes)->flatMap(fn ($currencyCode) => [$currencyCode . ' Amount', $currencyCode . ' Tendered', $currencyCode . ' Change'])->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'PAYMENT SUMMARY');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach ($this->pivotGroups($this->report['payments'] ?? [], ['payment_name', 'money_code']) as $group) {
            $first = $group->first();
            $byCurrency = $this->byCurrency($group);
            $values = [$this->value($first, 'payment_name'), $this->value($first, 'money_code'), $group->sum(fn ($item) => (float) $this->value($item, 'payment_count', 0))];
            foreach ($currencyCodes as $currencyCode) {
                $item = $byCurrency->get($currencyCode);
                $values[] = $this->value($item, 'amount', 0);
                $values[] = $this->value($item, 'amount_tendered', 0);
                $values[] = $this->value($item, 'change_amount', 0);
            }
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function receiptSheet(Worksheet $sheet): void
    {
        $currencyCodes = $this->currencyCodes();
        $headers = array_merge(['Invoice', 'Date Time', 'Lines', 'Payment', 'Cashier', 'Till', 'Customer', 'Sale ID'], collect($currencyCodes)->map(fn ($currencyCode) => $currencyCode . ' Total')->all());
        $lastColumn = $this->lastColumn(count($headers));
        $sheet->setCellValue('A1', 'RECEIPT DETAIL');
        $sheet->mergeCells('A1:' . $lastColumn . '1');
        $this->title($sheet, 'A1:' . $lastColumn . '1');
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:' . $lastColumn . '3');
        $row = 4;
        foreach (($this->report['receipt_detail'] ?? []) as $r) {
            $currencyCode = strtoupper((string) $this->value($r, 'currency_code', ''));
            $values = [$this->value($r, 'invoice_number'), $this->value($r, 'transaction_date'), $this->value($r, 'line_count', 0), $this->value($r, 'payment_method'), $this->value($r, 'cashier_name'), $this->value($r, 'till_name'), $this->value($r, 'customer_name'), $this->value($r, 'id')];
            foreach ($currencyCodes as $code) $values[] = $code === $currencyCode ? $this->value($r, 'total_amount', 0) : null;
            $sheet->fromArray($values, null, 'A' . $row);
            $row++;
        }
        $this->table($sheet, 'A3:' . $lastColumn . max(3, $row - 1));
        $this->autosize($sheet, $this->columnRange($lastColumn));
    }

    private function currencyCodes(): array
    {
        $configured = $this->report['filters']['currency_codes'] ?? [];
        if (is_string($configured)) $configured = array_filter(array_map('trim', explode(',', $configured)));
        $codes = collect(is_array($configured) ? $configured : []);

        foreach (['currency_summary', 'menu_profitability', 'category_profit', 'ingredient_consumption', 'daily_sales', 'payments', 'receipt_detail'] as $section) {
            $codes = $codes->merge(collect($this->report[$section] ?? [])->map(fn ($item) => $this->value($item, 'currency_code')));
        }

        return $codes->map(fn ($value) => strtoupper(trim((string) $value)))->filter()->unique()->values()->all();
    }

    private function value($row, string $field, $default = '')
    {
        if (is_array($row)) return $row[$field] ?? $default;
        if (is_object($row)) return $row->{$field} ?? $default;
        return $default;
    }

    private function pivotGroups($rows, array $keyFields)
    {
        return collect($rows)->groupBy(fn ($row) => json_encode(collect($keyFields)->map(fn ($field) => $this->value($row, $field))->all()))->values();
    }

    private function byCurrency($rows)
    {
        return collect($rows)->keyBy(fn ($row) => strtoupper((string) $this->value($row, 'currency_code')));
    }

    private function lastColumn(int $columnCount): string
    {
        return Coordinate::stringFromColumnIndex(max($columnCount, 1));
    }

    private function columnRange(string $lastColumn): array
    {
        $count = Coordinate::columnIndexFromString($lastColumn);
        return collect(range(1, $count))->map(fn ($index) => Coordinate::stringFromColumnIndex($index))->all();
    }

    private function title(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '172033']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension((int) preg_replace('/[^0-9]/', '', explode(':', $range)[0]))->setRowHeight(25);
    }

    private function section(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
        ]);
    }

    private function header(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '172033']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    private function table(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
    }

    private function numberColumns(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $col) {
            $sheet->getStyle($col . ':' . $col)->getNumberFormat()->setFormatCode('#,##0.0000');
        }
    }

    private function autosize(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
