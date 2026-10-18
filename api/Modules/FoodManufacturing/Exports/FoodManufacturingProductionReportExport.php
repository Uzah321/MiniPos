<?php

namespace Modules\FoodManufacturing\Exports;

use Carbon\Carbon;

class FoodManufacturingProductionReportExport
{
    public static function downloadExcel(array $payload, string $filename)
    {
        return response(self::buildProductionExcelXml($payload), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    public static function downloadPdf(array $payload, string $filename)
    {
        $html = self::buildProductionPdfHtml($payload);
        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename);
        }
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    public static function downloadPlanningExcel(array $payload, string $filename)
    {
        return response(self::buildPlanningExcelXml($payload), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    public static function downloadPlanningPdf(array $payload, string $filename)
    {
        $html = self::buildPlanningPdfHtml($payload);
        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename);
        }
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    public static function downloadSavedPlansExcel(array $payload, string $filename)
    {
        return response(self::buildSavedPlansExcelXml($payload), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    public static function downloadSavedPlansPdf(array $payload, string $filename)
    {
        $html = self::buildSavedPlansPdfHtml($payload);
        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    private static function workbook(array $sheets): string
    {
        return '<?xml version="1.0"?>' . "\n" .
            '<?mso-application progid="Excel.Sheet"?>' . "\n" .
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
            '<Styles>' .
            '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="15" ss:Color="#111827"/><Interior ss:Color="#EAF1F7" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2F3B4A" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Label"><Font ss:Bold="1" ss:Color="#475467"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Money"><NumberFormat ss:Format="#,##0.0000"/></Style>' .
            '</Styles>' . implode('', $sheets) . '</Workbook>';
    }

    private static function sheet(string $name, string $rows): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<Worksheet ss:Name="' . $e($name) . '"><Table>' . $rows . '</Table></Worksheet>';
    }

    private static function cell($value, string $style = '', ?string $type = null, int $mergeAcross = 0): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $type = $type ?: (is_numeric($value) ? 'Number' : 'String');
        $styleAttr = $style ? ' ss:StyleID="' . $style . '"' : '';
        $mergeAttr = $mergeAcross ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
        return '<Cell' . $styleAttr . $mergeAttr . '><Data ss:Type="' . $type . '">' . $e($value) . '</Data></Cell>';
    }

    private static function row(array $cells): string
    {
        return '<Row>' . implode('', $cells) . '</Row>';
    }

    public static function buildProductionExcelXml(array $payload): string
    {
        $m = fn ($v) => number_format((float) $v, 4, '.', '');
        $summary = $payload['summary'] ?? [];
        $batches = $payload['batches'] ?? [];
        $lines = $payload['lines'] ?? [];
        $plans = $payload['plans'] ?? [];

        $summaryRows = self::row([self::cell('Food Production Report', 'Title', 'String', 8)]);
        $summaryRows .= self::row([self::cell('Batches', 'Header'), self::cell('Plans', 'Header'), self::cell('Planned Qty', 'Header'), self::cell('Actual Qty', 'Header'), self::cell('Variance', 'Header'), self::cell('Total Cost', 'Header'), self::cell('Avg Unit Cost', 'Header')]);
        $summaryRows .= self::row([self::cell($summary['batch_count'] ?? 0), self::cell($summary['plan_count'] ?? 0), self::cell($m($summary['planned_yield'] ?? 0), 'Money'), self::cell($m($summary['actual_yield'] ?? 0), 'Money'), self::cell($m($summary['yield_variance'] ?? 0), 'Money'), self::cell($m($summary['total_cost'] ?? 0), 'Money'), self::cell($m($summary['unit_cost_average'] ?? 0), 'Money')]);

        $batchRows = self::row([self::cell('Production Register', 'Title', 'String', 11)]);
        $batchRows .= self::row([self::cell('#', 'Header'), self::cell('Batch', 'Header'), self::cell('Date', 'Header'), self::cell('Recipe', 'Header'), self::cell('Produced Item', 'Header'), self::cell('Source Area', 'Header'), self::cell('Output Area', 'Header'), self::cell('Planned', 'Header'), self::cell('Actual', 'Header'), self::cell('Total Cost', 'Header'), self::cell('Unit Cost', 'Header'), self::cell('Currency', 'Header')]);
        foreach ($batches as $i => $batch) {
            $batchRows .= self::row([self::cell($i + 1), self::cell($batch['batch_number'] ?? $batch['id'] ?? '-'), self::cell($batch['created_at'] ?? ''), self::cell($batch['recipe_name'] ?? '-'), self::cell($batch['produced_product_name'] ?? '-'), self::cell($batch['source_stock_area'] ?? '-'), self::cell($batch['destination_stock_area'] ?? '-'), self::cell($m($batch['planned_yield_quantity'] ?? 0), 'Money'), self::cell($m($batch['actual_yield_quantity'] ?? 0), 'Money'), self::cell($m($batch['total_cost'] ?? 0), 'Money'), self::cell($m($batch['unit_cost'] ?? 0), 'Money'), self::cell($batch['currency'] ?? 'USD')]);
        }

        $lineRows = self::row([self::cell('Material Consumption', 'Title', 'String', 10)]);
        $lineRows .= self::row([self::cell('#', 'Header'), self::cell('Batch', 'Header'), self::cell('Recipe', 'Header'), self::cell('Ingredient', 'Header'), self::cell('Area', 'Header'), self::cell('Net Qty', 'Header'), self::cell('Wastage %', 'Header'), self::cell('Gross Qty', 'Header'), self::cell('Unit Cost', 'Header'), self::cell('Line Cost', 'Header'), self::cell('Currency', 'Header')]);
        foreach ($lines as $i => $line) {
            $lineRows .= self::row([self::cell($i + 1), self::cell($line['batch_number'] ?? '-'), self::cell($line['recipe_name'] ?? '-'), self::cell($line['ingredient_name'] ?? '-'), self::cell($line['stock_area'] ?? '-'), self::cell($m($line['net_quantity'] ?? 0), 'Money'), self::cell($m($line['wastage_percent'] ?? 0), 'Money'), self::cell($m($line['gross_quantity'] ?? 0), 'Money'), self::cell($m($line['unit_cost'] ?? 0), 'Money'), self::cell($m($line['line_cost'] ?? 0), 'Money'), self::cell($line['currency'] ?? 'USD')]);
        }

        $planRows = self::row([self::cell('Saved Production Plans', 'Title', 'String', 9)]);
        $planRows .= self::row([self::cell('#', 'Header'), self::cell('Plan', 'Header'), self::cell('Date', 'Header'), self::cell('Recipe', 'Header'), self::cell('Produced Item', 'Header'), self::cell('Planned Qty', 'Header'), self::cell('Areas', 'Header'), self::cell('Status', 'Header'), self::cell('Short Lines', 'Header'), self::cell('Short Value', 'Header')]);
        foreach ($plans as $i => $plan) {
            $planRows .= self::row([self::cell($i + 1), self::cell($plan['plan_number'] ?? '-'), self::cell($plan['created_at'] ?? ''), self::cell($plan['recipe_name'] ?? '-'), self::cell($plan['produced_product_name'] ?? '-'), self::cell($m($plan['planned_quantity'] ?? 0), 'Money'), self::cell(($plan['source_stock_area'] ?? '-') . ' -> ' . ($plan['destination_stock_area'] ?? '-')), self::cell($plan['status'] ?? '-'), self::cell($plan['shortage_line_count'] ?? 0), self::cell($m($plan['shortage_value'] ?? 0), 'Money')]);
        }

        return self::workbook([
            self::sheet('Summary', $summaryRows),
            self::sheet('Production Register', $batchRows),
            self::sheet('Material Consumption', $lineRows),
            self::sheet('Saved Plans', $planRows),
        ]);
    }

    public static function buildPlanningExcelXml(array $payload): string
    {
        $m = fn ($v) => number_format((float) $v, 4, '.', '');
        $summary = $payload['summary'] ?? [];
        $recipe = $payload['recipe'] ?? [];
        $lines = $payload['lines'] ?? [];

        $rows = self::row([self::cell('Production Material Plan', 'Title', 'String', 8)]);
        $rows .= self::row([self::cell('Recipe', 'Label'), self::cell($recipe['name'] ?? '-'), self::cell('Planned Qty', 'Label'), self::cell($m($summary['planned_quantity'] ?? 0), 'Money'), self::cell('Can Produce', 'Label'), self::cell(($summary['can_produce'] ?? false) ? 'YES' : 'NO')]);
        $rows .= self::row([self::cell('#', 'Header'), self::cell('Ingredient', 'Header'), self::cell('UOM', 'Header'), self::cell('Source Area', 'Header'), self::cell('Required Qty', 'Header'), self::cell('Available Qty', 'Header'), self::cell('Shortage Qty', 'Header'), self::cell('Unit Cost', 'Header'), self::cell('Shortage Value', 'Header')]);
        foreach ($lines as $i => $line) {
            $rows .= self::row([self::cell($i + 1), self::cell($line['ingredient_name'] ?? '-'), self::cell($line['uom'] ?? 'EACH'), self::cell($line['source_stock_area'] ?? '-'), self::cell($m($line['required_quantity'] ?? 0), 'Money'), self::cell($m($line['available_quantity'] ?? 0), 'Money'), self::cell($m($line['shortage_quantity'] ?? 0), 'Money'), self::cell($m($line['unit_cost'] ?? 0), 'Money'), self::cell($m($line['shortage_value'] ?? 0), 'Money')]);
        }
        return self::workbook([self::sheet('Material Plan', $rows)]);
    }

    public static function buildSavedPlansExcelXml(array $payload): string
    {
        $m = fn ($value) => number_format((float) $value, 4, '.', '');
        $summary = $payload['summary'] ?? [];
        $plans = $payload['plans'] ?? [];

        $summaryRows = self::row([self::cell('Saved Production Plans', 'Title', 'String', 7)]);
        $summaryRows .= self::row([
            self::cell('Plans', 'Header'),
            self::cell('Ready', 'Header'),
            self::cell('Shortage', 'Header'),
            self::cell('Produced', 'Header'),
            self::cell('Planned Qty', 'Header'),
            self::cell('Required Value', 'Header'),
            self::cell('Shortage Value', 'Header'),
            self::cell('Short Lines', 'Header'),
        ]);
        $summaryRows .= self::row([
            self::cell($summary['plan_count'] ?? count($plans)),
            self::cell($summary['ready_count'] ?? 0),
            self::cell($summary['shortage_count'] ?? 0),
            self::cell($summary['produced_count'] ?? 0),
            self::cell($m($summary['planned_quantity'] ?? 0), 'Money'),
            self::cell($m($summary['required_value'] ?? 0), 'Money'),
            self::cell($m($summary['shortage_value'] ?? 0), 'Money'),
            self::cell($summary['shortage_line_count'] ?? 0),
        ]);

        $planRows = self::row([self::cell('Production Plan Register', 'Title', 'String', 14)]);
        $planRows .= self::row([
            self::cell('#', 'Header'),
            self::cell('Plan', 'Header'),
            self::cell('Created', 'Header'),
            self::cell('Planned For', 'Header'),
            self::cell('Branch', 'Header'),
            self::cell('Recipe', 'Header'),
            self::cell('Produced Item', 'Header'),
            self::cell('Source Area', 'Header'),
            self::cell('Output Area', 'Header'),
            self::cell('Planned Qty', 'Header'),
            self::cell('Actual Qty', 'Header'),
            self::cell('Status', 'Header'),
            self::cell('Can Produce', 'Header'),
            self::cell('Required Value', 'Header'),
            self::cell('Shortage Value', 'Header'),
        ]);

        foreach ($plans as $index => $plan) {
            $planRows .= self::row([
                self::cell($index + 1),
                self::cell($plan['plan_number'] ?? $plan['id'] ?? '-'),
                self::cell($plan['created_at'] ?? ''),
                self::cell($plan['planned_for'] ?? ''),
                self::cell($plan['branch_name'] ?? '-'),
                self::cell($plan['recipe_name'] ?? '-'),
                self::cell($plan['produced_product_name'] ?? '-'),
                self::cell($plan['source_stock_area'] ?? '-'),
                self::cell($plan['destination_stock_area'] ?? '-'),
                self::cell($m($plan['planned_quantity'] ?? 0), 'Money'),
                self::cell($m($plan['actual_quantity'] ?? 0), 'Money'),
                self::cell($plan['status'] ?? '-'),
                self::cell(($plan['can_produce'] ?? false) ? 'YES' : 'NO'),
                self::cell($m($plan['required_value'] ?? 0), 'Money'),
                self::cell($m($plan['shortage_value'] ?? 0), 'Money'),
            ]);
        }

        return self::workbook([
            self::sheet('Summary', $summaryRows),
            self::sheet('Saved Plans', $planRows),
        ]);
    }

    public static function buildProductionPdfHtml(array $payload): string
    {
        return self::simpleHtml('Food Production Report', $payload['summary'] ?? [], $payload['batches'] ?? [], $payload['lines'] ?? [], $payload['plans'] ?? []);
    }

    public static function buildPlanningPdfHtml(array $payload): string
    {
        return self::simpleHtml('Production Material Plan', $payload['summary'] ?? [], [], $payload['lines'] ?? [], []);
    }

    public static function buildSavedPlansPdfHtml(array $payload): string
    {
        $escape = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
        $number = fn ($value) => number_format((float) $value, 4);
        $summary = $payload['summary'] ?? [];
        $plans = $payload['plans'] ?? [];
        $rows = '';

        foreach ($plans as $index => $plan) {
            $rows .= '<tr>'
                . '<td>' . ($index + 1) . '</td>'
                . '<td>' . $escape($plan['plan_number'] ?? $plan['id'] ?? '-') . '</td>'
                . '<td>' . $escape($plan['planned_for'] ?? '-') . '</td>'
                . '<td>' . $escape($plan['branch_name'] ?? '-') . '</td>'
                . '<td>' . $escape($plan['recipe_name'] ?? '-') . '</td>'
                . '<td>' . $escape($plan['produced_product_name'] ?? '-') . '</td>'
                . '<td>' . $escape(($plan['source_stock_area'] ?? '-') . ' -> ' . ($plan['destination_stock_area'] ?? '-')) . '</td>'
                . '<td class="num">' . $number($plan['planned_quantity'] ?? 0) . '</td>'
                . '<td>' . $escape($plan['status'] ?? '-') . '</td>'
                . '<td class="num">' . $number($plan['required_value'] ?? 0) . '</td>'
                . '<td class="num">' . $number($plan['shortage_value'] ?? 0) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="11" class="empty">No saved production plans found.</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page{margin:12px}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:8px;color:#111827}'
            . 'h1{font-size:16px;margin:0 0 4px}.meta{color:#667085;margin-bottom:8px}'
            . '.cards{display:table;width:100%;border-spacing:5px}.card{display:table-cell;border:1px solid #cbd5e1;background:#f8fafc;padding:6px}'
            . '.label{font-size:7px;color:#667085;text-transform:uppercase}.value{font-size:12px;font-weight:700}'
            . 'table{width:100%;border-collapse:collapse;margin-top:8px}th{background:#2f3b4a;color:#fff}'
            . 'th,td{border:1px solid #d0d7e2;padding:4px;vertical-align:top}.num{text-align:right}.empty{text-align:center;color:#667085}'
            . '</style></head><body><h1>Saved Production Plans</h1>'
            . '<div class="meta">Generated: ' . $escape(Carbon::now()->format('Y-m-d H:i:s')) . '</div>'
            . '<div class="cards"><div class="card"><div class="label">Plans</div><div class="value">' . $escape($summary['plan_count'] ?? count($plans)) . '</div></div>'
            . '<div class="card"><div class="label">Ready</div><div class="value">' . $escape($summary['ready_count'] ?? 0) . '</div></div>'
            . '<div class="card"><div class="label">Planned Qty</div><div class="value">' . $number($summary['planned_quantity'] ?? 0) . '</div></div>'
            . '<div class="card"><div class="label">Shortage Value</div><div class="value">' . $number($summary['shortage_value'] ?? 0) . '</div></div></div>'
            . '<table><thead><tr><th>#</th><th>Plan</th><th>Planned For</th><th>Branch</th><th>Recipe</th><th>Produced Item</th><th>Areas</th><th>Planned Qty</th><th>Status</th><th>Required Value</th><th>Shortage Value</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></body></html>';
    }

    private static function simpleHtml(string $title, array $summary, array $batches, array $lines, array $plans): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $num = fn ($v) => number_format((float) $v, 4);
        $rows = '';
        foreach ($lines as $i => $line) {
            $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($line['ingredient_name'] ?? $line['recipe_name'] ?? '-') . '</td><td class="num">' . $num($line['gross_quantity'] ?? $line['required_quantity'] ?? 0) . '</td><td class="num">' . $num($line['available_quantity'] ?? 0) . '</td><td class="num">' . $num($line['shortage_quantity'] ?? 0) . '</td><td class="num">' . $num($line['line_cost'] ?? $line['shortage_value'] ?? 0) . '</td></tr>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:9px;color:#111827}h1{font-size:16px;margin:0 0 6px}.cards{display:table;width:100%;border-spacing:6px}.card{display:table-cell;border:1px solid #cbd5e1;background:#f8fafc;padding:7px}.label{font-size:7px;color:#667085;text-transform:uppercase}.value{font-size:13px;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:8px}th{background:#2f3b4a;color:#fff}th,td{border:1px solid #d0d7e2;padding:4px}.num{text-align:right}</style></head><body><h1>' . $e($title) . '</h1><div>Generated: ' . $e(Carbon::now()->format('Y-m-d H:i:s')) . '</div><div class="cards"><div class="card"><div class="label">Planned</div><div class="value">' . $num($summary['planned_yield'] ?? $summary['planned_quantity'] ?? 0) . '</div></div><div class="card"><div class="label">Actual</div><div class="value">' . $num($summary['actual_yield'] ?? 0) . '</div></div><div class="card"><div class="label">Short Lines</div><div class="value">' . $e($summary['shortage_line_count'] ?? 0) . '</div></div><div class="card"><div class="label">Short Value</div><div class="value">' . $num($summary['shortage_value'] ?? 0) . '</div></div></div><table><thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Available</th><th>Shortage</th><th>Value</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
    }
}
