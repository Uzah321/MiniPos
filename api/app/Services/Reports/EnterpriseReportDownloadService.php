<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EnterpriseReportDownloadService
{
    public function downloadSpreadsheet(
        string $title,
        string $filename,
        array $sheets,
        bool $autoFilter = false
    ) {
        if (!class_exists(Spreadsheet::class)) {
            return response()->json([
                'message' => 'PhpSpreadsheet is not installed. Run composer require phpoffice/phpspreadsheet.',
            ], 500);
        }

        $generatedAt = now()->toDateTimeString();
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS')
            ->setTitle($title)
            ->setSubject($title)
            ->setDescription($title . ' generated from ServeNest POS.');

        foreach ($sheets as $sheetIndex => $sheetConfig) {
            $sheet = $sheetIndex === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($sheetIndex);
            $sheet->setTitle(substr((string) ($sheetConfig['title'] ?? 'Report'), 0, 31));

            $headers = array_values($sheetConfig['headers'] ?? []);
            $rows = array_values($sheetConfig['rows'] ?? []);
            $lastColumn = $this->excelColumn(max(count($headers), 1));
            $lastRow = max(count($rows) + 5, 5);

            $sheet->setCellValue('A1', $title);
            $sheet->setCellValue('A2', (string) ($sheetConfig['title'] ?? 'Report'));
            $sheet->setCellValue('A3', 'Generated: ' . $generatedAt);
            $sheet->mergeCells("A1:{$lastColumn}1");
            $sheet->mergeCells("A2:{$lastColumn}2");

            foreach ($headers as $index => $header) {
                $sheet->setCellValue($this->excelColumn($index + 1) . '5', $header);
            }

            foreach ($rows as $rowIndex => $row) {
                $targetRow = $rowIndex + 6;
                [$rowStyle, $rowValues] = $this->rowParts($row);

                if ($rowStyle === 'bracket') {
                    $sheet->mergeCells("A{$targetRow}:{$lastColumn}{$targetRow}");
                    $sheet->setCellValue("A{$targetRow}", $rowValues[0] ?? '');
                    $sheet->getRowDimension($targetRow)->setRowHeight(26);
                    $range = "A{$targetRow}:{$lastColumn}{$targetRow}";
                    $sheet->getStyle($range)->getFont()->setBold(true)->setSize(11);
                    $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
                    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00695C');
                    $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                    continue;
                }

                if ($rowStyle === 'currency') {
                    $sheet->mergeCells("A{$targetRow}:{$lastColumn}{$targetRow}");
                    $sheet->setCellValue("A{$targetRow}", $rowValues[0] ?? '');
                    $sheet->getRowDimension($targetRow)->setRowHeight(22);
                    $range = "A{$targetRow}:{$lastColumn}{$targetRow}";
                    $sheet->getStyle($range)->getFont()->setBold(true)->setSize(10);
                    $sheet->getStyle($range)->getFont()->getColor()->setARGB('FF002B49');
                    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0F2F1');
                    $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                    continue;
                }

                if ($rowStyle === 'spacer') {
                    $sheet->getRowDimension($targetRow)->setRowHeight(8);
                    continue;
                }

                foreach ($rowValues as $columnIndex => $value) {
                    $sheet->setCellValue($this->excelColumn($columnIndex + 1) . $targetRow, $value);
                }
            }

            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A5:{$lastColumn}5")->getFont()->setBold(true);
            $sheet->getStyle("A5:{$lastColumn}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF002B49');
            $sheet->getStyle("A5:{$lastColumn}5")->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD7E0E8');
            $sheet->freezePane('A6');

            if ($autoFilter) {
                $sheet->setAutoFilter("A5:{$lastColumn}{$lastRow}");
            }

            for ($index = 1; $index <= max(count($headers), 1); $index++) {
                $sheet->getColumnDimension($this->excelColumn($index))->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function downloadPdf(
        string $title,
        string $filename,
        array $sections,
        ?int $rowLimit = null,
        string|array $paper = 'a4',
        ?string $orientation = 'landscape',
        bool $compact = false,
        bool $download = true
    ) {
        if (!class_exists(Pdf::class)) {
            return response()->json([
                'message' => 'DomPDF is not installed. Run composer require barryvdh/laravel-dompdf.',
            ], 500);
        }

        $pdf = Pdf::loadHTML($this->pdfHtml($title, $sections, $rowLimit, $compact))
            ->setPaper($paper, $orientation);

        return $download
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    private function pdfHtml(string $title, array $sections, ?int $rowLimit, bool $compact = false): string
    {
        $escape = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
        $sectionHtml = '';

        foreach ($sections as $section) {
            $headers = array_values($section['headers'] ?? []);
            $rows = array_values($section['rows'] ?? []);
            if ($rowLimit !== null) {
                $rows = array_slice($rows, 0, max($rowLimit, 0));
            }

            $head = collect($headers)
                ->map(fn ($header) => '<th>' . $escape($header) . '</th>')
                ->implode('');
            $body = collect($rows)->map(function ($row) use ($escape, $headers) {
                [$rowStyle, $rowValues] = $this->rowParts($row);
                $columnCount = max(count($headers), 1);

                if ($rowStyle === 'bracket') {
                    return '<tr class="bracket-row"><td colspan="' . $columnCount . '">' . $escape($rowValues[0] ?? '') . '</td></tr>';
                }

                if ($rowStyle === 'currency') {
                    return '<tr class="currency-row"><td colspan="' . $columnCount . '">' . $escape($rowValues[0] ?? '') . '</td></tr>';
                }

                if ($rowStyle === 'spacer') {
                    return '<tr class="spacer-row"><td colspan="' . $columnCount . '"></td></tr>';
                }

                return '<tr>' . collect($rowValues)
                    ->map(fn ($value) => '<td>' . $escape($value) . '</td>')
                    ->implode('') . '</tr>';
            })->implode('');

            if ($body === '') {
                $body = '<tr><td colspan="' . max(count($headers), 1) . '" class="empty">No records found.</td></tr>';
            }

            $sectionHtml .= '<section><h2>' . $escape($section['title'] ?? '') . '</h2><table><thead><tr>'
                . $head . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
        }

        $compactCss = $compact ? '
            @page{margin:8px;} body{font-size:7px;} .cover{padding:8px;margin-bottom:7px;} h1{font-size:13px;letter-spacing:0;} h2{font-size:9px;margin:7px 0 4px;} .meta{font-size:6px;} th{font-size:6px;padding:3px 2px;} td{font-size:6.5px;padding:3px 2px;word-break:break-word;white-space:pre-line;} .bracket-row td{font-size:8px;padding:5px;} .currency-row td{font-size:7px;padding:4px;}
        ' : '';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            @page{margin:18px;} body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:9.5px;background:#fff;}
            .cover{border:1px solid #002B49;background:#EEF3F7;padding:14px;margin-bottom:12px;}
            h1{margin:0;color:#002B49;font-size:22px;text-transform:uppercase;letter-spacing:.04em;} h2{margin:12px 0 6px;color:#002B49;font-size:13px;text-transform:uppercase;}
            .meta{margin-top:4px;color:#5D6B78;font-size:9px;} table{width:100%;border-collapse:collapse;table-layout:auto;margin-bottom:10px;}
            th{background:#002B49;color:#fff;text-align:left;font-weight:700;text-transform:uppercase;font-size:8px;padding:5px;border:1px solid #0F3B5A;}
            td{padding:4px;border:1px solid #D7E0E8;vertical-align:top;} tr:nth-child(even) td{background:#F8FAFC;}
            .empty{text-align:center;color:#667085;padding:15px;} .bracket-row td{background:#00695C!important;color:#fff;font-weight:700;font-size:11px;padding:9px;border:1px solid #004D40;} .currency-row td{background:#E0F2F1!important;color:#002B49;font-weight:700;font-size:10px;padding:7px;border:1px solid #80CBC4;} .spacer-row td{height:8px;background:#fff!important;border:none;}
            ' . $compactCss . '
        </style></head><body><div class="cover"><h1>' . $escape($title) . '</h1><div class="meta">Generated '
            . $escape(now()->toDateTimeString()) . ' &middot; ServeNest POS Enterprise Reports</div></div>'
            . $sectionHtml . '</body></html>';
    }

    private function rowParts($row): array
    {
        if (is_object($row)) {
            $row = get_object_vars($row);
        }

        if (!is_array($row)) {
            return ['normal', [$row]];
        }

        $style = (string) ($row['__style'] ?? 'normal');
        $values = array_key_exists('values', $row) ? $row['values'] : $row;

        if (is_object($values)) {
            $values = get_object_vars($values);
        }

        return [$style, array_values(is_array($values) ? $values : [$values])];
    }

    private function excelColumn(int $index): string
    {
        $column = '';
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)) . $column;
            $index = intdiv($index, 26);
        }

        return $column ?: 'A';
    }
}
