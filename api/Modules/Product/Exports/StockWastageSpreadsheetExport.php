<?php

namespace Modules\Product\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockWastageSpreadsheetExport
{
    public function __construct(private array $data) {}

    public function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('ServeNest POS')->setTitle('Stock Wastage Report');
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Executive Summary');
        $this->summarySheet($summary);
        $daily = $spreadsheet->createSheet();
        $daily->setTitle('Daily Wastage Flow');
        $this->dailySheet($daily);
        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Wastage Detail');
        $this->detailSheet($detail);
        $currency = $spreadsheet->createSheet();
        $currency->setTitle('Currency Totals');
        $this->currencySheet($currency);
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private function summarySheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'STOCK WASTAGE REPORT');
        $sheet->mergeCells('A1:J1');
        $this->title($sheet, 'A1:J1');
        $sheet->fromArray(['Date Range', ($this->data['date_from'] ?? '-') . ' to ' . ($this->data['date_to'] ?? '-'), '', 'Generated At', $this->data['generated_at'] ?? ''], null, 'A2');
        $sheet->fromArray(['Metric', 'Value'], null, 'A4');
        $this->header($sheet, 'A4:B4');
        $rows = [
            ['Wastage Days', $this->data['summary']['wastage_days'] ?? 0],
            ['Line Count', $this->data['summary']['line_count'] ?? 0],
            ['Total Quantity Wasted', $this->data['summary']['total_quantity'] ?? 0],
            ['Stock Area', $this->data['stock_area'] ?? 'ALL'],
        ];
        $r = 5;
        foreach ($rows as $row) { $sheet->fromArray($row, null, 'A' . $r++); }
        $sheet->fromArray(['Currency', 'Lines', 'Total Qty', 'Total Wastage Cost'], null, 'D4');
        $this->header($sheet, 'D4:G4');
        $r = 5;
        foreach (($this->data['summary']['currency_totals'] ?? []) as $ct) {
            $sheet->fromArray([$ct['currency_code'] ?? 'USD', $ct['line_count'] ?? 0, $ct['total_quantity'] ?? 0, $ct['total_cost'] ?? 0], null, 'D' . $r++);
        }
        $this->finish($sheet, 'A4:B' . max(4, count($rows) + 4));
        $this->finish($sheet, 'D4:G' . max(4, $r - 1));
        $this->numberCols($sheet, ['B', 'F', 'G']);
        $this->autosize($sheet, range('A', 'J'));
    }

    private function dailySheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'DAILY WASTAGE STOCK FLOW');
        $sheet->mergeCells('A1:N1');
        $this->title($sheet, 'A1:N1');
        $r = 3;
        foreach (($this->data['days'] ?? []) as $day) {
            $sheet->setCellValue('A' . $r, 'Wastage Date: ' . ($day['wastage_date'] ?? '-') . ' | Total Qty: ' . ($day['total_quantity'] ?? 0));
            $sheet->mergeCells('A' . $r . ':N' . $r);
            $this->section($sheet, 'A' . $r . ':N' . $r);
            $r++;
            $headers = ['Reference', 'Product', 'Code', 'SKU', 'Type', 'UOM', 'Area', 'Reason', 'Opening Stock', 'Wasted Qty', 'Closing Stock', 'Unit Cost', 'Wastage Cost', 'Currency'];
            $sheet->fromArray($headers, null, 'A' . $r);
            $this->header($sheet, 'A' . $r . ':N' . $r);
            $r++;
            foreach (($day['lines'] ?? []) as $line) {
                $sheet->fromArray([
                    $line['reference_no'] ?? '-', $line['product_name'] ?? 'Product', $line['product_code'] ?? '-', $line['product_sku'] ?? '-', $line['product_type'] ?? '-', $line['uom_code'] ?? 'EACH', $line['stock_area'] ?? '-', $line['reason_code'] ?? '-',
                    $line['opening_quantity'] ?? 0, $line['wastage_quantity'] ?? 0, $line['closing_quantity'] ?? 0, $line['unit_cost'] ?? 0, $line['total_cost'] ?? 0, $line['currency_code'] ?? 'USD'
                ], null, 'A' . $r++);
            }
            $r++;
        }
        $this->finish($sheet, 'A1:N' . max(1, $r - 1));
        $this->numberCols($sheet, ['I', 'J', 'K', 'L', 'M']);
        $this->autosize($sheet, range('A', 'N'));
        $sheet->freezePane('A4');
    }

    private function detailSheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'WASTAGE DETAIL');
        $sheet->mergeCells('A1:R1');
        $this->title($sheet, 'A1:R1');
        $headers = ['Date', 'Reference', 'Product', 'Code', 'SKU', 'Type', 'UOM', 'Area', 'Reason', 'Opening Stock', 'Wasted Qty', 'Closing Stock', 'Unit Cost', 'Wastage Cost', 'Currency', 'Created By', 'Header Notes', 'Line Notes'];
        $sheet->fromArray($headers, null, 'A3');
        $this->header($sheet, 'A3:R3');
        $r = 4;
        foreach (($this->data['lines'] ?? []) as $line) {
            $sheet->fromArray([
                $line['wastage_date'] ?? '-', $line['reference_no'] ?? '-', $line['product_name'] ?? 'Product', $line['product_code'] ?? '-', $line['product_sku'] ?? '-', $line['product_type'] ?? '-', $line['uom_code'] ?? 'EACH', $line['stock_area'] ?? '-', $line['reason_code'] ?? '-',
                $line['opening_quantity'] ?? 0, $line['wastage_quantity'] ?? 0, $line['closing_quantity'] ?? 0, $line['unit_cost'] ?? 0, $line['total_cost'] ?? 0, $line['currency_code'] ?? 'USD', $line['created_by_name'] ?? '-', $line['header_notes'] ?? '-', $line['notes'] ?? '-'
            ], null, 'A' . $r++);
        }
        $this->finish($sheet, 'A3:R' . max(3, $r - 1));
        $this->numberCols($sheet, ['J', 'K', 'L', 'M', 'N']);
        $this->autosize($sheet, range('A', 'R'));
        $sheet->freezePane('A4');
    }

    private function currencySheet(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CURRENCY AND REASON TOTALS');
        $sheet->mergeCells('A1:F1');
        $this->title($sheet, 'A1:F1');
        $sheet->fromArray(['Scope', 'Currency/Reason', 'Lines', 'Total Qty', 'Total Cost', 'Type'], null, 'A3');
        $this->header($sheet, 'A3:F3');
        $r = 4;
        foreach (($this->data['summary']['currency_totals'] ?? []) as $ct) {
            $sheet->fromArray(['All Dates', $ct['currency_code'] ?? 'USD', $ct['line_count'] ?? 0, $ct['total_quantity'] ?? 0, $ct['total_cost'] ?? 0, 'Currency'], null, 'A' . $r++);
        }
        foreach (($this->data['days'] ?? []) as $day) {
            foreach (($day['reason_totals'] ?? []) as $rt) {
                $sheet->fromArray([$day['wastage_date'] ?? '-', $rt['reason_code'] ?? '-', $rt['line_count'] ?? 0, $rt['total_quantity'] ?? 0, $rt['total_cost'] ?? 0, 'Reason'], null, 'A' . $r++);
            }
        }
        $this->finish($sheet, 'A3:F' . max(3, $r - 1));
        $this->numberCols($sheet, ['C', 'D', 'E']);
        $this->autosize($sheet, range('A', 'F'));
    }

    private function title(Worksheet $sheet, string $range): void { $sheet->getStyle($range)->applyFromArray(['font'=>['bold'=>true,'size'=>15,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'172033']], 'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT]]); }
    private function section(Worksheet $sheet, string $range): void { $sheet->getStyle($range)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'334155']]]); }
    private function header(Worksheet $sheet, string $range): void { $sheet->getStyle($range)->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>'172033']], 'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E2E8F0']], 'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]); }
    private function finish(Worksheet $sheet, string $range): void { $sheet->getStyle($range)->applyFromArray(['borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CBD5E1']]], 'alignment'=>['vertical'=>Alignment::VERTICAL_TOP]]); }
    private function numberCols(Worksheet $sheet, array $cols): void { foreach ($cols as $c) $sheet->getStyle($c . ':' . $c)->getNumberFormat()->setFormatCode('#,##0.0000'); }
    private function autosize(Worksheet $sheet, array $cols): void { foreach ($cols as $c) $sheet->getColumnDimension($c)->setAutoSize(true); }
}
