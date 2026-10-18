<?php

namespace Modules\Product\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockIssuedToFrontSpreadsheetExport
{
    public function __construct(private array $data)
    {
    }

    public function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS')
            ->setTitle('Stock Issued To Front Report')
            ->setSubject('BOH to Front Stock Issues')
            ->setDescription('Comprehensive BOH stock issued to front report with opening stock, issued quantity, available after issue, issue cost, daily grouping and currency totals.');

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Executive Summary');
        $this->buildSummarySheet($summary);

        $flow = $spreadsheet->createSheet();
        $flow->setTitle('Daily Stock Flow');
        $this->buildDailyStockFlowSheet($flow);

        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Movement Detail');
        $this->buildMovementDetailSheet($detail);

        $currency = $spreadsheet->createSheet();
        $currency->setTitle('Currency Totals');
        $this->buildCurrencySheet($currency);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'STOCK ISSUED TO FRONT REPORT');
        $sheet->mergeCells('A1:J1');
        $this->styleTitle($sheet, 'A1:J1');

        $sheet->setCellValue('A2', 'Date Range');
        $sheet->setCellValue('B2', ($this->data['date_from'] ?? '-') . ' to ' . ($this->data['date_to'] ?? '-'));
        $sheet->setCellValue('D2', 'Generated At');
        $sheet->setCellValue('E2', $this->data['generated_at'] ?? '');

        $cards = [
            ['Movements', $this->data['summary']['movement_count'] ?? 0],
            ['Issue Days', $this->data['summary']['distinct_days'] ?? 0],
            ['Products', $this->data['summary']['distinct_products'] ?? 0],
            ['Movement Type', $this->data['movement_type'] ?? 'boh_issue_to_front'],
            ['Branch', $this->data['branch_id'] ?? '-'],
        ];

        $row = 4;
        foreach ($cards as $index => $card) {
            $col = 1 + ($index * 2);
            $start = $this->cell($col, $row);
            $valueCell = $this->cell($col, $row + 1);
            $end = $this->cell($col + 1, $row);
            $valueEnd = $this->cell($col + 1, $row + 1);

            $sheet->setCellValue($start, $card[0]);
            $sheet->setCellValue($valueCell, $card[1]);
            $sheet->mergeCells($start . ':' . $end);
            $sheet->mergeCells($valueCell . ':' . $valueEnd);
            $this->styleCard($sheet, $col, $row, $col + 1, $row + 1);
        }

        $row = 8;
        $sheet->fromArray(['Currency', 'Movement Count', 'Issued Qty', 'Issue Cost'], null, 'A' . $row);
        $this->styleHeader($sheet, 'A' . $row . ':D' . $row);
        $row++;
        foreach (($this->data['summary']['currency_totals'] ?? []) as $ct) {
            $sheet->fromArray([
                $ct['currency_code'] ?? 'USD',
                $ct['movement_count'] ?? 0,
                $ct['issued_quantity'] ?? 0,
                $ct['total_cost'] ?? 0,
            ], null, 'A' . $row);
            $row++;
        }
        $this->finishTable($sheet, 'A8:D' . max(8, $row - 1));
        $this->numberColumns($sheet, ['B', 'C', 'D']);
        $this->autosize($sheet, range('A', 'J'));
    }

    private function buildDailyStockFlowSheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'DAILY STOCK ISSUE FLOW');
        $sheet->mergeCells('A1:O1');
        $this->styleTitle($sheet, 'A1:O1');

        $row = 3;
        foreach (($this->data['days'] ?? []) as $day) {
            $sheet->setCellValue('A' . $row, 'Issue Date: ' . ($day['issue_date'] ?? '-'));
            $sheet->mergeCells('A' . $row . ':O' . $row);
            $this->styleSection($sheet, 'A' . $row . ':O' . $row);
            $row++;

            $headers = [
                'Product', 'Code', 'SKU', 'Type', 'UOM', 'Currency', 'Movements',
                'Opening Front Stock', 'Issued Qty', 'Front Stock After Issue',
                'BOH Before Issue', 'BOH After Issue', 'Avg Unit Cost', 'Issue Cost', 'Issue Date'
            ];
            $sheet->fromArray($headers, null, 'A' . $row);
            $this->styleHeader($sheet, 'A' . $row . ':O' . $row);
            $row++;

            foreach (($day['product_summary'] ?? []) as $item) {
                $sheet->fromArray([
                    $item['product_name'] ?? 'Product',
                    $item['product_code'] ?? '-',
                    $item['product_sku'] ?? '-',
                    $item['product_type'] ?? '-',
                    $item['uom_code'] ?? 'EACH',
                    $item['currency_code'] ?? 'USD',
                    $item['movement_count'] ?? 0,
                    $item['front_opening_qty'] ?? 0,
                    $item['issued_quantity'] ?? 0,
                    $item['front_available_after_issue'] ?? 0,
                    $item['back_opening_before_issue'] ?? 0,
                    $item['back_available_after_issue'] ?? 0,
                    $item['average_unit_cost'] ?? 0,
                    $item['total_cost'] ?? 0,
                    $day['issue_date'] ?? '-',
                ], null, 'A' . $row);
                $row++;
            }

            $row++;
        }

        $this->finishTable($sheet, 'A1:O' . max(1, $row - 1));
        $this->numberColumns($sheet, ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N']);
        $this->autosize($sheet, range('A', 'O'));
        $sheet->freezePane('A4');
    }

    private function buildMovementDetailSheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'MOVEMENT DETAIL / BEFORE AND AFTER STOCK');
        $sheet->mergeCells('A1:W1');
        $this->styleTitle($sheet, 'A1:W1');

        $headers = [
            'Date', 'Time', 'Product', 'Code', 'SKU', 'Type', 'UOM', 'From Area', 'To Area',
            'Day Opening Source', 'Day Opening Front', 'Front Before Issue', 'Issued Qty', 'Front After Issue',
            'BOH Before Issue', 'BOH After Issue', 'Qty In', 'Qty Out', 'Unit Cost', 'Issue Cost', 'Currency', 'Created By', 'Notes'
        ];
        $sheet->fromArray($headers, null, 'A3');
        $this->styleHeader($sheet, 'A3:W3');

        $row = 4;
        foreach (($this->data['movements'] ?? []) as $line) {
            $sheet->fromArray([
                $line['issue_date'] ?? '-',
                substr((string) ($line['created_at'] ?? ''), 11, 8),
                $line['product_name'] ?? 'Product',
                $line['product_code'] ?? '-',
                $line['product_sku'] ?? '-',
                $line['product_type'] ?? '-',
                $line['uom_code'] ?? 'EACH',
                $line['from_stock_area'] ?? 'back',
                $line['to_stock_area'] ?? 'front',
                $line['day_opening_source'] ?? '-',
                $line['day_opening_front_qty'] ?? 0,
                $line['front_opening_before_issue'] ?? 0,
                $line['issued_quantity'] ?? 0,
                $line['front_available_after_issue'] ?? 0,
                $line['back_opening_before_issue'] ?? 0,
                $line['back_available_after_issue'] ?? 0,
                $line['quantity_in'] ?? 0,
                $line['quantity_out'] ?? 0,
                $line['unit_cost'] ?? 0,
                $line['total_cost'] ?? 0,
                $line['currency_code'] ?? 'USD',
                $line['created_by_name'] ?? '-',
                $line['notes'] ?? '-',
            ], null, 'A' . $row);
            $row++;
        }

        $this->finishTable($sheet, 'A3:W' . max(3, $row - 1));
        $this->numberColumns($sheet, ['K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T']);
        $this->autosize($sheet, range('A', 'W'));
        $sheet->freezePane('A4');
    }

    private function buildCurrencySheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CURRENCY TOTALS');
        $sheet->mergeCells('A1:E1');
        $this->styleTitle($sheet, 'A1:E1');
        $sheet->fromArray(['Scope', 'Currency', 'Movement Count', 'Issued Qty', 'Issue Cost'], null, 'A3');
        $this->styleHeader($sheet, 'A3:E3');
        $row = 4;

        foreach (($this->data['summary']['currency_totals'] ?? []) as $ct) {
            $sheet->fromArray(['All Selected Dates', $ct['currency_code'] ?? 'USD', $ct['movement_count'] ?? 0, $ct['issued_quantity'] ?? 0, $ct['total_cost'] ?? 0], null, 'A' . $row);
            $row++;
        }

        foreach (($this->data['days'] ?? []) as $day) {
            foreach (($day['currency_totals'] ?? []) as $ct) {
                $sheet->fromArray([$day['issue_date'] ?? '-', $ct['currency_code'] ?? 'USD', $ct['movement_count'] ?? 0, $ct['issued_quantity'] ?? 0, $ct['total_cost'] ?? 0], null, 'A' . $row);
                $row++;
            }
        }

        $this->finishTable($sheet, 'A3:E' . max(3, $row - 1));
        $this->numberColumns($sheet, ['C', 'D', 'E']);
        $this->autosize($sheet, range('A', 'E'));
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    private function styleTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '172033']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }

    private function styleSection(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
        ]);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '172033']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    private function styleCard(Worksheet $sheet, int $startCol, int $startRow, int $endCol, int $endRow): void
    {
        $range = $this->cell($startCol, $startRow) . ':' . $this->cell($endCol, $endRow);
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        ]);
        $sheet->getStyle($this->cell($startCol, $startRow + 1))->getFont()->setBold(true)->setSize(13);
    }

    private function finishTable(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ]);
    }

    private function numberColumns(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $column) {
            $sheet->getStyle($column . ':' . $column)->getNumberFormat()->setFormatCode('#,##0.0000');
        }
    }

    private function autosize(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
