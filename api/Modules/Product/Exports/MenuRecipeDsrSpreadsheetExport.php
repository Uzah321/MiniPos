<?php

namespace Modules\Product\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MenuRecipeDsrSpreadsheetExport
{
    public function __construct(private mixed $report)
    {
    }

    public static function download(mixed $report, string $filename): StreamedResponse
    {
        $export = new self($report);
        $spreadsheet = $export->build();

        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-store, no-cache, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS')
            ->setTitle('Daily Stock Reconciliation')
            ->setSubject('Menu recipe DSR')
            ->setDescription('Enterprise menu recipe stock reconciliation report');

        $ctx = new DsrWorkbookContext($this->report);

        $this->summarySheet($spreadsheet->getActiveSheet(), $ctx);
        $spreadsheet->getActiveSheet()->setTitle('Executive Summary');

        $menuProfit = $spreadsheet->createSheet();
        $menuProfit->setTitle('Menu Profit');
        $this->menuProfitSheet($menuProfit, $ctx);

        $flow = $spreadsheet->createSheet();
        $flow->setTitle('Menu Ingredient Flow');
        $this->menuIngredientFlowSheet($flow, $ctx);

        $shared = $spreadsheet->createSheet();
        $shared->setTitle('Shared Ingredient Flow');
        $this->sharedIngredientSheet($shared, $ctx);

        $movement = $spreadsheet->createSheet();
        $movement->setTitle('Stock Movement');
        $this->stockMovementSheet($movement, $ctx);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private function summarySheet($sheet, DsrWorkbookContext $ctx): void
    {
        $this->title($sheet, 'DAILY STOCK RECONCILIATION', 'Trading Date: ' . $ctx->date . ' | Status: ' . $ctx->status, 9);

        $row = 4;
        $this->section($sheet, $row++, 'Currency performance');
        $headers = ['Currency', 'Revenue', 'Recipe Cost', 'Expected Profit', 'Food Cost %', 'Variance Value'];
        $this->headers($sheet, $row++, $headers);

        foreach ($ctx->currencyTotals as $currency) {
            $code = $ctx->get($currency, 'currency_code', 'USD');
            $revenue = $ctx->money($ctx->get($currency, 'total_revenue'));
            $cost = $ctx->money($ctx->get($currency, 'total_recipe_cost'));
            $profit = $ctx->money($ctx->get($currency, 'gross_profit'));
            $foodCost = $revenue > 0 ? round(($cost / $revenue) * 100, 2) : 0;
            $variance = $ctx->money($ctx->get($currency, 'total_variance_value'));
            $sheet->fromArray([$code, $revenue, $cost, $profit, $foodCost, $variance], null, 'A' . $row++);
        }

        $this->totalsBand($sheet, $row, 6);
        if ($ctx->currencyTotals->count() <= 1) {
            $single = $ctx->currencyTotals->first() ?? [];
            $totalRevenue = $ctx->money($ctx->get($single, 'total_revenue'));
            $totalCost = $ctx->money($ctx->get($single, 'total_recipe_cost'));
            $sheet->setCellValue('A' . $row, 'TRADING DATE TOTAL');
            $sheet->setCellValue('B' . $row, $totalRevenue);
            $sheet->setCellValue('C' . $row, $totalCost);
            $sheet->setCellValue('D' . $row, $ctx->money($ctx->get($single, 'gross_profit')));
            $sheet->setCellValue('E' . $row, $totalRevenue > 0 ? round(($totalCost / $totalRevenue) * 100, 2) : 0);
        } else {
            $sheet->setCellValue('A' . $row, 'NO CROSS-CURRENCY TOTAL');
            $sheet->setCellValue('B' . $row, 'Use the authoritative currency rows above');
            $sheet->mergeCells("B{$row}:F{$row}");
        }
        $row++;

        $row += 2;
        $this->section($sheet, $row++, 'Key highlights');
        $sheet->fromArray([
            ['Sold menu items', $ctx->menuLines->count()],
            ['Recipe ingredient lines', $ctx->recipeLines->count()],
            ['Ingredients reconciled', $ctx->ingredientLines->count()],
            ['Report generated', now()->format('d M Y H:i')],
        ], null, 'A' . $row);

        $this->finish($sheet, 9, $row + 8, ['B:F']);
    }

    private function menuProfitSheet($sheet, DsrWorkbookContext $ctx): void
    {
        $this->title($sheet, 'MENU ITEM PROFIT BY CURRENCY', 'Revenue, selling price, recipe cost, expected profit and food cost percentage.', 12);
        $row = 4;
        $this->headers($sheet, $row++, ['Menu Code', 'Menu Item', 'Currency', 'Qty Sold', 'Avg Selling Price', 'Revenue', 'Recipe Cost', 'Expected Profit', 'Food Cost %', 'Ingredient Lines', 'Recipe Status', 'Notes']);

        foreach ($ctx->menuLines as $menu) {
            $revenue = $ctx->money($ctx->get($menu, 'menu_revenue'));
            $cost = $ctx->money($ctx->get($menu, 'menu_recipe_cost'));
            $profit = $ctx->money($ctx->get($menu, 'menu_gross_profit', $revenue - $cost));
            $menuId = (string) $ctx->get($menu, 'menu_product_id');
            $ingredientCount = $ctx->recipeLines->filter(fn ($line) => (string) $ctx->get($line, 'menu_product_id') === $menuId)->count();

            $sheet->fromArray([
                $ctx->get($menu, 'menu_product_code'),
                $ctx->get($menu, 'menu_product_name'),
                $ctx->get($menu, 'currency_code', 'USD'),
                $ctx->qty($ctx->get($menu, 'menu_quantity_sold')),
                $ctx->money($ctx->get($menu, 'menu_avg_selling_price')),
                $revenue,
                $cost,
                $profit,
                $ctx->pct($ctx->get($menu, 'menu_cost_percent')),
                $ingredientCount,
                $ingredientCount > 0 ? 'Recipe linked' : 'No recipe lines',
                $ingredientCount > 0 ? '' : 'Check Food Recipe / BOM setup',
            ], null, 'A' . $row++);
        }

        $row++;
        foreach ($ctx->menuLines->groupBy(fn ($line) => (string) $ctx->get($line, 'currency_code', 'USD')) as $currencyCode => $rows) {
            $revenue = $ctx->money($rows->sum(fn ($line) => (float) $ctx->get($line, 'menu_revenue')));
            $cost = $ctx->money($rows->sum(fn ($line) => (float) $ctx->get($line, 'menu_recipe_cost')));
            $profit = $ctx->money($rows->sum(fn ($line) => (float) $ctx->get($line, 'menu_gross_profit')));
            $this->totalsBand($sheet, $row, 12);
            $sheet->fromArray(['', 'TOTAL PROFIT FOR TRADING DATE', $currencyCode, '', '', $revenue, $cost, $profit, $revenue > 0 ? round(($cost / $revenue) * 100, 2) : 0, '', '', ''], null, 'A' . $row++);
        }

        $this->finish($sheet, 12, $row, ['D:I']);
    }

    private function menuIngredientFlowSheet($sheet, DsrWorkbookContext $ctx): void
    {
        $this->title($sheet, 'MENU ITEM INGREDIENT FLOW', 'Each menu item is grouped with ingredients consumed, stock movement, cost, closing and variance.', 18);
        $row = 4;

        foreach ($ctx->menuLines as $menu) {
            $menuId = (string) $ctx->get($menu, 'menu_product_id');
            $currency = $ctx->get($menu, 'currency_code', 'USD');
            $children = $ctx->recipeLines->filter(fn ($line) => (string) $ctx->get($line, 'menu_product_id') === $menuId)->values();

            $sheet->mergeCells("A{$row}:R{$row}");
            $sheet->setCellValue("A{$row}", $ctx->get($menu, 'menu_product_name') . '  |  ' . $ctx->get($menu, 'menu_product_code') . '  |  ' . $currency);
            $this->menuRow($sheet, $row++);

            $this->headers($sheet, $row++, ['Qty Sold', 'Avg Selling Price', 'Revenue', 'Recipe Cost', 'Expected Profit', 'Food Cost %', 'Ingredient Lines']);
            $sheet->fromArray([
                $ctx->qty($ctx->get($menu, 'menu_quantity_sold')),
                $ctx->money($ctx->get($menu, 'menu_avg_selling_price')),
                $ctx->money($ctx->get($menu, 'menu_revenue')),
                $ctx->money($ctx->get($menu, 'menu_recipe_cost')),
                $ctx->money($ctx->get($menu, 'menu_gross_profit')),
                $ctx->pct($ctx->get($menu, 'menu_cost_percent')),
                $children->count(),
            ], null, 'A' . $row++);
            $row++;

            $this->headers($sheet, $row++, ['Ingredient Code', 'Ingredient', 'UOM', 'Recipe Qty', 'Sold Qty', 'Consumed', 'Unit Cost', 'Total Cost', 'Opening', 'Receipts', 'BOH To Front', 'Issues', 'Sales Consumption', 'Adjustments', 'Expected', 'Counted', 'Variance', 'Variance Value']);

            foreach ($children as $line) {
                $ingredientId = (string) $ctx->get($line, 'ingredient_product_id');
                $recon = $ctx->ingredientById->get($ingredientId);
                $variance = $ctx->qty($ctx->get($recon, 'variance_qty', $ctx->get($recon, 'variance_quantity', 0)));

                $sheet->fromArray([
                    $ctx->get($line, 'ingredient_code'),
                    $ctx->get($line, 'ingredient_name'),
                    $ctx->get($line, 'uom_code'),
                    $ctx->qty($ctx->get($line, 'recipe_qty_per_menu')),
                    $ctx->qty($ctx->get($line, 'menu_quantity_sold')),
                    $ctx->qty($ctx->get($line, 'ingredient_consumed_qty')),
                    $ctx->money($ctx->get($line, 'unit_cost')),
                    $ctx->money($ctx->get($line, 'ingredient_total_cost')),
                    $ctx->qty($ctx->get($recon, 'opening_qty')),
                    $ctx->qty($ctx->get($recon, 'receipt_qty')),
                    $ctx->qty($ctx->get($recon, 'boh_to_front_qty')),
                    $ctx->qty($ctx->get($recon, 'issue_qty')),
                    $ctx->qty($ctx->get($recon, 'sales_consumption_qty')),
                    $ctx->qty($ctx->get($recon, 'adjustment_qty')),
                    $ctx->qty($ctx->get($recon, 'expected_closing_qty')),
                    $ctx->qty($ctx->get($recon, 'counted_closing_qty')),
                    $variance,
                    $ctx->money($ctx->get($recon, 'variance_value')),
                ], null, 'A' . $row);

                if (abs($variance) > 0.0001) {
                    $this->negativeRow($sheet, $row, 18);
                }
                $row++;
            }

            $row++;
        }

        $this->finish($sheet, 18, max($row, 4), ['D:R']);
    }

    private function sharedIngredientSheet($sheet, DsrWorkbookContext $ctx): void
    {
        $this->title($sheet, 'SHARED INGREDIENT FLOW', 'Shows ingredients consumed by multiple menu items and the combined movement for the trading day.', 12);
        $row = 4;
        $this->headers($sheet, $row++, ['Ingredient Code', 'Ingredient', 'UOM', 'Consumed By Menu Items', 'Total Consumed', 'Total Cost', 'Opening', 'Receipts', 'BOH To Front', 'Expected', 'Counted', 'Variance']);

        $grouped = $ctx->recipeLines->groupBy(fn ($line) => (string) $ctx->get($line, 'ingredient_product_id'));
        foreach ($grouped as $ingredientId => $items) {
            $first = $items->first();
            $recon = $ctx->ingredientById->get((string) $ingredientId);
            $menus = $items->pluck('menu_product_name')->filter()->unique()->implode(', ');

            $sheet->fromArray([
                $ctx->get($first, 'ingredient_code'),
                $ctx->get($first, 'ingredient_name'),
                $ctx->get($first, 'uom_code'),
                $menus,
                $ctx->qty($items->sum(fn ($line) => (float) $ctx->get($line, 'ingredient_consumed_qty'))),
                $ctx->money($items->sum(fn ($line) => (float) $ctx->get($line, 'ingredient_total_cost'))),
                $ctx->qty($ctx->get($recon, 'opening_qty')),
                $ctx->qty($ctx->get($recon, 'receipt_qty')),
                $ctx->qty($ctx->get($recon, 'boh_to_front_qty')),
                $ctx->qty($ctx->get($recon, 'expected_closing_qty')),
                $ctx->qty($ctx->get($recon, 'counted_closing_qty')),
                $ctx->qty($ctx->get($recon, 'variance_qty')),
            ], null, 'A' . $row++);
        }

        $this->finish($sheet, 12, $row, ['E:L']);
    }

    private function stockMovementSheet($sheet, DsrWorkbookContext $ctx): void
    {
        $this->title($sheet, 'INGREDIENT STOCK MOVEMENT', 'Opening + receipts + BOH to front - issues - consumption + adjustments = expected closing.', 15);
        $row = 4;
        $this->headers($sheet, $row++, ['Ingredient Code', 'Ingredient', 'UOM', 'Currency', 'Opening', 'Receipts', 'BOH To Front', 'Issues', 'Sales Consumption', 'Adjustments', 'Expected Closing', 'Counted Closing', 'Variance Qty', 'Unit Cost', 'Variance Value']);

        foreach ($ctx->ingredientLines as $line) {
            $variance = $ctx->qty($ctx->get($line, 'variance_qty', $ctx->get($line, 'variance_quantity', 0)));
            $sheet->fromArray([
                $ctx->get($line, 'ingredient_code'),
                $ctx->get($line, 'ingredient_name'),
                $ctx->get($line, 'uom_code'),
                $ctx->get($line, 'currency_code', 'USD'),
                $ctx->qty($ctx->get($line, 'opening_qty')),
                $ctx->qty($ctx->get($line, 'receipt_qty')),
                $ctx->qty($ctx->get($line, 'boh_to_front_qty')),
                $ctx->qty($ctx->get($line, 'issue_qty')),
                $ctx->qty($ctx->get($line, 'sales_consumption_qty')),
                $ctx->qty($ctx->get($line, 'adjustment_qty')),
                $ctx->qty($ctx->get($line, 'expected_closing_qty')),
                $ctx->qty($ctx->get($line, 'counted_closing_qty')),
                $variance,
                $ctx->money($ctx->get($line, 'unit_cost')),
                $ctx->money($ctx->get($line, 'variance_value')),
            ], null, 'A' . $row);

            if (abs($variance) > 0.0001) {
                $this->negativeRow($sheet, $row, 15);
            }
            $row++;
        }

        $this->finish($sheet, 15, $row, ['E:O']);
    }

    private function title($sheet, string $title, string $subtitle, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->mergeCells("A1:{$last}1");
        $sheet->mergeCells("A2:{$last}2");
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '111827']],
        ]);
        $sheet->getStyle("A2:{$last}2")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF1F7']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
    }

    private function section($sheet, int $row, string $title): void
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
        ]);
    }

    private function headers($sheet, int $row, array $headers): void
    {
        $sheet->fromArray($headers, null, 'A' . $row);
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '111827']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    private function menuRow($sheet, int $row): void
    {
        $sheet->getStyle("A{$row}:R{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '111827']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDE68A']],
        ]);
    }


    private function totalsBand($sheet, int $row, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0F172A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '16A34A']],
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '16A34A']],
            ],
        ]);
    }

    private function negativeRow($sheet, int $row, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '991B1B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF2F2']],
        ]);
    }

    private function finish($sheet, int $columns, int $highestRow, array $numericRanges = []): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $highestRow = max($highestRow, 4);

        $sheet->freezePane('A4');
        $sheet->getStyle("A1:{$last}{$highestRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A1:{$last}{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');

        foreach (range(1, $columns) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        foreach ($numericRanges as $range) {
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        }
    }
}

class DsrWorkbookContext
{
    public Collection $lines;
    public Collection $menuLines;
    public Collection $recipeLines;
    public Collection $ingredientLines;
    public Collection $currencyTotals;
    public Collection $ingredientById;
    public array $summary;
    public string $date;
    public string $status;

    public function __construct(public mixed $report)
    {
        $this->lines = collect($report->lines ?? []);
        $this->menuLines = $this->lines->filter(fn ($line) => $this->get($line, 'section') === 'menu_summary')->values();
        $this->recipeLines = $this->lines->filter(fn ($line) => in_array($this->get($line, 'section'), ['menu_recipe', 'ingredient_consumption', 'recipe_consumption'], true))->values();
        $this->ingredientLines = $this->lines->filter(fn ($line) => $this->get($line, 'section') === 'ingredient_reconciliation')->values();
        $this->ingredientById = $this->ingredientLines->keyBy(fn ($line) => (string) $this->get($line, 'ingredient_product_id'));

        $metadata = $report->metadata ?? [];
        if (!is_array($metadata)) {
            $metadata = json_decode((string) $metadata, true) ?: [];
        }

        $this->summary = $metadata['summary'] ?? [];
        $salesTotals = collect($this->summary['currency_totals'] ?? [])
            ->keyBy(fn ($row) => strtoupper((string) $this->get($row, 'currency_code', 'USD')));
        $inventoryTotals = collect($this->summary['inventory_currency_totals'] ?? [])
            ->keyBy(fn ($row) => strtoupper((string) $this->get($row, 'currency_code', 'USD')));

        $this->currencyTotals = $salesTotals->keys()
            ->merge($inventoryTotals->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currencyCode) use ($salesTotals, $inventoryTotals) {
                $sales = $salesTotals->get($currencyCode, []);
                $inventory = $inventoryTotals->get($currencyCode, []);

                return [
                    'currency_code' => $currencyCode,
                    'total_revenue' => $this->get($sales, 'total_revenue', 0),
                    'total_recipe_cost' => $this->get($sales, 'total_recipe_cost', 0),
                    'gross_profit' => $this->get($sales, 'gross_profit', 0),
                    'total_variance_value' => $this->get($inventory, 'total_variance_value', 0),
                ];
            });
        $this->date = Carbon::parse($report->trading_date ?? now())->format('d M Y');
        $this->status = strtoupper((string) ($report->status ?? 'LIVE'));
    }

    public function get(mixed $row, string $key, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? $default;
        }
        return $row->{$key} ?? $default;
    }

    public function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    public function qty(mixed $value): float
    {
        return round((float) ($value ?? 0), 4);
    }

    public function pct(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
