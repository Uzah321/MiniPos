<?php

namespace Modules\Product\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClosingStockMovementSpreadsheetExport
{
    public function __construct(private readonly array $payload)
    {
    }

    public function download(string $filename): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet();

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    private function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS')
            ->setTitle('Closing Stock Movement Report')
            ->setSubject('Closing stock movements, opening stock, closing stock and variance');

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Executive Summary');
        $this->buildSummarySheet($summary);

        $dayIndex = 1;
        foreach (($this->payload['days'] ?? []) as $day) {
            $sheet = new Worksheet($spreadsheet, substr('Day ' . ($day['closing_date'] ?? $dayIndex), 0, 31));
            $spreadsheet->addSheet($sheet);
            $this->buildDaySheet($sheet, $day);
            $dayIndex++;
        }

        $movement = new Worksheet($spreadsheet, 'Movement Detail');
        $spreadsheet->addSheet($movement);
        $this->buildMovementSheet($movement);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet): void
    {
        $sheet->mergeCells('A1:L1');
        $sheet->setCellValue('A1', 'CLOSING STOCK MOVEMENT REPORT');
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->mergeCells('A2:L2');
        $sheet->setCellValue('A2', 'Generated: ' . ($this->payload['generated_at'] ?? '') . ' | Stock Area: ' . strtoupper($this->payload['stock_area'] ?? 'front'));
        $sheet->getStyle('A2')->applyFromArray($this->subtitleStyle());

        $row = 4;
        $headers = ['Closing Date', 'Lines', 'Variance Lines', 'Opening Qty', 'Receipts', 'BOH To Front', 'Sales Cons.', 'Issues', 'Adjustments', 'Expected', 'Counted', 'Variance Qty'];
        $this->writeHeader($sheet, $row, $headers);
        $row++;

        foreach (($this->payload['days'] ?? []) as $day) {
            $totals = $day['totals'] ?? [];
            $values = [
                $day['formatted_date'] ?? $day['closing_date'] ?? '',
                $day['total_lines'] ?? 0,
                $day['variance_lines'] ?? 0,
                $totals['opening_qty'] ?? 0,
                $totals['receipts_qty'] ?? 0,
                $totals['boh_to_front_qty'] ?? 0,
                $totals['sales_consumption_qty'] ?? 0,
                $totals['other_issues_qty'] ?? 0,
                $totals['adjustments_qty'] ?? 0,
                $totals['expected_closing_qty'] ?? 0,
                $totals['counted_closing_qty'] ?? 0,
                $totals['variance_qty'] ?? 0,
            ];
            $this->writeRow($sheet, $row, $values);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Variance Value by Currency');
        $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($this->sectionStyle());
        $row++;
        $this->writeHeader($sheet, $row, ['Currency', 'Variance Value']);
        $row++;

        foreach (($this->payload['currency_totals'] ?? []) as $currency => $totals) {
            $this->writeRow($sheet, $row, [$currency, $totals['variance_value'] ?? 0]);
            $row++;
        }

        $this->finalizeSheet($sheet, 'A', 'L');
    }

    private function buildDaySheet(Worksheet $sheet, array $day): void
    {
        $sheet->mergeCells('A1:O1');
        $sheet->setCellValue('A1', 'CLOSING STOCK - ' . ($day['formatted_date'] ?? $day['closing_date'] ?? ''));
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->mergeCells('A2:O2');
        $sheet->setCellValue('A2', 'Opening stock to closing count, stock movement, expected closing and variance');
        $sheet->getStyle('A2')->applyFromArray($this->subtitleStyle());

        $row = 4;
        $totals = $day['totals'] ?? [];
        $cards = [
            ['Opening', $totals['opening_qty'] ?? 0],
            ['Receipts', $totals['receipts_qty'] ?? 0],
            ['BOH To Front', $totals['boh_to_front_qty'] ?? 0],
            ['Sales Cons.', $totals['sales_consumption_qty'] ?? 0],
            ['Expected', $totals['expected_closing_qty'] ?? 0],
            ['Counted', $totals['counted_closing_qty'] ?? 0],
            ['Variance', $totals['variance_qty'] ?? 0],
        ];
        $col = 1;
        foreach ($cards as [$label, $value]) {
            $cell = Coordinate::stringFromColumnIndex($col) . $row;
            $sheet->setCellValue($cell, $label);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . ($row + 1), $value);
            $sheet->getStyle($cell . ':' . Coordinate::stringFromColumnIndex($col) . ($row + 1))->applyFromArray($this->cardStyle());
            $col += 2;
        }

        $row += 4;
        $headers = [
            'Product', 'Code/SKU', 'Type', 'UOM', 'Currency', 'Unit Cost', 'Opening', 'Receipts', 'BOH To Front', 'Sales Cons.', 'Issues', 'Adjustments', 'Expected Closing', 'Counted Closing', 'Variance', 'Variance Value', 'Closing Adjustment', 'Movements'
        ];
        $this->writeHeader($sheet, $row, $headers);
        $row++;

        foreach (($day['lines'] ?? []) as $line) {
            $values = [
                $line['product_name'] ?? '',
                $line['product_code'] ?: ($line['product_sku'] ?? ''),
                $line['product_type'] ?? '',
                $line['uom_code'] ?? '',
                $line['currency_code'] ?? '',
                $line['unit_cost'] ?? 0,
                $line['opening_qty'] ?? 0,
                $line['receipts_qty'] ?? 0,
                $line['boh_to_front_qty'] ?? 0,
                $line['sales_consumption_qty'] ?? 0,
                $line['other_issues_qty'] ?? 0,
                $line['adjustments_qty'] ?? 0,
                $line['expected_closing_qty'] ?? 0,
                $line['counted_closing_qty'] ?? null,
                $line['variance_qty'] ?? null,
                $line['variance_value'] ?? null,
                $line['closing_adjustment_qty'] ?? 0,
                $line['movement_count'] ?? 0,
            ];
            $this->writeRow($sheet, $row, $values);
            if (($line['has_variance'] ?? false) === true) {
                $sheet->getStyle("A{$row}:R{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF7ED');
                $sheet->getStyle("O{$row}:P{$row}")->getFont()->getColor()->setARGB(($line['variance_qty'] ?? 0) < 0 ? 'FFB42318' : 'FF027A48');
                $sheet->getStyle("O{$row}:P{$row}")->getFont()->setBold(true);
            }
            $row++;
        }

        $this->finalizeSheet($sheet, 'A', 'R');
    }

    private function buildMovementSheet(Worksheet $sheet): void
    {
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'STOCK MOVEMENT DETAIL');
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $row = 3;
        $headers = ['Date', 'Created At', 'Product', 'Code', 'Movement Type', 'Stock Area', 'From', 'To', 'Qty In', 'Qty Out', 'Balance After', 'Cost', 'Reference'];
        $this->writeHeader($sheet, $row, $headers);
        $row++;

        foreach (($this->payload['movement_detail'] ?? []) as $line) {
            $this->writeRow($sheet, $row, [
                $line['movement_date'] ?? '',
                $line['created_at'] ?? '',
                $line['product_name'] ?? '',
                $line['product_code'] ?? '',
                $line['movement_type'] ?? '',
                $line['stock_area'] ?? '',
                $line['from_stock_area'] ?? '',
                $line['to_stock_area'] ?? '',
                $line['quantity_in'] ?? 0,
                $line['quantity_out'] ?? 0,
                $line['balance_after'] ?? null,
                ($line['currency_code'] ?? '') . ' ' . number_format((float) ($line['total_cost'] ?? 0), 2),
                trim(($line['reference_type'] ?? '') . ' ' . ($line['reference_id'] ?? '')),
            ]);
            $row++;
        }

        $this->finalizeSheet($sheet, 'A', 'M');
    }

    private function writeHeader(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $row, $header);
        }
        $end = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$end}{$row}")->applyFromArray($this->headerStyle());
    }

    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $row, $value);
        }
    }

    private function finalizeSheet(Worksheet $sheet, string $startCol, string $endCol): void
    {
        foreach (range($startCol, $endCol) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("{$startCol}1:{$endCol}{$highestRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFE2E8F0'],
                ],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->freezePane('A4');
        $sheet->getDefaultRowDimension()->setRowHeight(20);
    }

    private function titleStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF172033']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ];
    }

    private function subtitleStyle(): array
    {
        return [
            'font' => ['size' => 10, 'color' => ['argb' => 'FF475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']],
        ];
    }

    private function sectionStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF334155']],
        ];
    }

    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF4F8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
    }

    private function cardStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF172033']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
    }
}
