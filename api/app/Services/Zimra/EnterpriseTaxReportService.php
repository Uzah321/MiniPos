<?php

namespace App\Services\Zimra;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnterpriseTaxReportService
{
    /**
     * Dashboard totals for the tax report.
     */
    public function summary(Request $request): array
    {
        $company = app('company');

        [$dateFrom, $dateTo] = $this->dateRange($request);

        $taxQuery = $this->baseTaxQuery($company->id, $dateFrom, $dateTo, $request);

        $taxTotals = (clone $taxQuery)
            ->selectRaw('
                COUNT(*) as tax_line_count,
                COUNT(DISTINCT receipt_line_taxes.sales_transaction_id) as receipt_count,
                COALESCE(SUM(receipt_line_taxes.sales_amount_with_tax), 0) as sales_amount_with_tax,
                COALESCE(SUM(receipt_line_taxes.tax_amount), 0) as tax_amount,
                COALESCE(SUM(CASE WHEN receipt_line_taxes.obliged_to_zimra = 1 THEN receipt_line_taxes.tax_amount ELSE 0 END), 0) as zimra_tax_amount,
                COALESCE(SUM(CASE WHEN receipt_line_taxes.is_inclusive = 1 THEN receipt_line_taxes.tax_amount ELSE 0 END), 0) as inclusive_tax_amount,
                COALESCE(SUM(CASE WHEN receipt_line_taxes.is_inclusive = 0 THEN receipt_line_taxes.tax_amount ELSE 0 END), 0) as exclusive_tax_amount
            ')
            ->first();

        $salesQuery = $this->baseSalesQuery($company->id, $dateFrom, $dateTo, $request);

        $salesTotals = (clone $salesQuery)
            ->selectRaw('
                COUNT(*) as sales_count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(subtotal_amount), 0) as subtotal_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(tax_amount), 0) as sales_tax_amount
            ')
            ->first();

        $creditNoteTotals = (clone $salesQuery)
            ->where(function ($query) {
                $query->where('sales_transactions.sale_type', 'sale_cancel')
                    ->orWhere('sales_transactions.document_type', 'cancel_receipt');
            })
            ->selectRaw('
                COUNT(*) as credit_note_count,
                COALESCE(SUM(total_amount), 0) as credit_note_total,
                COALESCE(SUM(tax_amount), 0) as credit_note_tax
            ')
            ->first();

        return [
            'message' => 'Tax summary fetched successfully.',
            'data' => [
                'filters' => $this->filters($request, $dateFrom, $dateTo),
                'totals' => [
                    'receipt_count' => (int) ($taxTotals->receipt_count ?? 0),
                    'sales_count' => (int) ($salesTotals->sales_count ?? 0),
                    'tax_line_count' => (int) ($taxTotals->tax_line_count ?? 0),

                    'subtotal_amount' => round((float) ($salesTotals->subtotal_amount ?? 0), 4),
                    'discount_amount' => round((float) ($salesTotals->discount_amount ?? 0), 4),
                    'total_amount' => round((float) ($salesTotals->total_amount ?? 0), 4),

                    'sales_amount_with_tax' => round((float) ($taxTotals->sales_amount_with_tax ?? 0), 4),
                    'tax_amount' => round((float) ($taxTotals->tax_amount ?? 0), 4),
                    'zimra_tax_amount' => round((float) ($taxTotals->zimra_tax_amount ?? 0), 4),
                    'inclusive_tax_amount' => round((float) ($taxTotals->inclusive_tax_amount ?? 0), 4),
                    'exclusive_tax_amount' => round((float) ($taxTotals->exclusive_tax_amount ?? 0), 4),

                    'credit_note_count' => (int) ($creditNoteTotals->credit_note_count ?? 0),
                    'credit_note_total' => round((float) ($creditNoteTotals->credit_note_total ?? 0), 4),
                    'credit_note_tax' => round((float) ($creditNoteTotals->credit_note_tax ?? 0), 4),
                ],
            ],
        ];
    }

    /**
     * Grouped tax report.
     */
    public function byTax(Request $request): array
    {
        $company = app('company');

        [$dateFrom, $dateTo] = $this->dateRange($request);

        $query = $this->baseTaxQuery($company->id, $dateFrom, $dateTo, $request);

        $rows = $query
            ->selectRaw('
                COALESCE(receipt_line_taxes.tax_code, "N/A") as tax_code,
                COALESCE(receipt_line_taxes.tax_percent, 0) as tax_percent,
                COALESCE(receipt_line_taxes.currency_code, sales_transactions.currency_code, "USD") as currency_code,
                MAX(COALESCE(receipt_line_taxes.obliged_to_zimra, 0)) as obliged_to_zimra,
                MAX(COALESCE(receipt_line_taxes.is_inclusive, 0)) as is_inclusive,
                COUNT(*) as line_count,
                COUNT(DISTINCT sales_transactions.id) as receipt_count,
                COALESCE(SUM(receipt_line_taxes.sales_amount_with_tax), 0) as sales_amount_with_tax,
                COALESCE(SUM(receipt_line_taxes.tax_amount), 0) as tax_amount
            ')
            ->groupByRaw('
                COALESCE(receipt_line_taxes.tax_code, "N/A"),
                COALESCE(receipt_line_taxes.tax_percent, 0),
                COALESCE(receipt_line_taxes.currency_code, sales_transactions.currency_code, "USD")
            ')
            ->orderByRaw('COALESCE(receipt_line_taxes.tax_code, "N/A") asc')
            ->get();

        return [
            'message' => 'Tax breakdown fetched successfully.',
            'data' => [
                'filters' => $this->filters($request, $dateFrom, $dateTo),
                'totals' => [
                    'line_count' => (int) $rows->sum('line_count'),
                    'receipt_count' => (int) $rows->sum('receipt_count'),
                    'sales_amount_with_tax' => round((float) $rows->sum('sales_amount_with_tax'), 4),
                    'tax_amount' => round((float) $rows->sum('tax_amount'), 4),
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Detailed tax line report.
     *
     * IMPORTANT:
     * Your sale item table is receipt_lines, not sales_transaction_lines.
     */
    public function taxLineDetail(Request $request): array
    {
        $company = app('company');

        [$dateFrom, $dateTo] = $this->dateRange($request);

        $perPage = max(1, min((int) ($request->per_page ?: 25), 250));

        $query = $this->baseTaxQuery($company->id, $dateFrom, $dateTo, $request);

        if ($request->filled('q')) {
            $term = trim($request->q);

            $query->where(function ($sub) use ($term) {
                $sub->where('sales_transactions.invoice_number', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.customer_name', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.line_name', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.product_code', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.sku', 'like', "%{$term}%")
                    ->orWhere('receipt_line_taxes.tax_code', 'like', "%{$term}%");
            });
        }

        $rows = $query
            ->select([
                'receipt_line_taxes.id',
                'receipt_line_taxes.company_id',
                'receipt_line_taxes.branch_id',
                'receipt_line_taxes.sales_transaction_id',
                'receipt_line_taxes.receipt_line_id',
                'receipt_line_taxes.tax_id',
                'receipt_line_taxes.tax_code',
                'receipt_line_taxes.tax_percent',
                'receipt_line_taxes.is_inclusive',
                'receipt_line_taxes.obliged_to_zimra',
                'receipt_line_taxes.tax_amount',
                'receipt_line_taxes.sales_amount_with_tax',
                'receipt_line_taxes.currency_code',
                'receipt_line_taxes.metadata',
                'receipt_line_taxes.created_at',

                'sales_transactions.invoice_number',
                'sales_transactions.transaction_date',
                'sales_transactions.sale_type',
                'sales_transactions.document_type',
                'sales_transactions.status',
                'sales_transactions.customer_name',
                'sales_transactions.currency_code as sale_currency_code',
                'sales_transactions.total_amount as receipt_total_amount',
                'sales_transactions.tax_amount as receipt_tax_amount',
                'sales_transactions.discount_amount as receipt_discount_amount',

                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, receipt_line_taxes.branch_id) as branch_name'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name'),

                DB::raw('receipt_lines.line_no as line_no'),
                DB::raw('receipt_lines.line_name as product_name'),
                DB::raw('receipt_lines.product_code as product_code'),
                DB::raw('receipt_lines.sku as sku'),
                DB::raw('receipt_lines.barcode as barcode'),
                DB::raw('receipt_lines.line_quantity as quantity'),
                DB::raw('receipt_lines.line_price as unit_price'),
                DB::raw('receipt_lines.line_subtotal as line_subtotal'),
                DB::raw('receipt_lines.line_discount as line_discount'),
                DB::raw('receipt_lines.line_tax as line_tax'),
                DB::raw('receipt_lines.line_total as line_total'),
            ])
            ->orderByDesc('sales_transactions.created_at')
            ->orderBy('receipt_lines.line_no')
            ->paginate($perPage);

        return [
            'message' => 'Tax line detail fetched successfully.',
            'data' => $rows,
        ];
    }

    /**
     * ZIMRA receipt register.
     */
    public function zimraRegister(Request $request): array
    {
        $company = app('company');

        [$dateFrom, $dateTo] = $this->dateRange($request);

        $perPage = max(1, min((int) ($request->per_page ?: 25), 250));

        $query = DB::table('zimra_receipts')
            ->leftJoin('sales_transactions', 'sales_transactions.id', '=', 'zimra_receipts.sales_transaction_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'zimra_receipts.branch_id')
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sales_transactions.cashier_id')
            ->where('zimra_receipts.company_id', $company->id)
            ->whereBetween('zimra_receipts.created_at', [
                $dateFrom . ' 00:00:00',
                $dateTo . ' 23:59:59',
            ]);

        if ($request->filled('branch_id')) {
            $query->where('zimra_receipts.branch_id', $request->branch_id);
        } elseif ($request->filled('branch_ids')) {
            $query->whereIn('zimra_receipts.branch_id', (array) $request->input('branch_ids'));
        }

        if ($request->filled('status')) {
            $query->where('zimra_receipts.status', $request->status);
        }

        if ($request->filled('receipt_type')) {
            $query->where('zimra_receipts.receipt_type', $request->receipt_type);
        }

        if ($request->filled('currency_code')) {
            $query->where('zimra_receipts.receipt_currency', $request->currency_code);
        }

        if ($request->filled('q')) {
            $term = trim($request->q);

            $query->where(function ($sub) use ($term) {
                $sub->where('zimra_receipts.invoice_no', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.invoice_number', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.customer_name', 'like', "%{$term}%")
                    ->orWhere('zimra_receipts.receipt_global_no', 'like', "%{$term}%")
                    ->orWhere('zimra_receipts.fdms_receipt_id', 'like', "%{$term}%");
            });
        }

        $rows = $query
            ->select([
                'zimra_receipts.id',
                'zimra_receipts.company_id',
                'zimra_receipts.branch_id',
                'zimra_receipts.fiscal_device_id',
                'zimra_receipts.fiscal_day_id',
                'zimra_receipts.sales_transaction_id',
                'zimra_receipts.original_zimra_receipt_id',
                'zimra_receipts.original_sales_transaction_id',
                'zimra_receipts.device_id',
                'zimra_receipts.fiscal_day_no',
                'zimra_receipts.receipt_counter',
                'zimra_receipts.receipt_global_no',
                'zimra_receipts.invoice_no',
                'zimra_receipts.receipt_type',
                'zimra_receipts.receipt_currency',
                'zimra_receipts.fdms_receipt_id',
                'zimra_receipts.operation_id',
                'zimra_receipts.qr_url',
                'zimra_receipts.qr_code_data',
                'zimra_receipts.qr_string',
                'zimra_receipts.status',
                'zimra_receipts.error',
                'zimra_receipts.submitted_at',
                'zimra_receipts.created_at',

                'sales_transactions.invoice_number as sale_invoice_number',
                'sales_transactions.transaction_date',
                'sales_transactions.customer_name',
                'sales_transactions.total_amount',
                'sales_transactions.tax_amount',
                'sales_transactions.discount_amount',
                'sales_transactions.fiscal_status',

                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, zimra_receipts.branch_id) as branch_name'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name'),
            ])
            ->orderByDesc('zimra_receipts.created_at')
            ->paginate($perPage);

        return [
            'message' => 'ZIMRA receipt register fetched successfully.',
            'data' => $rows,
        ];
    }

    /**
     * Single ZIMRA receipt detail.
     */
    public function zimraReceiptDetail(Request $request, string $receiptId): array
    {
        $company = app('company');

        $receipt = DB::table('zimra_receipts')
            ->leftJoin('sales_transactions', 'sales_transactions.id', '=', 'zimra_receipts.sales_transaction_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'zimra_receipts.branch_id')
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sales_transactions.cashier_id')
            ->where('zimra_receipts.company_id', $company->id)
            ->where('zimra_receipts.id', $receiptId)
            ->when($request->filled('branch_ids'), fn ($query) => $query->whereIn('zimra_receipts.branch_id', (array) $request->input('branch_ids')))
            ->select([
                'zimra_receipts.*',
                'sales_transactions.invoice_number as sale_invoice_number',
                'sales_transactions.transaction_date',
                'sales_transactions.customer_name',
                'sales_transactions.customer_phone',
                'sales_transactions.customer_tax_number',
                'sales_transactions.total_amount',
                'sales_transactions.tax_amount',
                'sales_transactions.discount_amount',
                'sales_transactions.fiscal_status',
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, zimra_receipts.branch_id) as branch_name'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name'),
            ])
            ->first();

        if (!$receipt) {
            return [
                'message' => 'ZIMRA receipt not found.',
                'data' => null,
            ];
        }

        $lines = DB::table('receipt_lines')
            ->where('sales_transaction_id', $receipt->sales_transaction_id)
            ->orderBy('line_no')
            ->get();

        $taxes = DB::table('receipt_line_taxes')
            ->where('sales_transaction_id', $receipt->sales_transaction_id)
            ->orderBy('tax_code')
            ->get();

        $originalReceipt = null;

        if (!empty($receipt->original_zimra_receipt_id)) {
            $originalReceipt = DB::table('zimra_receipts')
                ->where('id', $receipt->original_zimra_receipt_id)
                ->first();
        }

        return [
            'message' => 'ZIMRA receipt detail fetched successfully.',
            'data' => [
                'receipt' => $receipt,
                'lines' => $lines,
                'taxes' => $taxes,
                'original_receipt' => $originalReceipt,
                'payload' => $this->decodeJsonColumn($receipt->payload ?? null),
                'response' => $this->decodeJsonColumn($receipt->response ?? null),
                'credit_debit_note' => $this->decodeJsonColumn($receipt->credit_debit_note ?? null),
            ],
        ];
    }

    /**
     * Optional CSV export if you add a route for it later.
     */
    public function exportRows(Request $request)
    {
        $company = app('company');

        [$dateFrom, $dateTo] = $this->dateRange($request);

        $query = $this->baseTaxQuery($company->id, $dateFrom, $dateTo, $request);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($sub) use ($term) {
                $sub->where('sales_transactions.invoice_number', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.customer_name', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.line_name', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.product_code', 'like', "%{$term}%")
                    ->orWhere('receipt_lines.sku', 'like', "%{$term}%")
                    ->orWhere('receipt_line_taxes.tax_code', 'like', "%{$term}%");
            });
        }

        return $query
            ->select([
                DB::raw('DATE_FORMAT(sales_transactions.transaction_date, "%d/%m/%Y %H:%i") as Date'),
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, receipt_line_taxes.branch_id) as Branch'),
                DB::raw('sales_transactions.invoice_number as Receipt'),
                DB::raw('sales_transactions.sale_type as Type'),
                DB::raw('receipt_lines.line_no as Line'),
                DB::raw('receipt_lines.line_name as Product'),
                DB::raw('receipt_lines.product_code as ProductCode'),
                DB::raw('receipt_line_taxes.tax_code as TaxCode'),
                DB::raw('receipt_line_taxes.tax_percent as TaxPercent'),
                DB::raw('CASE WHEN receipt_line_taxes.is_inclusive = 1 THEN "Inclusive" ELSE "Exclusive" END as TaxMode'),
                DB::raw('CASE WHEN receipt_line_taxes.obliged_to_zimra = 1 THEN "Yes" ELSE "No" END as ZimraObliged'),
                DB::raw('receipt_line_taxes.sales_amount_with_tax as SalesAmountWithTax'),
                DB::raw('receipt_line_taxes.tax_amount as TaxAmount'),
                DB::raw('COALESCE(receipt_line_taxes.currency_code, sales_transactions.currency_code) as Currency'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as Cashier'),
            ])
            ->orderByDesc('sales_transactions.created_at')
            ->orderBy('receipt_lines.line_no')
            ->get();
    }

    public function zimraExportRows(Request $request)
    {
        $company = app('company');
        [$dateFrom, $dateTo] = $this->dateRange($request);

        $query = DB::table('zimra_receipts')
            ->leftJoin('sales_transactions', 'sales_transactions.id', '=', 'zimra_receipts.sales_transaction_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'zimra_receipts.branch_id')
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sales_transactions.cashier_id')
            ->where('zimra_receipts.company_id', $company->id)
            ->whereBetween('zimra_receipts.created_at', [
                $dateFrom . ' 00:00:00',
                $dateTo . ' 23:59:59',
            ]);

        if ($request->filled('branch_id')) {
            $query->where('zimra_receipts.branch_id', $request->branch_id);
        } elseif ($request->filled('branch_ids')) {
            $query->whereIn('zimra_receipts.branch_id', (array) $request->input('branch_ids'));
        }

        if ($request->filled('status')) {
            $query->where('zimra_receipts.status', $request->status);
        }
        if ($request->filled('receipt_type')) {
            $query->where('zimra_receipts.receipt_type', $request->receipt_type);
        }
        if ($request->filled('currency_code')) {
            $query->where('zimra_receipts.receipt_currency', $request->currency_code);
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function ($sub) use ($term) {
                $sub->where('zimra_receipts.invoice_no', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.invoice_number', 'like', "%{$term}%")
                    ->orWhere('sales_transactions.customer_name', 'like', "%{$term}%")
                    ->orWhere('zimra_receipts.receipt_global_no', 'like', "%{$term}%")
                    ->orWhere('zimra_receipts.fdms_receipt_id', 'like', "%{$term}%");
            });
        }

        return $query
            ->select([
                DB::raw('DATE_FORMAT(zimra_receipts.created_at, "%d/%m/%Y %H:%i") as Date'),
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, zimra_receipts.branch_id) as Branch'),
                DB::raw('COALESCE(zimra_receipts.invoice_no, sales_transactions.invoice_number) as Invoice'),
                DB::raw('zimra_receipts.receipt_type as ReceiptType'),
                DB::raw('zimra_receipts.receipt_currency as Currency'),
                DB::raw('zimra_receipts.status as Status'),
                DB::raw('zimra_receipts.receipt_counter as ReceiptCounter'),
                DB::raw('zimra_receipts.receipt_global_no as GlobalNumber'),
                DB::raw('zimra_receipts.fdms_receipt_id as FdmsId'),
                DB::raw('COALESCE(sales_transactions.total_amount, 0) as TotalAmount'),
                DB::raw('COALESCE(sales_transactions.tax_amount, 0) as TaxAmount'),
                DB::raw('COALESCE(sales_transactions.discount_amount, 0) as DiscountAmount'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as Cashier'),
                DB::raw('zimra_receipts.error as Error'),
            ])
            ->orderByDesc('zimra_receipts.created_at')
            ->get();
    }

    private function baseTaxQuery(string $companyId, string $dateFrom, string $dateTo, Request $request)
    {
        /*
         |--------------------------------------------------------------------------
         | Correct sales reference
         |--------------------------------------------------------------------------
         | Your SalesController creates sale lines in receipt_lines.
         | receipt_line_taxes references them with receipt_line_id.
         |
         | Do NOT join sales_transaction_lines because that table does not exist.
         */
        $query = DB::table('receipt_line_taxes')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_line_taxes.sales_transaction_id')
            ->leftJoin('receipt_lines', 'receipt_lines.id', '=', 'receipt_line_taxes.receipt_line_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'receipt_line_taxes.branch_id')
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sales_transactions.cashier_id')
            ->where('receipt_line_taxes.company_id', $companyId)
            ->whereBetween('sales_transactions.created_at', [
                $dateFrom . ' 00:00:00',
                $dateTo . ' 23:59:59',
            ]);

        $this->applyTaxFilters($query, $request);

        return $query;
    }

    private function baseSalesQuery(string $companyId, string $dateFrom, string $dateTo, Request $request)
    {
        $query = DB::table('sales_transactions')
            ->where('sales_transactions.company_id', $companyId)
            ->whereBetween('sales_transactions.created_at', [
                $dateFrom . ' 00:00:00',
                $dateTo . ' 23:59:59',
            ]);

        if ($request->filled('branch_id')) {
            $query->where('sales_transactions.branch_id', $request->branch_id);
        } elseif ($request->filled('branch_ids')) {
            $query->whereIn('sales_transactions.branch_id', (array) $request->input('branch_ids'));
        }

        if ($request->filled('currency_code')) {
            $query->where('sales_transactions.currency_code', $request->currency_code);
        }

        if ($request->filled('sale_type')) {
            $query->where('sales_transactions.sale_type', $request->sale_type);
        }

        if ($request->filled('status')) {
            $query->where('sales_transactions.status', $request->status);
        }

        return $query;
    }

    private function applyTaxFilters($query, Request $request): void
    {
        if ($request->filled('branch_id')) {
            $query->where('receipt_line_taxes.branch_id', $request->branch_id);
        } elseif ($request->filled('branch_ids')) {
            $query->whereIn('receipt_line_taxes.branch_id', (array) $request->input('branch_ids'));
        }

        if ($request->filled('currency_code')) {
            $query->where(function ($sub) use ($request) {
                $sub->where('receipt_line_taxes.currency_code', $request->currency_code)
                    ->orWhere('sales_transactions.currency_code', $request->currency_code);
            });
        }

        if ($request->filled('tax_code')) {
            $query->where('receipt_line_taxes.tax_code', $request->tax_code);
        }

        if ($request->filled('obliged_to_zimra')) {
            $query->where(
                'receipt_line_taxes.obliged_to_zimra',
                filter_var($request->obliged_to_zimra, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('is_inclusive')) {
            $query->where(
                'receipt_line_taxes.is_inclusive',
                filter_var($request->is_inclusive, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('sale_type')) {
            $query->where('sales_transactions.sale_type', $request->sale_type);
        }

        if ($request->filled('status')) {
            $query->where('sales_transactions.status', $request->status);
        }
    }

    private function dateRange(Request $request): array
    {
        $dateFrom = $request->date_from ?: now()->toDateString();
        $dateTo = $request->date_to ?: $dateFrom;

        return [$dateFrom, $dateTo];
    }

    private function filters(Request $request, string $dateFrom, string $dateTo): array
    {
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $request->branch_id,
            'currency_code' => $request->currency_code,
            'tax_code' => $request->tax_code,
            'obliged_to_zimra' => $request->obliged_to_zimra,
            'is_inclusive' => $request->is_inclusive,
            'sale_type' => $request->sale_type,
            'status' => $request->status,
            'receipt_type' => $request->receipt_type,
            'q' => $request->q,
        ];
    }

    private function decodeJsonColumn($value): mixed
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: $value;
        }

        return $value;
    }
}
