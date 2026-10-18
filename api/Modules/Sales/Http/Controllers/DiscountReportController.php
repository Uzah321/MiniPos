<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function App\Helpers\hasPermission;

class DiscountReportController extends Controller
{
    public function __construct(
        private readonly EnterpriseReportDownloadService $reportDownloads,
        private readonly BusinessModeBranchScopeService $branchScope
    ) {
    }

    public function dashboard(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        $base = $this->filteredSaleDiscounts($request, $company->id);

        return response()->json([
            'message' => 'Discount dashboard fetched successfully.',
            'data' => [
                'summary' => [
                    'discount_count' => (clone $base)->count(),
                    'total_discount_amount' => round((float) (clone $base)->sum('sale_discounts.discount_amount'), 4),
                    'gross_amount' => round((float) (clone $base)->sum('sale_discounts.gross_amount'), 4),
                    'net_amount' => round((float) (clone $base)->sum('sale_discounts.net_amount'), 4),
                    'manager_approved_count' => (clone $base)->whereNotNull('sale_discounts.manager_approval_id')->count(),
                ],
                'by_type' => $this->byType($request, $company->id),
                'by_cashier' => $this->byCashier($request, $company->id),
                'by_branch' => $this->byBranch($request, $company->id),
                'by_day' => $this->byDay($request, $company->id),
            ],
        ]);
    }

    public function report(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        return response()->json([
            'message' => 'Discount report fetched successfully.',
            'data' => $this->baseQuery($request, $company->id)
                ->orderByDesc('sale_discounts.created_at')
                ->paginate((int) $request->input('per_page', 50)),
        ]);
    }

    public function byTypeReport(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        return response()->json([
            'message' => 'Discount by type report fetched successfully.',
            'data' => $this->byType($request, $company->id),
        ]);
    }

    public function byCashierReport(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        return response()->json([
            'message' => 'Discount by cashier report fetched successfully.',
            'data' => $this->byCashier($request, $company->id),
        ]);
    }

    public function byBranchReport(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'view_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        return response()->json([
            'message' => 'Discount by branch report fetched successfully.',
            'data' => $this->byBranch($request, $company->id),
        ]);
    }

    public function export(Request $request)
    {
        $company = app('company');

        $permission = hasPermission(auth()->user()->role_id, 'export_discount_reports', $company->id);
        if ($permission !== true) return $permission;

        $rows = $this->baseQuery($request, $company->id)
            ->orderByDesc('sale_discounts.created_at')
            ->get();

        $headers = [
            'Date',
            'Branch',
            'Branch Code',
            'Receipt',
            'Discount Code',
            'Discount Name',
            'Scope',
            'Basis',
            'Value',
            'Gross Amount',
            'Discount Amount',
            'Net Amount',
            'Currency',
            'Cashier',
            'Approved By',
            'Reason',
        ];

        $reportRows = $rows->map(fn ($row) => [
            $row->created_at,
            $row->branch_name ?: $row->branch_id,
            $row->branch_code ?: '',
            $row->receipt_number,
            $row->discount_code,
            $row->discount_name,
            $row->scope,
            $row->discount_basis,
            $row->discount_value,
            $row->gross_amount,
            $row->discount_amount,
            $row->net_amount,
            $row->currency,
            $row->cashier_name,
            $row->approved_by_name,
            $row->reason,
        ])->all();

        $format = strtolower((string) $request->input('format', 'xlsx'));
        $timestamp = now()->format('Ymd_His');

        if ($format === 'pdf') {
            return $this->reportDownloads->downloadPdf(
                'Discount Transaction Report',
                "discount-report-{$timestamp}.pdf",
                [[
                    'title' => 'Discount Transactions',
                    'headers' => $headers,
                    'rows' => $reportRows,
                ]],
                compact: true
            );
        }

        if (in_array($format, ['xlsx', 'excel'], true)) {
            return $this->reportDownloads->downloadSpreadsheet(
                'Discount Transaction Report',
                "discount-report-{$timestamp}.xlsx",
                [[
                    'title' => 'Discounts',
                    'headers' => $headers,
                    'rows' => $reportRows,
                ]],
                true
            );
        }

        abort_unless($format === 'csv', 422, 'Unsupported discount report format.');

        $filename = "discount-report-{$timestamp}.csv";

        return response()->streamDownload(function () use ($headers, $reportRows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($reportRows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Detail query only
    |--------------------------------------------------------------------------
    | Uses company_branches, not branches.
    */
    private function baseQuery(Request $request, string $companyId)
    {
        $receiptSelect = $this->salesReceiptSelectSql();

        return $this->filteredSaleDiscounts($request, $companyId)
            ->leftJoin('sales_transactions', 'sales_transactions.id', '=', 'sale_discounts.sales_transaction_id')
            ->leftJoin('company_branches', function ($join) use ($companyId) {
                $join->on('company_branches.id', '=', 'sale_discounts.branch_id')
                    ->where('company_branches.company_id', '=', $companyId);
            })
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sale_discounts.cashier_id')
            ->leftJoin('pos_users as approvers', 'approvers.id', '=', 'sale_discounts.approved_by')
            ->select([
                'sale_discounts.*',
                DB::raw($receiptSelect . ' as receipt_number'),
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, sale_discounts.branch_id) as branch_name'),
                DB::raw('company_branches.branch_code as branch_code'),
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name'),
                DB::raw('COALESCE(approvers.name, approvers.username, "N/A") as approved_by_name'),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter only query
    |--------------------------------------------------------------------------
    | No select and no joins. Used for aggregates to avoid GROUP BY errors.
    */
    private function filteredSaleDiscounts(Request $request, string $companyId)
    {
        $branchIds = $this->modeScopedBranchIds($request, $companyId);

        return DB::table('sale_discounts')
            ->where('sale_discounts.company_id', $companyId)
            ->whereIn('sale_discounts.branch_id', $branchIds)
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('sale_discounts.created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('sale_discounts.created_at', '<=', $request->date_to);
            })
            ->when($request->filled('discount_id'), function ($query) use ($request) {
                $query->where('sale_discounts.discount_id', $request->discount_id);
            });
    }

    /** @return array<int, string> */
    private function modeScopedBranchIds(Request $request, string $companyId): array
    {
        $cacheKey = 'discount_report_mode_branch_ids';
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $requested = $request->input('branch_ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        $requested = is_array($requested) ? $requested : [];
        if ($request->filled('branch_id')) {
            $requested[] = $request->input('branch_id');
        }

        $scope = $this->branchScope->resolve($request, $companyId, $requested);
        $request->attributes->set($cacheKey, $scope['branch_ids']);

        return $scope['branch_ids'];
    }

    private function byType(Request $request, string $companyId)
    {
        return $this->filteredSaleDiscounts($request, $companyId)
            ->select([
                'sale_discounts.discount_code',
                'sale_discounts.discount_name',
                'sale_discounts.discount_basis',
                'sale_discounts.currency',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(sale_discounts.gross_amount), 0) as gross_amount'),
                DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0) as discount_amount'),
                DB::raw('COALESCE(SUM(sale_discounts.net_amount), 0) as net_amount'),
            ])
            ->groupBy([
                'sale_discounts.discount_code',
                'sale_discounts.discount_name',
                'sale_discounts.discount_basis',
                'sale_discounts.currency',
            ])
            ->orderByDesc(DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0)'))
            ->get();
    }

    private function byCashier(Request $request, string $companyId)
    {
        return $this->filteredSaleDiscounts($request, $companyId)
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'sale_discounts.cashier_id')
            ->select([
                'sale_discounts.cashier_id',
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name'),
                'sale_discounts.currency',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0) as discount_amount'),
            ])
            ->groupBy([
                'sale_discounts.cashier_id',
                DB::raw('COALESCE(cashiers.name, cashiers.username, "Unknown")'),
                'sale_discounts.currency',
            ])
            ->orderByDesc(DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0)'))
            ->get();
    }

    private function byBranch(Request $request, string $companyId)
    {
        return $this->filteredSaleDiscounts($request, $companyId)
            ->leftJoin('company_branches', function ($join) use ($companyId) {
                $join->on('company_branches.id', '=', 'sale_discounts.branch_id')
                    ->where('company_branches.company_id', '=', $companyId);
            })
            ->select([
                'sale_discounts.branch_id',
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, sale_discounts.branch_id) as branch_name'),
                DB::raw('company_branches.branch_code as branch_code'),
                'sale_discounts.currency',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(sale_discounts.gross_amount), 0) as gross_amount'),
                DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0) as discount_amount'),
                DB::raw('COALESCE(SUM(sale_discounts.net_amount), 0) as net_amount'),
            ])
            ->groupBy([
                'sale_discounts.branch_id',
                DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, sale_discounts.branch_id)'),
                'company_branches.branch_code',
                'sale_discounts.currency',
            ])
            ->orderBy('branch_name')
            ->get();
    }

    private function byDay(Request $request, string $companyId)
    {
        return $this->filteredSaleDiscounts($request, $companyId)
            ->select([
                DB::raw('DATE(sale_discounts.created_at) as report_date'),
                'sale_discounts.currency',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(sale_discounts.discount_amount), 0) as discount_amount'),
            ])
            ->groupBy([
                DB::raw('DATE(sale_discounts.created_at)'),
                'sale_discounts.currency',
            ])
            ->orderBy('report_date')
            ->get();
    }

    private function salesReceiptSelectSql(): string
    {
        if (!Schema::hasTable('sales_transactions')) {
            return 'NULL';
        }

        $candidates = [
            'receipt_no',
            'receipt_number',
            'invoice_number',
            'invoice_no',
            'transaction_number',
            'transaction_no',
            'sale_number',
            'reference',
            'reference_number',
        ];

        $existing = [];

        foreach ($candidates as $column) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $existing[] = 'sales_transactions.`' . $column . '`';
            }
        }

        if (empty($existing)) {
            return 'sales_transactions.`id`';
        }

        return 'COALESCE(' . implode(', ', $existing) . ', sales_transactions.`id`)';
    }
}
