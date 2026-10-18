<?php

namespace Modules\ZimraIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Services\Zimra\EnterpriseTaxReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EnterpriseTaxReportController extends Controller
{
    public function __construct(
        private EnterpriseTaxReportService $service,
        private EnterpriseReportDownloadService $reportDownloads,
        private BusinessModeBranchScopeService $branchScope
    ) {
    }

    /**
     * GET /api/pos/reports/tax/summary
     */
    public function summary(Request $request)
    {
        $request = $this->scopedRequest($request);

        return response()->json(
            $this->service->summary($request)
        );
    }

    /**
     * GET /api/pos/reports/tax/by-tax
     */
    public function byTax(Request $request)
    {
        $request = $this->scopedRequest($request);

        return response()->json(
            $this->service->byTax($request)
        );
    }

    /**
     * GET /api/pos/reports/tax/details
     */
    public function details(Request $request)
    {
        $request = $this->scopedRequest($request);

        return response()->json(
            $this->service->taxLineDetail($request)
        );
    }

    /**
     * GET /api/pos/reports/tax/zimra-receipts
     */
    public function zimraRegister(Request $request)
    {
        $request = $this->scopedRequest($request);

        return response()->json(
            $this->service->zimraRegister($request)
        );
    }

    /**
     * GET /api/pos/reports/tax/zimra-receipts/{receiptId}
     */
    public function zimraReceiptDetail(Request $request, string $receiptId)
    {
        $request = $this->scopedRequest($request);

        return response()->json(
            $this->service->zimraReceiptDetail($request, $receiptId)
        );
    }

    public function download(Request $request, string $format)
    {
        $validator = Validator::make(
            [...$request->all(), 'format' => strtolower($format)],
            [
                'format' => ['required', Rule::in(['pdf', 'xlsx', 'csv'])],
                'report' => ['nullable', Rule::in(['overview', 'tax', 'lines', 'zimra'])],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $request = $this->scopedRequest($request);
        $report = (string) $request->input('report', 'overview');
        $definition = $this->exportDefinition($request, $report);
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo = $request->input('date_to', $dateFrom);
        $baseFilename = sprintf('enterprise-tax-%s-%s-to-%s', $report, $dateFrom, $dateTo);

        if (strtolower($format) === 'pdf') {
            return $this->reportDownloads->downloadPdf(
                $definition['title'],
                $baseFilename . '.pdf',
                $definition['sections']
            );
        }

        if (strtolower($format) === 'xlsx') {
            $sheets = collect($definition['sections'])->map(fn ($section) => [
                'title' => $section['title'],
                'headers' => $section['headers'],
                'rows' => $section['rows'],
            ])->all();

            return $this->reportDownloads->downloadSpreadsheet(
                $definition['title'],
                $baseFilename . '.xlsx',
                $sheets,
                true
            );
        }

        return $this->downloadCsv($baseFilename . '.csv', $definition['sections']);
    }

    private function exportDefinition(Request $request, string $report): array
    {
        $taxHeaders = ['Tax Code', 'Rate', 'Currency', 'Mode', 'ZIMRA', 'Receipts', 'Lines', 'Sales With Tax', 'Tax Amount'];
        $taxRows = [];

        if (in_array($report, ['overview', 'tax'], true)) {
            $taxPayload = $this->service->byTax($request)['data'];
            $taxRows = collect($taxPayload['rows'] ?? [])->map(fn ($row) => [
                data_get($row, 'tax_code') ?: 'N/A',
                number_format((float) data_get($row, 'tax_percent', 0), 2, '.', '') . '%',
                strtoupper((string) (data_get($row, 'currency_code') ?: 'N/A')),
                data_get($row, 'is_inclusive') ? 'Inclusive' : 'Exclusive',
                data_get($row, 'obliged_to_zimra') ? 'Yes' : 'No',
                (int) data_get($row, 'receipt_count', 0),
                (int) data_get($row, 'line_count', 0),
                number_format((float) data_get($row, 'sales_amount_with_tax', 0), 2, '.', ''),
                number_format((float) data_get($row, 'tax_amount', 0), 2, '.', ''),
            ])->all();
        }

        if ($report === 'tax') {
            return [
                'title' => 'Enterprise Tax Summary',
                'sections' => [[
                    'title' => 'Tax Summary',
                    'headers' => $taxHeaders,
                    'rows' => $taxRows,
                ]],
            ];
        }

        if ($report === 'lines') {
            $rows = collect($this->service->exportRows($request))->map(fn ($row) => [
                data_get($row, 'Date'),
                data_get($row, 'Branch'),
                data_get($row, 'Receipt'),
                data_get($row, 'Type'),
                data_get($row, 'Line'),
                data_get($row, 'Product'),
                data_get($row, 'ProductCode'),
                data_get($row, 'TaxCode'),
                data_get($row, 'TaxPercent'),
                data_get($row, 'TaxMode'),
                data_get($row, 'ZimraObliged'),
                data_get($row, 'SalesAmountWithTax'),
                data_get($row, 'TaxAmount'),
                data_get($row, 'Currency'),
                data_get($row, 'Cashier'),
            ])->all();

            return [
                'title' => 'Enterprise Tax Line Detail',
                'sections' => [[
                    'title' => 'Tax Lines',
                    'headers' => ['Date', 'Branch', 'Receipt', 'Type', 'Line', 'Product', 'Product Code', 'Tax Code', 'Rate', 'Mode', 'ZIMRA', 'Sales With Tax', 'Tax Amount', 'Currency', 'Cashier'],
                    'rows' => $rows,
                ]],
            ];
        }

        if ($report === 'zimra') {
            $rows = collect($this->service->zimraExportRows($request))->map(fn ($row) => [
                data_get($row, 'Date'),
                data_get($row, 'Branch'),
                data_get($row, 'Invoice'),
                data_get($row, 'ReceiptType'),
                data_get($row, 'Currency'),
                data_get($row, 'Status'),
                data_get($row, 'ReceiptCounter'),
                data_get($row, 'GlobalNumber'),
                data_get($row, 'FdmsId'),
                data_get($row, 'TotalAmount'),
                data_get($row, 'TaxAmount'),
                data_get($row, 'DiscountAmount'),
                data_get($row, 'Cashier'),
                data_get($row, 'Error'),
            ])->all();

            return [
                'title' => 'ZIMRA Receipt Register',
                'sections' => [[
                    'title' => 'ZIMRA Receipts',
                    'headers' => ['Date', 'Branch', 'Invoice', 'Type', 'Currency', 'Status', 'Counter', 'Global No', 'FDMS ID', 'Total', 'Tax', 'Discount', 'Cashier', 'Error'],
                    'rows' => $rows,
                ]],
            ];
        }

        $summary = $this->service->summary($request)['data'];
        $totals = $summary['totals'] ?? [];

        return [
            'title' => 'Enterprise Tax Overview',
            'sections' => [
                [
                    'title' => 'Report Totals',
                    'headers' => ['Metric', 'Value', 'Metric', 'Value'],
                    'rows' => [
                        ['Receipts', (int) ($totals['receipt_count'] ?? 0), 'Tax Lines', (int) ($totals['tax_line_count'] ?? 0)],
                        ['Subtotal', $this->amount($totals['subtotal_amount'] ?? 0), 'Discount', $this->amount($totals['discount_amount'] ?? 0)],
                        ['Total Sales', $this->amount($totals['total_amount'] ?? 0), 'Total Tax', $this->amount($totals['tax_amount'] ?? 0)],
                        ['ZIMRA Tax', $this->amount($totals['zimra_tax_amount'] ?? 0), 'Inclusive Tax', $this->amount($totals['inclusive_tax_amount'] ?? 0)],
                        ['Exclusive Tax', $this->amount($totals['exclusive_tax_amount'] ?? 0), 'Credit Notes', (int) ($totals['credit_note_count'] ?? 0)],
                        ['Credit Note Total', $this->amount($totals['credit_note_total'] ?? 0), 'Credit Note Tax', $this->amount($totals['credit_note_tax'] ?? 0)],
                    ],
                ],
                [
                    'title' => 'Tax Summary',
                    'headers' => $taxHeaders,
                    'rows' => $taxRows,
                ],
            ],
        ];
    }

    private function scopedRequest(Request $request): Request
    {
        $company = app('company');
        $requested = $request->input('branch_ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        $requested = is_array($requested) ? $requested : [];
        if ($request->filled('branch_id')) {
            $requested[] = $request->input('branch_id');
        }

        $scope = $this->branchScope->resolve($request, (string) $company->id, $requested);
        $request->merge([
            'branch_ids' => $scope['branch_ids'],
            'business_mode' => $scope['business_mode'],
        ]);

        return $request;
    }

    private function downloadCsv(string $filename, array $sections)
    {
        return response()->streamDownload(function () use ($sections) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");

            foreach ($sections as $index => $section) {
                if ($index > 0) fputcsv($stream, []);
                fputcsv($stream, [(string) ($section['title'] ?? 'Report')]);
                fputcsv($stream, array_values($section['headers'] ?? []));
                foreach ($section['rows'] ?? [] as $row) {
                    fputcsv($stream, array_values(is_array($row) ? $row : get_object_vars($row)));
                }
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function amount($value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
