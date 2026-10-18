<?php

namespace Modules\Product\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductManagementSpreadsheetExport
{
    public function __construct(private readonly array $report)
    {
    }

    public function download(string $filename): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet();

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    private function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS Enterprise')
            ->setTitle('Product Management Report')
            ->setSubject('Filtered product register and stock summary');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $headers = [
            '#', 'Product', 'Description', 'Type', 'Scope', 'Code', 'SKU', 'Barcode',
            'Category', 'Supplier', 'UOM', 'Currency', 'Selling Price', 'On Hand',
            'Reserved', 'Available', 'Average Cost', 'Stock Value', 'Reorder Level',
            'Min Stock', 'Max Stock', 'Stock Tracking', 'Batch Tracking',
            'Expiry Tracking', 'Status',
        ];
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', strtoupper($this->report['company_name']) . ' — PRODUCT MANAGEMENT REPORT');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF002B49']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', 'Generated ' . $this->report['generated_at'] . ' | Records: ' . $this->report['record_count']);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['argb' => 'FF475569']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->setCellValue('A3', 'Filters: ' . $this->report['filter_summary']);
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']],
            'alignment' => ['wrapText' => true],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(27);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '5', $header);
        }
        $sheet->getStyle("A5:{$lastColumn}5")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF009688']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD6DEE6']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(28);

        $rowNumber = 6;
        foreach ($this->report['rows'] as $row) {
            $values = [
                $row['line'], $row['name'], $row['description'], $row['type'], $row['scope'],
                $row['code'], $row['sku'], $row['barcode'], $row['category'], $row['supplier'],
                $row['uom'], $row['currency'], $row['selling_price'], $row['on_hand'],
                $row['reserved'], $row['available'], $row['average_cost'], $row['stock_value'],
                $row['reorder_level'], $row['minimum_stock'], $row['maximum_stock'],
                $row['stock_tracking'], $row['batch_tracking'], $row['expiry_tracking'], $row['status'],
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $rowNumber, $value);
            }

            $fill = $rowNumber % 2 === 0 ? 'FFF8FAFC' : 'FFFFFFFF';
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
                'font' => ['size' => 9, 'color' => ['argb' => 'FF0F2942']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fill]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFE2E8F0']]],
            ]);
            $rowNumber++;
        }

        $lastDataRow = max(5, $rowNumber - 1);
        $sheet->setAutoFilter("A5:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane('B6');
        if ($lastDataRow >= 6) {
            $sheet->getStyle("M6:U{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
            $sheet->getStyle("A6:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("V6:Y{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $widths = [7, 32, 42, 18, 22, 18, 18, 22, 24, 24, 14, 12, 16, 14, 14, 14, 16, 16, 14, 14, 14, 14, 14, 14, 12];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setRight(0.25)->setBottom(0.4)->setLeft(0.25);

        return $spreadsheet;
    }
}
