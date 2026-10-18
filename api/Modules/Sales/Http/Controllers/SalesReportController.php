<?php
//
//namespace Modules\Sales\Http\Controllers;
//
//use App\Http\Controllers\Controller;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Schema;
//use Modules\Sales\Models\SalesTransaction;
//use Modules\Authentication\Models\GeneralSetting;
//
//
//
//
//class SalesReportController extends Controller
//{
//    /*
//     * Till table note:
//     * This project uses pos_tills, not tills.
//     * All report joins alias pos_tills as tills so existing select aliases remain stable.
//     */
//    public function dashboard(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        return response()->json([
//            'message' => 'Sales dashboard fetched successfully.',
//            'data' => [
//                'base_summary' => $this->baseSummary($company->id, $filters),
//                'currency_summary' => $this->salesByCurrency($company->id, $filters),
//                'payment_summary' => $this->paymentSummaryByCurrency($company->id, $filters),
//                'tax_summary' => $this->taxSummary($company->id, $filters),
//                'hourly_sales' => $this->hourlySales($company->id, $filters),
//                'daily_sales' => $this->dailySales($company->id, $filters),
//                'top_products' => $this->topProducts($company->id, $filters, 20),
//                'cashier_performance' => $this->cashierPerformance($company->id, $filters),
//            ],
//        ]);
//    }
//
//
//    public function shiftSessions(Request $request)
//    {
//        $company = app('company');
//
//        $query = DB::table('pos_user_shift_sessions')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
//            ->selectRaw('
//            pos_user_shift_sessions.id,
//            pos_user_shift_sessions.company_id,
//            pos_user_shift_sessions.branch_id,
//            pos_user_shift_sessions.pos_user_id as cashier_id,
//            COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//            pos_user_shift_sessions.shift_name,
//            pos_user_shift_sessions.opened_at,
//            pos_user_shift_sessions.closed_at,
//            pos_user_shift_sessions.status
//        ')
//            ->where('pos_user_shift_sessions.company_id', $company->id)
//            ->when($request->filled('branch_id'), function ($q) use ($request) {
//                $q->where('pos_user_shift_sessions.branch_id', $request->branch_id);
//            })
//            ->when($request->filled('cashier_id'), function ($q) use ($request) {
//                $q->where('pos_user_shift_sessions.pos_user_id', $request->cashier_id);
//            })
//            ->when($request->filled('status'), function ($q) use ($request) {
//                $q->where('pos_user_shift_sessions.status', $request->status);
//            })
//            ->when($request->filled('date_from'), function ($q) use ($request) {
//                $q->whereDate('pos_user_shift_sessions.opened_at', '>=', $request->date_from);
//            })
//            ->when($request->filled('date_to'), function ($q) use ($request) {
//                $q->whereDate('pos_user_shift_sessions.opened_at', '<=', $request->date_to);
//            })
//            ->orderByDesc('pos_user_shift_sessions.opened_at');
//
//        return response()->json([
//            'message' => 'Shift sessions fetched successfully.',
//            'data' => $query->paginate($request->per_page ?? 100),
//        ]);
//    }
//
//    public function salesList(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        $lineSummary = $this->saleLineSummarySubquery($company->id, $filters);
//        $costSummary = $this->saleCostSummarySubquery($company->id, $filters);
//
//        $query = DB::table('sales_transactions')
//            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
//                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
//                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
//            ->selectRaw('
//                sales_transactions.id,
//                sales_transactions.company_id,
//                sales_transactions.branch_id,
//                sales_transactions.till_id,
//                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
//                tills.till_code,
//                sales_transactions.shift_id,
//                sales_transactions.shift_session_id,
//                sales_transactions.cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                sales_transactions.user_id,
//                sales_transactions.invoice_number,
//                sales_transactions.transaction_date,
//                sales_transactions.document_type,
//                sales_transactions.sale_type,
//                sales_transactions.status,
//                sales_transactions.currency_code,
//                sales_transactions.base_currency_code,
//                sales_transactions.exchange_rate,
//                sales_transactions.subtotal_amount,
//                sales_transactions.discount_amount,
//                sales_transactions.tax_amount,
//                sales_transactions.total_amount,
//                sales_transactions.base_total_amount,
//                sales_transactions.payment_method,
//                sales_transactions.amount_tendered,
//                sales_transactions.change_amount,
//                sales_transactions.customer_name,
//                sales_transactions.customer_phone,
//                COALESCE(line_summary.receipt_lines_count, 0) as receipt_lines_count,
//                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as sales_value,
//                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) as base_sales_value,
//                COALESCE(cost_summary.cost_value, 0) as cost_value,
//                COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code) as cost_currency_code,
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) - COALESCE(cost_summary.cost_value, 0) as estimated_profit,
//                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) - COALESCE(cost_summary.base_cost_value, cost_summary.cost_value, 0) as base_estimated_profit
//            ')
//            ->where('sales_transactions.company_id', $company->id)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->when($filters['status'], fn ($q) => $q->where('sales_transactions.status', $filters['status']))
//            ->orderByDesc('sales_transactions.transaction_date');
//
//        return response()->json([
//            'message' => 'Sales list fetched successfully.',
//            'summary' => $this->salesListSummary($company->id, $filters),
//            'data' => $query->paginate($request->per_page ?? 25),
//        ]);
//    }
//
//    public function dailyReport(Request $request)
//    {
//        $company = app('company');
//
//        $businessDate = $request->business_date
//            ?: $request->date
//                ?: $request->date_from
//                    ?: now()->toDateString();
//
//        $filters = $this->filters($request);
//        $filters['date_from'] = $businessDate;
//        $filters['date_to'] = $businessDate;
//        $filters['status'] = 'completed';
//
//        return response()->json([
//            'message' => 'Daily sales report fetched successfully.',
//            'data' => [
//                'business_date' => $businessDate,
//                'summary' => $this->dailyProfitSummary($company->id, $filters),
//                'cashier_summary' => $this->dailyCashierSummary($company->id, $filters),
//                'payment_type_summary' => $this->dailyPaymentTypeSummary($company->id, $filters),
//                'category_summary' => $this->dailyCategorySummary($company->id, $filters),
//                'product_sales' => $this->dailyProductSales($company->id, $filters, (int) ($request->product_limit ?? 100)),
//                'sales' => $this->dailySalesList($company->id, $filters, (int) ($request->per_page ?? 50)),
//            ],
//        ]);
//    }
//
//    public function saleDrilldown(string $saleId)
//    {
//        $company = app('company');
//
//        $sale = SalesTransaction::with([
//            'branch',
//            'till',
//            'cashier',
//            'payments',
//            'receiptLines.taxes',
//        ])
//            ->where('company_id', $company->id)
//            ->where('id', $saleId)
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Sale not found.',
//            ], 404);
//        }
//
//        return response()->json([
//            'message' => 'Sale drill-down fetched successfully.',
//            'data' => [
//                'sale' => $sale,
//                'payments_by_currency' => $this->salePaymentsByCurrency($saleId),
//                'lines_by_currency' => $this->saleLinesByCurrency($saleId),
//                'taxes_by_code' => $this->saleTaxesByCode($saleId),
//            ],
//        ]);
//    }
//
//    public function paymentSummary(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        return response()->json([
//            'message' => 'Payment summary fetched successfully.',
//            'data' => $this->paymentSummaryByCurrency($company->id, $filters),
//        ]);
//    }
//
//    public function productSales(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        return response()->json([
//            'message' => 'Product sales report fetched successfully.',
//            'data' => $this->topProducts(
//                $company->id,
//                $filters,
//                (int) ($request->per_page ?? 50)
//            ),
//        ]);
//    }
//
//    public function productDrilldown(Request $request, string $productId)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        $rows = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
//            ->leftJoin('pos_user_shift_sessions', 'pos_user_shift_sessions.id', '=', 'sales_transactions.shift_session_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw('
//                sales_transactions.id as sale_id,
//                sales_transactions.invoice_number,
//                sales_transactions.transaction_date,
//                sales_transactions.cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                sales_transactions.till_id,
//                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
//                tills.till_code,
//                sales_transactions.shift_session_id,
//                pos_user_shift_sessions.shift_name,
//                receipt_lines.line_name,
//                receipt_lines.line_quantity,
//                receipt_lines.currency_code,
//                receipt_lines.line_price,
//                receipt_lines.line_subtotal,
//                receipt_lines.line_discount,
//                receipt_lines.line_tax,
//                receipt_lines.line_total,
//                COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) as average_cost,
//                COALESCE(product_costs.currency, receipt_lines.currency_code) as cost_currency_code,
//                receipt_lines.line_quantity * COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) as cost_value,
//                receipt_lines.line_total - (receipt_lines.line_quantity * COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)) as gross_profit
//            ')
//            ->where('sales_transactions.company_id', $company->id)
//            ->where('sales_transactions.status', 'completed')
//            ->where('receipt_lines.product_id', $productId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->orderByDesc('sales_transactions.transaction_date')
//            ->paginate($request->per_page ?? 50);
//
//        return response()->json([
//            'message' => 'Product drill-down fetched successfully.',
//            'data' => $rows,
//        ]);
//    }
//
//    public function cashierSales(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        return response()->json([
//            'message' => 'Cashier sales report fetched successfully.',
//            'data' => $this->cashierPerformance($company->id, $filters),
//        ]);
//    }
//
//    public function shiftSales(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        $sessions = DB::table('pos_user_shift_sessions')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
//            ->selectRaw('
//                pos_user_shift_sessions.id as shift_session_id,
//                pos_user_shift_sessions.shift_configuration_id,
//                pos_user_shift_sessions.shift_name,
//                pos_user_shift_sessions.company_id,
//                pos_user_shift_sessions.branch_id,
//                pos_user_shift_sessions.pos_user_id as cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                pos_user_shift_sessions.opening_float,
//                pos_user_shift_sessions.closing_cash,
//                pos_user_shift_sessions.opened_at,
//                pos_user_shift_sessions.closed_at,
//                pos_user_shift_sessions.status
//            ')
//            ->where('pos_user_shift_sessions.company_id', $company->id)
//            ->whereDate('pos_user_shift_sessions.opened_at', '>=', $filters['date_from'])
//            ->whereDate('pos_user_shift_sessions.opened_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('pos_user_shift_sessions.branch_id', $filters['branch_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('pos_user_shift_sessions.pos_user_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('pos_user_shift_sessions.id', $filters['shift_session_id']))
//            ->orderByDesc('pos_user_shift_sessions.opened_at')
//            ->paginate($request->per_page ?? 25);
//
//        $rows = collect($sessions->items())->map(function ($session) use ($company, $filters) {
//            $shiftFilters = array_merge($filters, [
//                'shift_session_id' => $session->shift_session_id,
//            ]);
//
//            return [
//                'shift_session' => $session,
//                'base_summary' => $this->baseSummary($company->id, $shiftFilters),
//                'currency_summary' => $this->salesByCurrency($company->id, $shiftFilters),
//                'payment_summary' => $this->paymentSummaryByCurrency($company->id, $shiftFilters),
//                'cashup' => $this->cashupForShift($company->id, $session->shift_session_id),
//            ];
//        });
//
//        return response()->json([
//            'message' => 'Shift sales report fetched successfully.',
//            'data' => [
//                'current_page' => $sessions->currentPage(),
//                'last_page' => $sessions->lastPage(),
//                'per_page' => $sessions->perPage(),
//                'total' => $sessions->total(),
//                'data' => $rows,
//            ],
//        ]);
//    }
//
//    public function shiftSalesDrilldown(Request $request, string $shiftSessionId)
//    {
//        $request->merge([
//            'shift_session_id' => $shiftSessionId,
//        ]);
//
//        return $this->salesList($request);
//    }
//
//    public function dayEnds(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        $salesByDayCurrency = DB::table('sales_transactions')
//            ->selectRaw('
//                DATE(transaction_date) as business_date,
//                currency_code,
//                base_currency_code,
//                COUNT(*) as transaction_count,
//                SUM(subtotal_amount) as subtotal,
//                SUM(discount_amount) as discount,
//                SUM(tax_amount) as tax,
//                SUM(total_amount) as total,
//                SUM(base_total_amount) as base_total
//            ')
//            ->where('company_id', $company->id)
//            ->where('status', 'completed')
//            ->whereDate('transaction_date', '>=', $filters['date_from'])
//            ->whereDate('transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->groupByRaw('DATE(transaction_date), currency_code, base_currency_code')
//            ->orderBy('business_date')
//            ->get();
//
//        /*
//         * Do cashup aggregation in PHP so old records still work even when
//         * top-level pos_cashups.cashout_total is 0 but nested JSON has:
//         * sales_summary.cash.cashout_total
//         * cashout_summary.cashout_total
//         */
//        $cashupRows = DB::table('pos_cashups')
//            ->where('company_id', $company->id)
//            ->whereDate('closed_at', '>=', $filters['date_from'])
//            ->whereDate('closed_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->orderBy('closed_at')
//            ->get()
//            ->map(fn ($cashup) => $this->normalizeCashupForReports($cashup));
//
//        $cashupsByDay = $cashupRows
//            ->groupBy(fn ($cashup) => substr((string) $cashup->closed_at, 0, 10))
//            ->map(function ($rows, $businessDate) {
//                return [
//                    'business_date' => $businessDate,
//                    'expected_cash' => round($rows->sum('expected_cash'), 4),
//                    'cashout_total' => round($rows->sum('cashout_total'), 4),
//                    'cashout_count' => (int) $rows->sum('cashout_count'),
//                    'expected_cash_after_cashouts' => round($rows->sum('expected_cash_after_cashouts'), 4),
//                    'counted_cash' => round($rows->sum('counted_cash'), 4),
//                    'cash_variance' => round($rows->sum('cash_variance'), 4),
//                    'expected_total' => round($rows->sum('expected_total'), 4),
//                    'counted_total' => round($rows->sum('counted_total'), 4),
//                    'total_variance' => round($rows->sum('total_variance'), 4),
//                    'cashup_count' => $rows->count(),
//                ];
//            })
//            ->values();
//
//        return response()->json([
//            'message' => 'Day ends report fetched successfully.',
//            'data' => [
//                'sales_by_day_currency' => $salesByDayCurrency,
//                'cashups_by_day' => $cashupsByDay,
//            ],
//        ]);
//    }
//
//    private function filters(Request $request): array
//    {
//        return [
//            'date_from' => $request->date_from ?? now()->toDateString(),
//            'date_to' => $request->date_to ?? now()->toDateString(),
//            'branch_id' => $request->branch_id,
//            'till_id' => $request->till_id,
//            'cashier_id' => $request->cashier_id,
//            'shift_session_id' => $request->shift_session_id,
//            'currency_code' => $request->currency_code,
//            'status' => $request->status,
//        ];
//    }
//
//    private function baseSalesQuery(string $companyId, array $filters)
//    {
//        return DB::table('sales_transactions')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']));
//    }
//
//    private function baseSummary(string $companyId, array $filters)
//    {
//        $completed = $this->baseSalesQuery($companyId, $filters)
//            ->where('sales_transactions.status', 'completed');
//
//        $row = $completed
//            ->selectRaw('
//                COUNT(*) as transaction_count,
//                SUM(subtotal_amount) as gross_sales,
//                SUM(discount_amount) as discounts,
//                SUM(tax_amount) as tax,
//                SUM(total_amount) as net_sales,
//                SUM(base_total_amount) as base_net_sales
//            ')
//            ->first();
//
//        $voidCount = $this->baseSalesQuery($companyId, $filters)
//            ->where('sales_transactions.status', 'voided')
//            ->count();
//
//        $refundCount = $this->baseSalesQuery($companyId, $filters)
//            ->where('sales_transactions.sale_type', 'refund')
//            ->count();
//
//        $transactionCount = (int) ($row->transaction_count ?? 0);
//        $netSales = (float) ($row->net_sales ?? 0);
//
//        return [
//            'transaction_count' => $transactionCount,
//            'gross_sales' => round((float) ($row->gross_sales ?? 0), 4),
//            'discounts' => round((float) ($row->discounts ?? 0), 4),
//            'tax' => round((float) ($row->tax ?? 0), 4),
//            'net_sales' => round($netSales, 4),
//            'base_net_sales' => round((float) ($row->base_net_sales ?? 0), 4),
//            'void_count' => $voidCount,
//            'refund_count' => $refundCount,
//            'average_basket' => $transactionCount > 0
//                ? round($netSales / $transactionCount, 4)
//                : 0,
//        ];
//    }
//
//    private function salesByCurrency(string $companyId, array $filters)
//    {
//        return $this->baseSalesQuery($companyId, $filters)
//            ->selectRaw('
//                sales_transactions.currency_code,
//                sales_transactions.base_currency_code,
//                COUNT(*) as transaction_count,
//                SUM(sales_transactions.subtotal_amount) as subtotal,
//                SUM(sales_transactions.discount_amount) as discount,
//                SUM(sales_transactions.tax_amount) as tax,
//                SUM(sales_transactions.total_amount) as total,
//                SUM(sales_transactions.base_subtotal_amount) as base_subtotal,
//                SUM(sales_transactions.base_discount_amount) as base_discount,
//                SUM(sales_transactions.base_tax_amount) as base_tax,
//                SUM(sales_transactions.base_total_amount) as base_total
//            ')
//            ->where('sales_transactions.status', 'completed')
//            ->groupBy(
//                'sales_transactions.currency_code',
//                'sales_transactions.base_currency_code'
//            )
//            ->orderBy('sales_transactions.currency_code')
//            ->get();
//    }
//
//    private function paymentSummaryByCurrency(string $companyId, array $filters)
//    {
//        return DB::table('sales_transaction_payments')
//            ->selectRaw('
//                payment_name,
//                money_code,
//                currency_code,
//                base_currency_code,
//                COUNT(*) as payment_count,
//                SUM(amount) as amount,
//                SUM(base_amount) as base_amount,
//                SUM(amount_tendered) as amount_tendered,
//                SUM(change_amount) as change_amount
//            ')
//            ->where('company_id', $companyId)
//            ->whereDate('created_at', '>=', $filters['date_from'])
//            ->whereDate('created_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('till_id', $filters['till_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('currency_code', $filters['currency_code']))
//            ->groupBy(
//                'payment_name',
//                'money_code',
//                'currency_code',
//                'base_currency_code'
//            )
//            ->orderBy('payment_name')
//            ->orderBy('currency_code')
//            ->get();
//    }
//
//    private function taxSummary(string $companyId, array $filters)
//    {
//        return DB::table('receipt_line_taxes')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_line_taxes.sales_transaction_id')
//            ->selectRaw('
//                receipt_line_taxes.tax_code,
//                receipt_line_taxes.tax_percent,
//                receipt_line_taxes.currency_code,
//                COUNT(*) as line_count,
//                SUM(receipt_line_taxes.tax_amount) as tax_amount,
//                SUM(receipt_line_taxes.sales_amount_with_tax) as sales_amount_with_tax
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_line_taxes.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_line_taxes.tax_code',
//                'receipt_line_taxes.tax_percent',
//                'receipt_line_taxes.currency_code'
//            )
//            ->orderBy('receipt_line_taxes.tax_code')
//            ->get();
//    }
//
//    private function hourlySales(string $companyId, array $filters)
//    {
//        return $this->baseSalesQuery($companyId, $filters)
//            ->selectRaw('
//                HOUR(sales_transactions.transaction_date) as hour,
//                sales_transactions.currency_code,
//                COUNT(*) as transaction_count,
//                SUM(sales_transactions.total_amount) as total,
//                SUM(sales_transactions.base_total_amount) as base_total
//            ')
//            ->where('sales_transactions.status', 'completed')
//            ->groupByRaw('HOUR(sales_transactions.transaction_date), sales_transactions.currency_code')
//            ->orderBy('hour')
//            ->orderBy('sales_transactions.currency_code')
//            ->get();
//    }
//
//    private function dailySales(string $companyId, array $filters)
//    {
//        return $this->baseSalesQuery($companyId, $filters)
//            ->selectRaw('
//                DATE(sales_transactions.transaction_date) as sale_date,
//                sales_transactions.currency_code,
//                COUNT(*) as transaction_count,
//                SUM(sales_transactions.total_amount) as total,
//                SUM(sales_transactions.base_total_amount) as base_total
//            ')
//            ->where('sales_transactions.status', 'completed')
//            ->groupByRaw('DATE(sales_transactions.transaction_date), sales_transactions.currency_code')
//            ->orderBy('sale_date')
//            ->orderBy('sales_transactions.currency_code')
//            ->get();
//    }
//
//    private function topProducts(string $companyId, array $filters, int $limit = 20)
//    {
//        $productLines = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//                receipt_lines.line_name as product_name,
//                receipt_lines.currency_code,
//                receipt_lines.base_currency_code,
//                SUM(receipt_lines.line_quantity) as quantity_sold,
//                SUM(receipt_lines.line_subtotal) as subtotal,
//                SUM(receipt_lines.line_discount) as discount,
//                SUM(receipt_lines.line_tax) as tax,
//                SUM(receipt_lines.line_total) as sales_value,
//                SUM(receipt_lines.base_line_total) as base_sales_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_lines.sales_transaction_id',
//                'receipt_lines.product_id',
//                'receipt_lines.line_name',
//                'receipt_lines.currency_code',
//                'receipt_lines.base_currency_code'
//            );
//
//        $costBySaleProduct = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//                SUM(receipt_lines.line_quantity * COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)) as cost_value,
//                SUM(receipt_lines.line_quantity * COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) *
//                    CASE
//                        WHEN COALESCE(product_costs.currency, sales_transactions.currency_code) = sales_transactions.base_currency_code THEN 1
//                        ELSE COALESCE(sales_transactions.exchange_rate, 1)
//                    END
//                ) as base_cost_value,
//                MAX(COALESCE(product_costs.currency, sales_transactions.currency_code)) as cost_currency_code,
//                MAX(COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)) as average_cost
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy('receipt_lines.sales_transaction_id', 'receipt_lines.product_id');
//
//        return DB::query()
//            ->fromSub($productLines, 'product_lines')
//            ->leftJoinSub($costBySaleProduct, 'product_costs', function ($join) {
//                $join->on('product_costs.sales_transaction_id', '=', 'product_lines.sales_transaction_id')
//                    ->on('product_costs.product_id', '=', 'product_lines.product_id');
//            })
//            ->selectRaw('
//                product_lines.product_id,
//                product_lines.product_name,
//                product_lines.currency_code,
//                product_lines.base_currency_code,
//                MAX(COALESCE(product_costs.cost_currency_code, product_lines.currency_code)) as cost_currency_code,
//                MAX(COALESCE(product_costs.average_cost, 0)) as average_cost,
//                COUNT(DISTINCT product_lines.sales_transaction_id) as receipt_count,
//                SUM(product_lines.quantity_sold) as quantity_sold,
//                SUM(product_lines.subtotal) as subtotal,
//                SUM(product_lines.discount) as discount,
//                SUM(product_lines.tax) as tax,
//                SUM(product_lines.sales_value) as total,
//                SUM(product_lines.sales_value) as sales_value,
//                SUM(product_lines.base_sales_value) as base_total,
//                SUM(product_lines.base_sales_value) as base_sales_value,
//                SUM(COALESCE(product_costs.cost_value, 0)) as cost_value,
//                SUM(COALESCE(product_costs.base_cost_value, 0)) as base_cost_value,
//                SUM(product_lines.sales_value) - SUM(COALESCE(product_costs.cost_value, 0)) as estimated_profit,
//                SUM(product_lines.sales_value) - SUM(COALESCE(product_costs.cost_value, 0)) as gross_profit,
//                SUM(product_lines.base_sales_value) - SUM(COALESCE(product_costs.base_cost_value, 0)) as base_gross_profit
//            ')
//            ->groupBy(
//                'product_lines.product_id',
//                'product_lines.product_name',
//                'product_lines.currency_code',
//                'product_lines.base_currency_code'
//            )
//            ->orderByDesc('base_gross_profit')
//            ->limit($limit)
//            ->get()
//            ->map(function ($row) {
//                $salesValue = (float) ($row->sales_value ?? 0);
//                $row->quantity_sold = round((float) ($row->quantity_sold ?? 0), 4);
//                $row->average_cost = round((float) ($row->average_cost ?? 0), 4);
//                $row->subtotal = round((float) ($row->subtotal ?? 0), 4);
//                $row->discount = round((float) ($row->discount ?? 0), 4);
//                $row->tax = round((float) ($row->tax ?? 0), 4);
//                $row->total = round((float) ($row->total ?? 0), 4);
//                $row->sales_value = round((float) ($row->sales_value ?? 0), 4);
//                $row->base_total = round((float) ($row->base_total ?? 0), 4);
//                $row->base_sales_value = round((float) ($row->base_sales_value ?? 0), 4);
//                $row->cost_value = round((float) ($row->cost_value ?? 0), 4);
//                $row->base_cost_value = round((float) ($row->base_cost_value ?? 0), 4);
//                $row->estimated_profit = round((float) ($row->estimated_profit ?? 0), 4);
//                $row->gross_profit = round((float) ($row->gross_profit ?? 0), 4);
//                $row->gross_margin_percent = $salesValue > 0 ? round(((float) $row->gross_profit / $salesValue) * 100, 2) : 0;
//                return $row;
//            })
//            ->values();
//    }
//
//    private function cashierPerformance(string $companyId, array $filters)
//    {
//        return DB::table('sales_transactions')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->selectRaw('
//                sales_transactions.cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                sales_transactions.currency_code,
//                sales_transactions.base_currency_code,
//                COUNT(*) as transaction_count,
//                SUM(sales_transactions.subtotal_amount) as subtotal,
//                SUM(sales_transactions.discount_amount) as discount,
//                SUM(sales_transactions.tax_amount) as tax,
//                SUM(sales_transactions.total_amount) as total,
//                SUM(sales_transactions.base_total_amount) as base_total
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'sales_transactions.cashier_id',
//                'pos_users.name',
//                'pos_users.username',
//                'sales_transactions.currency_code',
//                'sales_transactions.base_currency_code'
//            )
//            ->orderByDesc('base_total')
//            ->get();
//    }
//
//    private function salePaymentsByCurrency(string $saleId)
//    {
//        return DB::table('sales_transaction_payments')
//            ->selectRaw('
//                payment_name,
//                money_code,
//                currency_code,
//                base_currency_code,
//                SUM(amount) as amount,
//                SUM(base_amount) as base_amount,
//                SUM(amount_tendered) as amount_tendered,
//                SUM(change_amount) as change_amount
//            ')
//            ->where('sales_transaction_id', $saleId)
//            ->groupBy(
//                'payment_name',
//                'money_code',
//                'currency_code',
//                'base_currency_code'
//            )
//            ->get();
//    }
//
//    private function saleLinesByCurrency(string $saleId)
//    {
//        return DB::table('receipt_lines')
//            ->selectRaw('
//                currency_code,
//                base_currency_code,
//                COUNT(*) as line_count,
//                SUM(line_quantity) as quantity,
//                SUM(line_subtotal) as subtotal,
//                SUM(line_discount) as discount,
//                SUM(line_tax) as tax,
//                SUM(line_total) as total,
//                SUM(base_line_total) as base_total
//            ')
//            ->where('sales_transaction_id', $saleId)
//            ->groupBy('currency_code', 'base_currency_code')
//            ->get();
//    }
//
//    private function saleTaxesByCode(string $saleId)
//    {
//        return DB::table('receipt_line_taxes')
//            ->selectRaw('
//                tax_code,
//                tax_percent,
//                currency_code,
//                SUM(tax_amount) as tax_amount,
//                SUM(sales_amount_with_tax) as sales_amount_with_tax
//            ')
//            ->where('sales_transaction_id', $saleId)
//            ->groupBy('tax_code', 'tax_percent', 'currency_code')
//            ->get();
//    }
//
//
//    private function baseCurrencyCode(string $companyId, array $filters): ?string
//    {
//        $row = DB::table('sales_transactions')
//            ->selectRaw('MAX(base_currency_code) as base_currency_code')
//            ->where('company_id', $companyId)
//            ->whereDate('transaction_date', '>=', $filters['date_from'])
//            ->whereDate('transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('shift_session_id', $filters['shift_session_id']))
//            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
//            ->first();
//
//        return $row->base_currency_code ?? null;
//    }
//
//    private function saleLineSummarySubquery(string $companyId, array $filters)
//    {
//        return DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                COUNT(*) as receipt_lines_count,
//                SUM(receipt_lines.line_quantity) as product_sold_quantity,
//                SUM(receipt_lines.line_subtotal) as sales_subtotal,
//                SUM(receipt_lines.line_discount) as sales_discount,
//                SUM(receipt_lines.line_tax) as sales_tax,
//                SUM(receipt_lines.line_total) as sales_value,
//                SUM(receipt_lines.base_line_total) as base_sales_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->when($filters['status'], fn ($q) => $q->where('sales_transactions.status', $filters['status']))
//            ->groupBy('receipt_lines.sales_transaction_id');
//    }
//
//    private function saleCostSummarySubquery(string $companyId, array $filters)
//    {
//        return DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)
//                ) as cost_value,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) *
//                    CASE
//                        WHEN COALESCE(product_costs.currency, sales_transactions.currency_code) = sales_transactions.base_currency_code
//                            THEN 1
//                        ELSE COALESCE(sales_transactions.exchange_rate, 1)
//                    END
//                ) as base_cost_value,
//
//                MAX(COALESCE(product_costs.currency, sales_transactions.currency_code)) as cost_currency_code
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->when($filters['status'], fn ($q) => $q->where('sales_transactions.status', $filters['status']))
//            ->groupBy('receipt_lines.sales_transaction_id');
//    }
//
//
//    private function salesListSummary(string $companyId, array $filters): array
//    {
//        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
//        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
//
//        $row = DB::table('sales_transactions')
//            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
//                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
//                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->selectRaw('
//                COUNT(*) as transaction_count,
//                SUM(COALESCE(line_summary.product_sold_quantity, 0)) as product_sold_quantity,
//
//                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0)) as sales_value,
//                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0)) as base_sales_value,
//
//                SUM(COALESCE(cost_summary.cost_value, 0)) as cost_value,
//                SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_cost_value,
//
//                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0))
//                    - SUM(COALESCE(cost_summary.cost_value, 0)) as estimated_profit,
//
//                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0))
//                    - SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_estimated_profit,
//
//                MAX(sales_transactions.currency_code) as currency_code,
//                MAX(sales_transactions.base_currency_code) as base_currency_code,
//                MAX(COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code)) as cost_currency_code
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->when($filters['status'], fn ($q) => $q->where('sales_transactions.status', $filters['status']))
//            ->first();
//
//        $baseCurrencyCode = $this->baseCurrencyCode($companyId, $filters);
//
//        return [
//            'transaction_count' => (int) ($row->transaction_count ?? 0),
//            'product_sold_quantity' => round((float) ($row->product_sold_quantity ?? 0), 4),
//
//            // Summary cards use base currency.
//            'sales_value' => round((float) ($row->base_sales_value ?? 0), 4),
//            'cost_value' => round((float) ($row->base_cost_value ?? 0), 4),
//            'estimated_profit' => round(
//                (float) (($row->base_sales_value ?? 0) - ($row->base_cost_value ?? 0)),
//                4
//            ),
//
//            'currency_code' => $baseCurrencyCode,
//            'cost_currency_code' => $baseCurrencyCode,
//            'base_currency_code' => $baseCurrencyCode,
//
//            // Transaction-currency reference values.
//            'transaction_sales_value' => round((float) ($row->sales_value ?? 0), 4),
//            'transaction_cost_value' => round((float) ($row->cost_value ?? 0), 4),
//            'transaction_estimated_profit' => round(
//                (float) (($row->sales_value ?? 0) - ($row->cost_value ?? 0)),
//                4
//            ),
//            'transaction_currency_code' => $row->currency_code ?? null,
//
//            'base_sales_value' => round((float) ($row->base_sales_value ?? 0), 4),
//            'base_cost_value' => round((float) ($row->base_cost_value ?? 0), 4),
//            'base_estimated_profit' => round(
//                (float) (($row->base_sales_value ?? 0) - ($row->base_cost_value ?? 0)),
//                4
//            ),
//        ];
//    }
//
//    private function dailyProfitSummary(string $companyId, array $filters): array
//    {
//        $summary = $this->salesListSummary($companyId, $filters);
//        $base = $this->baseSummary($companyId, $filters);
//
//        return array_merge($base, [
//            'product_sold_quantity' => $summary['product_sold_quantity'],
//
//            // These are already converted/consolidated into base currency by salesListSummary().
//            'sales_value' => $summary['sales_value'],
//            'cost_value' => $summary['cost_value'],
//            'estimated_profit' => $summary['estimated_profit'],
//
//            'base_sales_value' => $summary['base_sales_value'],
//            'base_cost_value' => $summary['base_cost_value'],
//            'base_estimated_profit' => $summary['base_estimated_profit'],
//            'currency_code' => $summary['base_currency_code'] ?? $summary['currency_code'],
//            'cost_currency_code' => $summary['base_currency_code'] ?? $summary['cost_currency_code'],
//            'base_currency_code' => $summary['base_currency_code'] ?? $summary['currency_code'],
//        ]);
//    }
//
//    private function dailySalesList(string $companyId, array $filters, int $perPage = 50)
//    {
//        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
//        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
//
//        return DB::table('sales_transactions')
//            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
//                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
//                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
//            ->selectRaw('
//                sales_transactions.id,
//                sales_transactions.invoice_number,
//                sales_transactions.transaction_date,
//                sales_transactions.currency_code,
//                sales_transactions.base_currency_code,
//                sales_transactions.cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                sales_transactions.till_id,
//                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
//                tills.till_code,
//                sales_transactions.payment_method,
//                sales_transactions.customer_name,
//                COALESCE(line_summary.receipt_lines_count, 0) as receipt_lines_count,
//                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,
//
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as sales_value,
//                COALESCE(cost_summary.cost_value, 0) as cost_value,
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0)
//                    - COALESCE(cost_summary.cost_value, 0) as estimated_profit,
//
//                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) as base_sales_value,
//                COALESCE(cost_summary.base_cost_value, 0) as base_cost_value,
//                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0)
//                    - COALESCE(cost_summary.base_cost_value, 0) as base_estimated_profit,
//
//                COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code) as cost_currency_code,
//                sales_transactions.status
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->where('sales_transactions.status', 'completed')
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->orderByDesc('sales_transactions.transaction_date')
//            ->paginate($perPage);
//    }
//
//    private function dailyCashierSummary(string $companyId, array $filters)
//    {
//        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
//        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
//
//        return DB::table('sales_transactions')
//            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
//                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
//                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
//            })
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->selectRaw('
//                sales_transactions.cashier_id,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                sales_transactions.currency_code,
//                sales_transactions.base_currency_code,
//                COUNT(*) as transaction_count,
//                SUM(COALESCE(line_summary.product_sold_quantity, 0)) as product_sold_quantity,
//                SUM(sales_transactions.subtotal_amount) as subtotal,
//                SUM(sales_transactions.discount_amount) as discount,
//                SUM(sales_transactions.tax_amount) as tax,
//
//                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0)) as sales_value,
//                SUM(COALESCE(cost_summary.cost_value, 0)) as cost_value,
//                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0))
//                    - SUM(COALESCE(cost_summary.cost_value, 0)) as estimated_profit,
//
//                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0)) as base_sales_value,
//                SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_cost_value,
//                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0))
//                    - SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_estimated_profit
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'sales_transactions.cashier_id',
//                'pos_users.name',
//                'pos_users.username',
//                'sales_transactions.currency_code',
//                'sales_transactions.base_currency_code'
//            )
//            ->orderByDesc('sales_value')
//            ->get();
//    }
//
//    private function dailyPaymentTypeSummary(string $companyId, array $filters)
//    {
//        return DB::table('sales_transaction_payments')
//            ->selectRaw('
//                payment_name,
//                money_code,
//                currency_code,
//                base_currency_code,
//                COUNT(*) as payment_count,
//                SUM(amount) as amount,
//                SUM(base_amount) as base_amount,
//                SUM(amount_tendered) as amount_tendered,
//                SUM(change_amount) as change_amount
//            ')
//            ->where('company_id', $companyId)
//            ->whereDate('created_at', '>=', $filters['date_from'])
//            ->whereDate('created_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('till_id', $filters['till_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('currency_code', $filters['currency_code']))
//            ->groupBy(
//                'payment_name',
//                'money_code',
//                'currency_code',
//                'base_currency_code'
//            )
//            ->orderBy('payment_name')
//            ->orderBy('currency_code')
//            ->get();
//    }
//
//    private function dailyCategorySummary(string $companyId, array $filters)
//    {
//        $lineByProductCategory = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
//            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//                products.category_id,
//                COALESCE(product_categories.name, "Uncategorised") as category_name,
//                receipt_lines.currency_code,
//                receipt_lines.base_currency_code,
//                SUM(receipt_lines.line_quantity) as quantity_sold,
//                SUM(receipt_lines.line_subtotal) as subtotal,
//                SUM(receipt_lines.line_discount) as discount,
//                SUM(receipt_lines.line_tax) as tax,
//                SUM(receipt_lines.line_total) as sales_value,
//                SUM(receipt_lines.base_line_total) as base_sales_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_lines.sales_transaction_id',
//                'receipt_lines.product_id',
//                'products.category_id',
//                'product_categories.name',
//                'receipt_lines.currency_code',
//                'receipt_lines.base_currency_code'
//            );
//
//        $costBySaleProduct = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)
//                ) as cost_value,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) *
//                    CASE
//                        WHEN COALESCE(product_costs.currency, sales_transactions.currency_code) = sales_transactions.base_currency_code
//                            THEN 1
//                        ELSE COALESCE(sales_transactions.exchange_rate, 1)
//                    END
//                ) as base_cost_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy('receipt_lines.sales_transaction_id', 'receipt_lines.product_id');
//
//        return DB::query()
//            ->fromSub($lineByProductCategory, 'category_lines')
//            ->leftJoinSub($costBySaleProduct, 'category_costs', function ($join) {
//                $join->on('category_costs.sales_transaction_id', '=', 'category_lines.sales_transaction_id')
//                    ->on('category_costs.product_id', '=', 'category_lines.product_id');
//            })
//            ->selectRaw('
//                category_lines.category_id,
//                category_lines.category_name,
//                category_lines.currency_code,
//                category_lines.base_currency_code,
//                COUNT(DISTINCT category_lines.sales_transaction_id) as receipt_count,
//                SUM(category_lines.quantity_sold) as quantity_sold,
//                SUM(category_lines.subtotal) as subtotal,
//                SUM(category_lines.discount) as discount,
//                SUM(category_lines.tax) as tax,
//                SUM(category_lines.sales_value) as sales_value,
//                SUM(category_lines.base_sales_value) as base_sales_value,
//                SUM(COALESCE(category_costs.cost_value, 0)) as cost_value,
//                SUM(COALESCE(category_costs.base_cost_value, 0)) as base_cost_value,
//                SUM(category_lines.sales_value) - SUM(COALESCE(category_costs.cost_value, 0)) as estimated_profit
//            ')
//            ->groupBy(
//                'category_lines.category_id',
//                'category_lines.category_name',
//                'category_lines.currency_code',
//                'category_lines.base_currency_code'
//            )
//            ->orderByDesc('sales_value')
//            ->get();
//    }
//
//    private function dailyProductSales(string $companyId, array $filters, int $limit = 100)
//    {
//        $productLines = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//                receipt_lines.line_name as product_name,
//                receipt_lines.currency_code,
//                receipt_lines.base_currency_code,
//                SUM(receipt_lines.line_quantity) as quantity_sold,
//                SUM(receipt_lines.line_subtotal) as subtotal,
//                SUM(receipt_lines.line_discount) as discount,
//                SUM(receipt_lines.line_tax) as tax,
//                SUM(receipt_lines.line_total) as sales_value,
//                SUM(receipt_lines.base_line_total) as base_sales_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_lines.sales_transaction_id',
//                'receipt_lines.product_id',
//                'receipt_lines.line_name',
//                'receipt_lines.currency_code',
//                'receipt_lines.base_currency_code'
//            );
//
//        $costBySaleProduct = DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw('
//                receipt_lines.sales_transaction_id,
//                receipt_lines.product_id,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0)
//                ) as cost_value,
//
//                SUM(
//                    receipt_lines.line_quantity *
//                    COALESCE(NULLIF(product_costs.average_cost, 0), product_costs.last_cost, 0) *
//                    CASE
//                        WHEN COALESCE(product_costs.currency, sales_transactions.currency_code) = sales_transactions.base_currency_code
//                            THEN 1
//                        ELSE COALESCE(sales_transactions.exchange_rate, 1)
//                    END
//                ) as base_cost_value
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy('receipt_lines.sales_transaction_id', 'receipt_lines.product_id');
//
//        return DB::query()
//            ->fromSub($productLines, 'product_lines')
//            ->leftJoinSub($costBySaleProduct, 'product_costs', function ($join) {
//                $join->on('product_costs.sales_transaction_id', '=', 'product_lines.sales_transaction_id')
//                    ->on('product_costs.product_id', '=', 'product_lines.product_id');
//            })
//            ->selectRaw('
//                product_lines.product_id,
//                product_lines.product_name,
//                product_lines.currency_code,
//                product_lines.base_currency_code,
//                COUNT(DISTINCT product_lines.sales_transaction_id) as receipt_count,
//                SUM(product_lines.quantity_sold) as quantity_sold,
//                SUM(product_lines.subtotal) as subtotal,
//                SUM(product_lines.discount) as discount,
//                SUM(product_lines.tax) as tax,
//                SUM(product_lines.sales_value) as sales_value,
//                SUM(product_lines.base_sales_value) as base_sales_value,
//                SUM(COALESCE(product_costs.cost_value, 0)) as cost_value,
//                SUM(COALESCE(product_costs.base_cost_value, 0)) as base_cost_value,
//                SUM(product_lines.sales_value) - SUM(COALESCE(product_costs.cost_value, 0)) as estimated_profit
//            ')
//            ->groupBy(
//                'product_lines.product_id',
//                'product_lines.product_name',
//                'product_lines.currency_code',
//                'product_lines.base_currency_code'
//            )
//            ->orderByDesc('sales_value')
//            ->limit($limit)
//            ->get();
//    }
//
//    private function cashupForShift(string $companyId, string $shiftSessionId)
//    {
//        return DB::table('pos_cashups')
//            ->where('company_id', $companyId)
//            ->where('shift_session_id', $shiftSessionId)
//            ->first();
//    }
//
//    private function normalizeCashupForReports($cashup)
//    {
//        $salesSummary = $this->toArrayValue($cashup->sales_summary ?? null);
//        $cashoutSummary = $this->toArrayValue($cashup->cashout_summary ?? null);
//        $nestedCash = $salesSummary['cash'] ?? [];
//
//        $cashoutRows = $cashoutSummary['cash_out_rows'] ?? [];
//
//        $cashoutTotal = $this->numberValue($cashup->cashout_total ?? 0);
//
//        if ($cashoutTotal <= 0) {
//            $cashoutTotal = $this->numberValue($cashoutSummary['cashout_total'] ?? 0);
//        }
//
//        if ($cashoutTotal <= 0) {
//            $cashoutTotal = $this->numberValue($nestedCash['cashout_total'] ?? 0);
//        }
//
//        if ($cashoutTotal <= 0 && count($cashoutRows) > 0) {
//            $cashoutTotal = collect($cashoutRows)->sum(fn ($row) => $this->numberValue($row['amount'] ?? 0));
//        }
//
//        $cashoutCount = (int) (($cashup->cashout_count ?? 0) ?: ($cashoutSummary['cashout_count'] ?? count($cashoutRows)));
//
//        $expectedAfterCashouts = $this->numberValue($cashup->expected_cash_after_cashouts ?? 0);
//
//        if ($expectedAfterCashouts <= 0) {
//            $expectedAfterCashouts = $this->numberValue($nestedCash['expected_cash_after_cashouts'] ?? 0);
//        }
//
//        if ($expectedAfterCashouts <= 0) {
//            $expectedAfterCashouts = $this->numberValue($cashup->expected_cash ?? ($nestedCash['expected_cash'] ?? 0)) - $cashoutTotal;
//        }
//
//        $cashup->cashout_total = round($cashoutTotal, 4);
//        $cashup->cashout_count = $cashoutCount;
//        $cashup->expected_cash_after_cashouts = round($expectedAfterCashouts, 4);
//
//        return $cashup;
//    }
//
//    private function toArrayValue($value): array
//    {
//        if (is_array($value)) {
//            return $value;
//        }
//
//        if (is_object($value)) {
//            return json_decode(json_encode($value), true) ?: [];
//        }
//
//        if (is_string($value) && $value !== '') {
//            return json_decode($value, true) ?: [];
//        }
//
//        return [];
//    }
//
//    private function numberValue($value): float
//    {
//        return is_numeric($value) ? (float) $value : 0.0;
//    }
//
//
//    public function profitAndLoss(Request $request)
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//
//        $productSummary = $this->profitAndLossProductSummary($company->id, $filters);
//        $productLines = $this->profitAndLossProductLines($company->id, $filters, (int) ($request->per_page ?? 100));
//        $cashouts = $this->profitAndLossCashouts($company->id, $filters, $request);
//
//        $salesValue = round((float) $productSummary->sum('base_sales_value'), 4);
//        $costValue = round((float) $productSummary->sum('base_cost_value'), 4);
//        $grossProfit = round($salesValue - $costValue, 4);
//
//        $cashoutTotal = round((float) ($cashouts['summary']['cashout_total'] ?? 0), 4);
//        $cashinTotal = round((float) ($cashouts['summary']['cashin_total'] ?? 0), 4);
//
//        $netProfitAfterCashouts = round($grossProfit - $cashoutTotal + $cashinTotal, 4);
//
//        $baseCurrencyCode = $this->baseCurrencyCode($company->id, $filters);
//
//        return response()->json([
//            'message' => 'Profit and loss report fetched successfully.',
//            'data' => [
//                'summary' => [
//                    'product_count' => $productSummary->count(),
//                    'quantity_sold' => round((float) $productSummary->sum('quantity_sold'), 4),
//
//                    'sales_value' => $salesValue,
//                    'cost_value' => $costValue,
//                    'gross_profit' => $grossProfit,
//
//                    'cashout_total' => $cashoutTotal,
//                    'cashin_total' => $cashinTotal,
//                    'net_profit_after_cashouts' => $netProfitAfterCashouts,
//
//                    'gross_margin_percent' => $salesValue > 0
//                        ? round(($grossProfit / $salesValue) * 100, 2)
//                        : 0,
//
//                    'net_margin_after_cashouts_percent' => $salesValue > 0
//                        ? round(($netProfitAfterCashouts / $salesValue) * 100, 2)
//                        : 0,
//
//                    'base_currency_code' => $baseCurrencyCode,
//                    'currency_code' => $baseCurrencyCode,
//                    'cost_currency_code' => $baseCurrencyCode,
//                ],
//
//                'product_summary' => $productSummary,
//                'product_lines' => $productLines,
//                'cashouts' => $cashouts,
//            ],
//        ]);
//    }
//
//    private function profitAndLossProductSummary(string $companyId, array $filters)
//    {
//        $costExpression = '
//        COALESCE(
//            NULLIF(MAX(product_costs.average_cost), 0),
//            NULLIF(MAX(product_costs.last_cost), 0),
//            0
//        )
//    ';
//
//        $costCurrencyExpression = '
//        COALESCE(MAX(product_costs.currency), receipt_lines.currency_code)
//    ';
//
//        return DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
//            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw("
//            receipt_lines.product_id,
//            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
//            COALESCE(products.code, receipt_lines.product_code) as product_code,
//            COALESCE(products.sku, receipt_lines.sku) as sku,
//            COALESCE(product_categories.name, 'Uncategorised') as category_name,
//
//            receipt_lines.currency_code,
//            receipt_lines.base_currency_code,
//
//            COUNT(DISTINCT sales_transactions.id) as receipt_count,
//            COUNT(receipt_lines.id) as line_count,
//
//            SUM(receipt_lines.line_quantity) as quantity_sold,
//
//            AVG(receipt_lines.line_price) as average_selling_price,
//            MIN(receipt_lines.line_price) as minimum_selling_price,
//            MAX(receipt_lines.line_price) as maximum_selling_price,
//
//            {$costExpression} as average_cost,
//            {$costCurrencyExpression} as cost_currency_code,
//
//            SUM(receipt_lines.line_subtotal) as subtotal,
//            SUM(receipt_lines.line_discount) as discount,
//            SUM(receipt_lines.line_tax) as tax,
//            SUM(receipt_lines.line_total) as sales_value,
//            SUM(receipt_lines.base_line_total) as base_sales_value,
//
//            SUM(receipt_lines.line_quantity) * {$costExpression} as cost_value,
//
//            SUM(receipt_lines.line_quantity) *
//            {$costExpression} *
//            CASE
//                WHEN {$costCurrencyExpression} = sales_transactions.base_currency_code
//                    THEN 1
//                ELSE COALESCE(MAX(sales_transactions.exchange_rate), 1)
//            END as base_cost_value,
//
//            SUM(receipt_lines.line_total) -
//            (
//                SUM(receipt_lines.line_quantity) * {$costExpression}
//            ) as gross_profit,
//
//            SUM(receipt_lines.base_line_total) -
//            (
//                SUM(receipt_lines.line_quantity) *
//                {$costExpression} *
//                CASE
//                    WHEN {$costCurrencyExpression} = sales_transactions.base_currency_code
//                        THEN 1
//                    ELSE COALESCE(MAX(sales_transactions.exchange_rate), 1)
//                END
//            ) as base_gross_profit
//        ")
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->where('sales_transactions.sale_type', 'sale')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_lines.product_id',
//                DB::raw("COALESCE(products.name, receipt_lines.line_name, 'Unknown Product')"),
//                DB::raw('COALESCE(products.code, receipt_lines.product_code)'),
//                DB::raw('COALESCE(products.sku, receipt_lines.sku)'),
//                DB::raw("COALESCE(product_categories.name, 'Uncategorised')"),
//                'receipt_lines.currency_code',
//                'receipt_lines.base_currency_code',
//                'sales_transactions.base_currency_code'
//            )
//            ->orderByDesc('base_gross_profit')
//            ->get()
//            ->map(function ($row) {
//                $salesValue = (float) ($row->sales_value ?? 0);
//                $baseSalesValue = (float) ($row->base_sales_value ?? 0);
//
//                $row->quantity_sold = round((float) ($row->quantity_sold ?? 0), 4);
//                $row->average_selling_price = round((float) ($row->average_selling_price ?? 0), 4);
//                $row->minimum_selling_price = round((float) ($row->minimum_selling_price ?? 0), 4);
//                $row->maximum_selling_price = round((float) ($row->maximum_selling_price ?? 0), 4);
//                $row->average_cost = round((float) ($row->average_cost ?? 0), 4);
//
//                $row->subtotal = round((float) ($row->subtotal ?? 0), 4);
//                $row->discount = round((float) ($row->discount ?? 0), 4);
//                $row->tax = round((float) ($row->tax ?? 0), 4);
//                $row->sales_value = round($salesValue, 4);
//                $row->base_sales_value = round($baseSalesValue, 4);
//
//                $row->cost_value = round((float) ($row->cost_value ?? 0), 4);
//                $row->base_cost_value = round((float) ($row->base_cost_value ?? 0), 4);
//                $row->gross_profit = round((float) ($row->gross_profit ?? 0), 4);
//                $row->base_gross_profit = round((float) ($row->base_gross_profit ?? 0), 4);
//
//                $row->gross_margin_percent = $salesValue > 0
//                    ? round(((float) $row->gross_profit / $salesValue) * 100, 2)
//                    : 0;
//
//                $row->base_gross_margin_percent = $baseSalesValue > 0
//                    ? round(((float) $row->base_gross_profit / $baseSalesValue) * 100, 2)
//                    : 0;
//
//                return $row;
//            })
//            ->values();
//    }
//
//    private function profitAndLossProductLines(string $companyId, array $filters, int $perPage = 100)
//    {
//        return DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
//            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw("
//            receipt_lines.id,
//            receipt_lines.sales_transaction_id,
//            sales_transactions.invoice_number,
//            sales_transactions.transaction_date,
//            sales_transactions.branch_id,
//            sales_transactions.till_id,
//            COALESCE(tills.till_name, tills.till_code, 'Unknown Till') as till_name,
//            tills.till_code,
//            sales_transactions.cashier_id,
//            COALESCE(pos_users.name, pos_users.username, 'Unknown') as cashier_name,
//            sales_transactions.shift_session_id,
//
//            receipt_lines.product_id,
//            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
//            COALESCE(products.code, receipt_lines.product_code) as product_code,
//            COALESCE(products.sku, receipt_lines.sku) as sku,
//            COALESCE(product_categories.name, 'Uncategorised') as category_name,
//
//            receipt_lines.currency_code,
//            receipt_lines.base_currency_code,
//
//            receipt_lines.line_quantity as quantity_sold,
//            receipt_lines.line_price as selling_price,
//            receipt_lines.line_subtotal as subtotal,
//            receipt_lines.line_discount as discount,
//            receipt_lines.line_tax as tax,
//            receipt_lines.line_total as sales_value,
//            receipt_lines.base_line_total as base_sales_value,
//
//            COALESCE(
//                NULLIF(product_costs.average_cost, 0),
//                NULLIF(product_costs.last_cost, 0),
//                0
//            ) as average_cost,
//
//            COALESCE(product_costs.currency, receipt_lines.currency_code) as cost_currency_code,
//
//            receipt_lines.line_quantity *
//            COALESCE(
//                NULLIF(product_costs.average_cost, 0),
//                NULLIF(product_costs.last_cost, 0),
//                0
//            ) as cost_value,
//
//            receipt_lines.line_quantity *
//            COALESCE(
//                NULLIF(product_costs.average_cost, 0),
//                NULLIF(product_costs.last_cost, 0),
//                0
//            ) *
//            CASE
//                WHEN COALESCE(product_costs.currency, receipt_lines.currency_code) = sales_transactions.base_currency_code
//                    THEN 1
//                ELSE COALESCE(sales_transactions.exchange_rate, 1)
//            END as base_cost_value,
//
//            receipt_lines.line_total -
//            (
//                receipt_lines.line_quantity *
//                COALESCE(
//                    NULLIF(product_costs.average_cost, 0),
//                    NULLIF(product_costs.last_cost, 0),
//                    0
//                )
//            ) as gross_profit,
//
//            receipt_lines.base_line_total -
//            (
//                receipt_lines.line_quantity *
//                COALESCE(
//                    NULLIF(product_costs.average_cost, 0),
//                    NULLIF(product_costs.last_cost, 0),
//                    0
//                ) *
//                CASE
//                    WHEN COALESCE(product_costs.currency, receipt_lines.currency_code) = sales_transactions.base_currency_code
//                        THEN 1
//                    ELSE COALESCE(sales_transactions.exchange_rate, 1)
//                END
//            ) as base_gross_profit
//        ")
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->where('sales_transactions.sale_type', 'sale')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->orderByDesc('sales_transactions.transaction_date')
//            ->paginate($perPage);
//    }
//
//
//
//    private function profitAndLossProductSummaryRows(string $companyId, array $filters)
//    {
//        $unitCostExpression = '
//        COALESCE(
//            NULLIF(MAX(product_costs.average_cost), 0),
//            NULLIF(MAX(product_costs.last_cost), 0),
//            0
//        )
//    ';
//
//        $costCurrencyExpression = '
//        COALESCE(MAX(product_costs.currency), MAX(receipt_lines.currency_code))
//    ';
//
//        $exchangeRateExpression = 'COALESCE(MAX(sales_transactions.exchange_rate), 1)';
//
//        return DB::table('receipt_lines')
//            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
//            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
//            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
//            ->leftJoin('product_costs', function ($join) {
//                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
//                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
//                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
//            })
//            ->selectRaw("
//            receipt_lines.product_id,
//            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
//            COALESCE(products.code, receipt_lines.product_code) as product_code,
//            COALESCE(products.sku, receipt_lines.sku) as sku,
//            COALESCE(product_categories.name, 'Uncategorised') as category_name,
//
//            MAX(receipt_lines.currency_code) as currency_code,
//            MAX(receipt_lines.base_currency_code) as base_currency_code,
//            {$costCurrencyExpression} as cost_currency_code,
//
//            COUNT(DISTINCT sales_transactions.id) as receipt_count,
//            COUNT(receipt_lines.id) as line_count,
//            SUM(receipt_lines.line_quantity) as quantity_sold,
//
//            CASE
//                WHEN SUM(receipt_lines.line_quantity) > 0
//                    THEN SUM(receipt_lines.line_total) / SUM(receipt_lines.line_quantity)
//                ELSE 0
//            END as average_selling_price,
//
//            MIN(receipt_lines.line_price) as minimum_selling_price,
//            MAX(receipt_lines.line_price) as maximum_selling_price,
//            {$unitCostExpression} as average_cost,
//
//            SUM(receipt_lines.line_subtotal) as subtotal,
//            SUM(receipt_lines.line_discount) as discount,
//            SUM(receipt_lines.line_tax) as tax,
//            SUM(receipt_lines.line_total) as sales_value,
//            SUM(receipt_lines.base_line_total) as base_sales_value,
//
//            SUM(receipt_lines.line_quantity) * {$unitCostExpression} as cost_value,
//
//            SUM(receipt_lines.line_quantity) * {$unitCostExpression} *
//            CASE
//                WHEN {$costCurrencyExpression} = MAX(sales_transactions.base_currency_code)
//                    THEN 1
//                ELSE {$exchangeRateExpression}
//            END as base_cost_value,
//
//            SUM(receipt_lines.line_total) -
//            (SUM(receipt_lines.line_quantity) * {$unitCostExpression}) as gross_profit,
//
//            SUM(receipt_lines.base_line_total) -
//            (
//                SUM(receipt_lines.line_quantity) * {$unitCostExpression} *
//                CASE
//                    WHEN {$costCurrencyExpression} = MAX(sales_transactions.base_currency_code)
//                        THEN 1
//                    ELSE {$exchangeRateExpression}
//                END
//            ) as base_gross_profit
//        ")
//            ->where('sales_transactions.company_id', $companyId)
//            ->where('sales_transactions.status', 'completed')
//            ->where('sales_transactions.sale_type', 'sale')
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('receipt_lines.currency_code', $filters['currency_code']))
//            ->groupBy(
//                'receipt_lines.product_id',
//                DB::raw("COALESCE(products.name, receipt_lines.line_name, 'Unknown Product')"),
//                DB::raw('COALESCE(products.code, receipt_lines.product_code)'),
//                DB::raw('COALESCE(products.sku, receipt_lines.sku)'),
//                DB::raw("COALESCE(product_categories.name, 'Uncategorised')")
//            )
//            ->orderByDesc('base_gross_profit');
//    }
//
//
//
//    private function profitAndLossCashouts(string $companyId, array $filters): array
//    {
//        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
//        $cashInTypes = ['cash_in', 'float_in'];
//
//        $baseQuery = DB::table('pos_cash_drawer_movements')
//            ->where('company_id', $companyId)
//            ->whereNull('deleted_at')
//            ->whereDate('occurred_at', '>=', $filters['date_from'])
//            ->whereDate('occurred_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('currency_code', strtoupper($filters['currency_code'])));
//
//        $summaryByTypeCurrency = (clone $baseQuery)
//            ->selectRaw('
//            movement_type,
//            currency_code,
//            COUNT(*) as movement_count,
//            SUM(amount) as amount
//        ')
//            ->groupBy('movement_type', 'currency_code')
//            ->orderBy('movement_type')
//            ->orderBy('currency_code')
//            ->get()
//            ->map(fn ($row) => [
//                'movement_type' => $row->movement_type,
//                'currency_code' => $row->currency_code,
//                'movement_count' => (int) $row->movement_count,
//                'amount' => round((float) $row->amount, 4),
//            ])
//            ->values();
//
//        $cashoutTotal = $summaryByTypeCurrency
//            ->filter(fn ($row) => in_array($row['movement_type'], $cashOutTypes, true))
//            ->sum('amount');
//
//        $cashinTotal = $summaryByTypeCurrency
//            ->filter(fn ($row) => in_array($row['movement_type'], $cashInTypes, true))
//            ->sum('amount');
//
//        $byDate = (clone $baseQuery)
//            ->selectRaw('
//            DATE(occurred_at) as movement_date,
//            currency_code,
//            movement_type,
//            COUNT(*) as movement_count,
//            SUM(amount) as amount
//        ')
//            ->groupByRaw('DATE(occurred_at), currency_code, movement_type')
//            ->orderBy('movement_date')
//            ->orderBy('currency_code')
//            ->orderBy('movement_type')
//            ->get()
//            ->map(fn ($row) => [
//                'movement_date' => $row->movement_date,
//                'currency_code' => $row->currency_code,
//                'movement_type' => $row->movement_type,
//                'movement_count' => (int) $row->movement_count,
//                'amount' => round((float) $row->amount, 4),
//            ])
//            ->values();
//
//        return [
//            'summary' => [
//                'cashout_total' => round((float) $cashoutTotal, 4),
//                'cashin_total' => round((float) $cashinTotal, 4),
//                'net_cash_movement' => round((float) ($cashinTotal - $cashoutTotal), 4),
//                'movement_count' => (int) $summaryByTypeCurrency->sum('movement_count'),
//                'summary_by_type_currency' => $summaryByTypeCurrency,
//            ],
//            'by_date' => $byDate,
//        ];
//    }
//
//
//
//
//    private function profitAndLossCashoutsByDate(string $companyId, array $filters)
//    {
//        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
//
//        return DB::table('pos_cash_drawer_movements')
//            ->selectRaw('
//            DATE(occurred_at) as movement_date,
//            currency_code,
//            movement_type,
//            COUNT(*) as movement_count,
//            SUM(amount) as amount
//        ')
//            ->where('company_id', $companyId)
//            ->whereNull('deleted_at')
//            ->whereIn('movement_type', $cashOutTypes)
//            ->whereDate('occurred_at', '>=', $filters['date_from'])
//            ->whereDate('occurred_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('currency_code', strtoupper($filters['currency_code'])))
//            ->groupByRaw('DATE(occurred_at), currency_code, movement_type')
//            ->orderBy('movement_date')
//            ->orderBy('currency_code')
//            ->orderBy('movement_type')
//            ->get()
//            ->map(fn ($row) => [
//                'movement_date' => $row->movement_date,
//                'currency_code' => $row->currency_code,
//                'movement_type' => $row->movement_type,
//                'movement_count' => (int) $row->movement_count,
//                'amount' => round((float) $row->amount, 4),
//            ])
//            ->values();
//    }
//    private function profitAndLossCashoutRows(string $companyId, array $filters, int $perPage = 100)
//    {
//        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
//
//        return DB::table('pos_cash_drawer_movements')
//            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'pos_cash_drawer_movements.cashier_id')
//            ->leftJoin('pos_users as creators', 'creators.id', '=', 'pos_cash_drawer_movements.created_by')
//            ->leftJoin('pos_users as approvers', 'approvers.id', '=', 'pos_cash_drawer_movements.approved_by')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'pos_cash_drawer_movements.till_id')
//            ->selectRaw('
//            pos_cash_drawer_movements.id,
//            pos_cash_drawer_movements.company_id,
//            pos_cash_drawer_movements.branch_id,
//            pos_cash_drawer_movements.till_id,
//            COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
//            tills.till_code,
//
//            pos_cash_drawer_movements.shift_session_id,
//            pos_cash_drawer_movements.cashier_id,
//            COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name,
//
//            pos_cash_drawer_movements.movement_type,
//            pos_cash_drawer_movements.amount,
//            pos_cash_drawer_movements.currency_code,
//            pos_cash_drawer_movements.reason,
//            pos_cash_drawer_movements.notes,
//            pos_cash_drawer_movements.reference_no,
//
//            pos_cash_drawer_movements.approved_by,
//            COALESCE(approvers.name, approvers.username, "N/A") as approved_by_name,
//
//            pos_cash_drawer_movements.created_by,
//            COALESCE(creators.name, creators.username, "Unknown") as created_by_name,
//
//            pos_cash_drawer_movements.occurred_at,
//            pos_cash_drawer_movements.created_at
//        ')
//            ->where('pos_cash_drawer_movements.company_id', $companyId)
//            ->whereNull('pos_cash_drawer_movements.deleted_at')
//            ->whereIn('pos_cash_drawer_movements.movement_type', $cashOutTypes)
//            ->whereDate('pos_cash_drawer_movements.occurred_at', '>=', $filters['date_from'])
//            ->whereDate('pos_cash_drawer_movements.occurred_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('pos_cash_drawer_movements.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('pos_cash_drawer_movements.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('pos_cash_drawer_movements.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('pos_cash_drawer_movements.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('pos_cash_drawer_movements.currency_code', strtoupper($filters['currency_code'])))
//            ->orderByDesc('pos_cash_drawer_movements.occurred_at')
//            ->paginate($perPage);
//    }
//
//
//
//    public function dashboardExcel(Request $request) { return $this->salesReportDownload($request, 'dashboard', 'excel'); }
//    public function dashboardPdf(Request $request) { return $this->salesReportDownload($request, 'dashboard', 'pdf'); }
//    public function dailySalesExcel(Request $request) { return $this->salesReportDownload($request, 'daily-sales', 'excel'); }
//    public function dailySalesPdf(Request $request) { return $this->salesReportDownload($request, 'daily-sales', 'pdf'); }
//    public function salesListExcel(Request $request) { return $this->salesReportDownload($request, 'sales-list', 'excel'); }
//    public function salesListPdf(Request $request) { return $this->salesReportDownload($request, 'sales-list', 'pdf'); }
//    public function paymentsExcel(Request $request) { return $this->salesReportDownload($request, 'payments', 'excel'); }
//    public function paymentsPdf(Request $request) { return $this->salesReportDownload($request, 'payments', 'pdf'); }
//    public function productsExcel(Request $request) { return $this->salesReportDownload($request, 'products', 'excel'); }
//    public function productsPdf(Request $request) { return $this->salesReportDownload($request, 'products', 'pdf'); }
//    public function cashiersExcel(Request $request) { return $this->salesReportDownload($request, 'cashiers', 'excel'); }
//    public function cashiersPdf(Request $request) { return $this->salesReportDownload($request, 'cashiers', 'pdf'); }
//    public function shiftsExcel(Request $request) { return $this->salesReportDownload($request, 'shifts', 'excel'); }
//    public function shiftsPdf(Request $request) { return $this->salesReportDownload($request, 'shifts', 'pdf'); }
//    public function dayEndsExcel(Request $request) { return $this->salesReportDownload($request, 'day-ends', 'excel'); }
//    public function dayEndsPdf(Request $request) { return $this->salesReportDownload($request, 'day-ends', 'pdf'); }
//
//    private function salesReportDownload(Request $request, string $reportKey, string $format)
//    {
//        $data = $this->salesReportExportData($request, $reportKey);
//        $filename = $data['slug'] . '-' . now()->format('Ymd-His') . ($format === 'pdf' ? '.pdf' : '.xlsx');
//
//        if ($format === 'pdf') {
//            return $this->downloadSalesEnterprisePdf($data['title'], $filename, $data['sections']);
//        }
//
//        return $this->downloadSalesEnterpriseSpreadsheet($data['title'], $filename, $data['sheets']);
//    }
//
//    private function salesReportExportData(Request $request, string $reportKey): array
//    {
//        $company = app('company');
//        $filters = $this->filters($request);
//        $limit = (int) $request->input('export_limit', 10000);
//        $businessMode = $this->resolveSalesBusinessMode($company->id, $filters['branch_id'], $request->input('business_mode'));
//        $baseCurrencyCode = $this->baseCurrencyCode($company->id, $filters) ?: 'USD';
//        $summaryRows = [
//            ['Business Mode', $businessMode['label']],
//            ['BOH Enabled', $businessMode['boh_enabled'] ? 'Yes' : 'No'],
//            ['Fast Food Mode Enabled', $businessMode['fast_food_mode_enabled'] ? 'Yes' : 'No'],
//            ['Date From', $filters['date_from']],
//            ['Date To', $filters['date_to']],
//            ['Currency Filter', $filters['currency_code'] ?: 'All'],
//            ['Base Currency', $baseCurrencyCode],
//            ['Generated At', now()->toDateTimeString()],
//        ];
//
//        if ($reportKey === 'dashboard') {
//            $title = 'Sales Dashboard Report';
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Currency Summary', 'headers' => ['Currency', 'Base Currency', 'Transactions', 'Subtotal', 'Discount', 'Tax', 'Total', 'Base Total'], 'rows' => $this->rowsFromCollection($this->salesByCurrency($company->id, $filters), ['currency_code', 'base_currency_code', 'transaction_count', 'subtotal', 'discount', 'tax', 'total', 'base_total'])],
//                ['title' => 'Payment Summary', 'headers' => ['Payment', 'Money Code', 'Currency', 'Base Currency', 'Count', 'Amount', 'Base Amount', 'Tendered', 'Change'], 'rows' => $this->rowsFromCollection($this->paymentSummaryByCurrency($company->id, $filters), ['payment_name', 'money_code', 'currency_code', 'base_currency_code', 'payment_count', 'amount', 'base_amount', 'amount_tendered', 'change_amount'])],
//                ['title' => 'Top Products With Cost', 'headers' => ['Product', 'Currency', 'Receipts', 'Qty Sold', 'Sales Value', 'Cost Value', 'Gross Profit', 'Gross Margin %'], 'rows' => $this->rowsFromCollection($this->topProducts($company->id, $filters, 100), ['product_name', 'currency_code', 'receipt_count', 'quantity_sold', 'sales_value', 'cost_value', 'gross_profit', 'gross_margin_percent'])],
//            ];
//            return $this->buildReportPayload($title, 'sales-dashboard-report', $sections);
//        }
//
//        if ($reportKey === 'daily-sales') {
//            $businessDate = $request->business_date ?: $request->date ?: $filters['date_from'];
//            $filters['date_from'] = $businessDate;
//            $filters['date_to'] = $businessDate;
//            $filters['status'] = 'completed';
//            $summary = $this->dailyProfitSummary($company->id, $filters);
//            $summaryRows = array_merge($summaryRows, $this->arraySummaryRows($summary));
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Cashier Summary', 'headers' => ['Cashier', 'Currency', 'Transactions', 'Products Sold', 'Subtotal', 'Discount', 'Tax', 'Sales Value', 'Cost Value', 'Estimated Profit'], 'rows' => $this->rowsFromCollection($this->dailyCashierSummary($company->id, $filters), ['cashier_name', 'currency_code', 'transaction_count', 'product_sold_quantity', 'subtotal', 'discount', 'tax', 'sales_value', 'cost_value', 'estimated_profit'])],
//                ['title' => 'Payment Summary', 'headers' => ['Payment', 'Money Code', 'Currency', 'Base Currency', 'Count', 'Amount', 'Base Amount', 'Tendered', 'Change'], 'rows' => $this->rowsFromCollection($this->dailyPaymentTypeSummary($company->id, $filters), ['payment_name', 'money_code', 'currency_code', 'base_currency_code', 'payment_count', 'amount', 'base_amount', 'amount_tendered', 'change_amount'])],
//                ['title' => 'Product Sales With Cost', 'headers' => ['Product', 'Currency', 'Receipts', 'Qty Sold', 'Sales Value', 'Cost Value', 'Estimated Profit'], 'rows' => $this->rowsFromCollection($this->dailyProductSales($company->id, $filters, $limit), ['product_name', 'currency_code', 'receipt_count', 'quantity_sold', 'sales_value', 'cost_value', 'estimated_profit'])],
//            ];
//            return $this->buildReportPayload('Daily Sales Report', 'daily-sales-report', $sections);
//        }
//
//        if ($reportKey === 'sales-list') {
//            $records = $this->salesListExportRows($company->id, $filters, $limit);
//            $summaryRows = array_merge($summaryRows, $this->arraySummaryRows($this->salesListSummary($company->id, $filters)));
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Sales Transactions', 'headers' => ['Receipt', 'Date', 'Cashier', 'Till', 'Customer', 'Payment', 'Currency', 'Products Sold', 'Invoiced Value', 'Money Received', 'Credit Paid', 'Credit Outstanding', 'Cost Value', 'Estimated Profit', 'Status'], 'rows' => $this->rowsFromCollection($records, ['invoice_number', 'transaction_date', 'cashier_name', 'till_name', 'customer_name', 'payment_method', 'currency_code', 'product_sold_quantity', 'invoiced_value', 'sales_value', 'credit_paid_value', 'credit_outstanding_value', 'cost_value', 'estimated_profit', 'status'])],
//            ];
//            return $this->buildReportPayload('Sales List Report', 'sales-list-report', $sections);
//        }
//
//        if ($reportKey === 'payments') {
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Payment Summary', 'headers' => ['Payment', 'Money Code', 'Currency', 'Base Currency', 'Count', 'Amount', 'Base Amount', 'Tendered', 'Change'], 'rows' => $this->rowsFromCollection($this->paymentSummaryByCurrency($company->id, $filters), ['payment_name', 'money_code', 'currency_code', 'base_currency_code', 'payment_count', 'amount', 'base_amount', 'amount_tendered', 'change_amount'])],
//            ];
//            return $this->buildReportPayload('Payment Summary Report', 'payment-summary-report', $sections);
//        }
//
//        if ($reportKey === 'products') {
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Product Sales With Cost', 'headers' => ['Product', 'Currency', 'Receipts', 'Qty Sold', 'Average Cost', 'Sales Value', 'Cost Value', 'Gross Profit', 'Gross Margin %'], 'rows' => $this->rowsFromCollection($this->topProducts($company->id, $filters, $limit), ['product_name', 'currency_code', 'receipt_count', 'quantity_sold', 'average_cost', 'sales_value', 'cost_value', 'gross_profit', 'gross_margin_percent'])],
//            ];
//            return $this->buildReportPayload('Product Sales Report', 'product-sales-report', $sections);
//        }
//
//        if ($reportKey === 'cashiers') {
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Cashier Sales', 'headers' => ['Cashier', 'Currency', 'Base Currency', 'Transactions', 'Subtotal', 'Discount', 'Tax', 'Total', 'Base Total'], 'rows' => $this->rowsFromCollection($this->cashierPerformance($company->id, $filters), ['cashier_name', 'currency_code', 'base_currency_code', 'transaction_count', 'subtotal', 'discount', 'tax', 'total', 'base_total'])],
//            ];
//            return $this->buildReportPayload('Cashier Sales Report', 'cashier-sales-report', $sections);
//        }
//
//        if ($reportKey === 'shifts') {
//            $records = $this->shiftSalesExportRows($company->id, $filters, $limit);
//            $sections = [
//                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//                ['title' => 'Shift Sales', 'headers' => ['Shift', 'Cashier', 'Opened At', 'Closed At', 'Status', 'Transactions', 'Base Net Sales', 'Cashup Variance'], 'rows' => $records],
//            ];
//            return $this->buildReportPayload('Shift Sales Report', 'shift-sales-report', $sections);
//        }
//
//        $dayEnd = $this->dayEndExportRows($company->id, $filters);
//        $sections = [
//            ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
//            ['title' => 'Sales by Day and Currency', 'headers' => ['Date', 'Currency', 'Base Currency', 'Transactions', 'Subtotal', 'Discount', 'Tax', 'Total', 'Base Total'], 'rows' => $dayEnd['sales_rows']],
//            ['title' => 'Cashups by Day', 'headers' => ['Date', 'Cashups', 'Expected Cash', 'Cashouts', 'Expected After Cashouts', 'Counted Cash', 'Cash Variance', 'Expected Total', 'Counted Total', 'Total Variance'], 'rows' => $dayEnd['cashup_rows']],
//        ];
//        return $this->buildReportPayload('Day Ends Report', 'day-ends-report', $sections);
//    }
//
//    private function salesListExportRows(string $companyId, array $filters, int $limit)
//    {
//        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
//        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
//
//        return DB::table('sales_transactions')
//            ->leftJoinSub($lineSummary, 'line_summary', fn ($join) => $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id'))
//            ->leftJoinSub($costSummary, 'cost_summary', fn ($join) => $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id'))
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
//            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
//            ->selectRaw('
//                sales_transactions.invoice_number,
//                sales_transactions.transaction_date,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
//                sales_transactions.customer_name,
//                sales_transactions.payment_method,
//                sales_transactions.currency_code,
//                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as sales_value,
//                COALESCE(cost_summary.cost_value, 0) as cost_value,
//                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) - COALESCE(cost_summary.cost_value, 0) as estimated_profit,
//                sales_transactions.status
//            ')
//            ->where('sales_transactions.company_id', $companyId)
//            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
//            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('sales_transactions.branch_id', $filters['branch_id']))
//            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
//            ->when($filters['currency_code'], fn ($q) => $q->where('sales_transactions.currency_code', $filters['currency_code']))
//            ->orderByDesc('sales_transactions.transaction_date')
//            ->limit($limit)
//            ->get();
//    }
//
//    private function shiftSalesExportRows(string $companyId, array $filters, int $limit): array
//    {
//        return DB::table('pos_user_shift_sessions')
//            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
//            ->leftJoin('pos_cashups', function ($join) {
//                $join->on('pos_cashups.company_id', '=', 'pos_user_shift_sessions.company_id')
//                    ->on('pos_cashups.shift_session_id', '=', 'pos_user_shift_sessions.id');
//            })
//            ->where('pos_user_shift_sessions.company_id', $companyId)
//            ->whereDate('pos_user_shift_sessions.opened_at', '>=', $filters['date_from'])
//            ->whereDate('pos_user_shift_sessions.opened_at', '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('pos_user_shift_sessions.branch_id', $filters['branch_id']))
//            ->when($filters['cashier_id'], fn ($q) => $q->where('pos_user_shift_sessions.pos_user_id', $filters['cashier_id']))
//            ->when($filters['shift_session_id'], fn ($q) => $q->where('pos_user_shift_sessions.id', $filters['shift_session_id']))
//            ->selectRaw('
//                pos_user_shift_sessions.shift_name,
//                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
//                pos_user_shift_sessions.opened_at,
//                pos_user_shift_sessions.closed_at,
//                pos_user_shift_sessions.status,
//                pos_cashups.transaction_count,
//                pos_cashups.expected_total,
//                pos_cashups.counted_total,
//                pos_cashups.total_variance
//            ')
//            ->orderByDesc('pos_user_shift_sessions.opened_at')
//            ->limit($limit)
//            ->get()
//            ->map(fn ($row) => [
//                $row->shift_name ?: 'Shift',
//                $row->cashier_name,
//                $this->salesReportDateTime($row->opened_at),
//                $this->salesReportDateTime($row->closed_at),
//                $row->status,
//                (int) ($row->transaction_count ?? 0),
//                $this->salesReportAmount($row->expected_total),
//                $this->salesReportAmount($row->total_variance),
//            ])->values()->all();
//    }
//
//    private function dayEndExportRows(string $companyId, array $filters): array
//    {
//        $salesRows = $this->baseSalesQuery($companyId, $filters)
//            ->where('sales_transactions.status', 'completed')
//            ->selectRaw('DATE(sales_transactions.transaction_date) as business_date, sales_transactions.currency_code, sales_transactions.base_currency_code, COUNT(*) as transaction_count, SUM(sales_transactions.subtotal_amount) as subtotal, SUM(sales_transactions.discount_amount) as discount, SUM(sales_transactions.tax_amount) as tax, SUM(sales_transactions.total_amount) as total, SUM(sales_transactions.base_total_amount) as base_total')
//            ->groupByRaw('DATE(sales_transactions.transaction_date), sales_transactions.currency_code, sales_transactions.base_currency_code')
//            ->orderBy('business_date')
//            ->get();
//
//        $dateColumn = Schema::hasColumn('pos_cashups', 'cashup_closed_at') ? 'cashup_closed_at' : 'closed_at';
//        $cashupRows = DB::table('pos_cashups')
//            ->where('company_id', $companyId)
//            ->whereDate($dateColumn, '>=', $filters['date_from'])
//            ->whereDate($dateColumn, '<=', $filters['date_to'])
//            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
//            ->get()
//            ->groupBy(fn ($row) => substr((string) ($row->{$dateColumn} ?? $row->closed_at), 0, 10))
//            ->map(fn ($rows, $date) => [
//                $date,
//                $rows->count(),
//                $this->salesReportAmount($rows->sum('expected_cash')),
//                $this->salesReportAmount($rows->sum('cashout_total')),
//                $this->salesReportAmount($rows->sum('expected_cash_after_cashouts')),
//                $this->salesReportAmount($rows->sum('counted_cash')),
//                $this->salesReportAmount($rows->sum('cash_variance')),
//                $this->salesReportAmount($rows->sum('expected_total')),
//                $this->salesReportAmount($rows->sum('counted_total')),
//                $this->salesReportAmount($rows->sum('total_variance')),
//            ])->values()->all();
//
//        return [
//            'sales_rows' => $this->rowsFromCollection($salesRows, ['business_date', 'currency_code', 'base_currency_code', 'transaction_count', 'subtotal', 'discount', 'tax', 'total', 'base_total']),
//            'cashup_rows' => $cashupRows,
//        ];
//    }
//
//    private function buildReportPayload(string $title, string $slug, array $sections): array
//    {
//        return [
//            'title' => $title,
//            'slug' => $slug,
//            'sections' => $sections,
//            'sheets' => collect($sections)->map(fn ($section) => [
//                'title' => $section['title'],
//                'headers' => $section['headers'],
//                'rows' => $section['rows'],
//            ])->values()->all(),
//        ];
//    }
//
//    private function rowsFromCollection($collection, array $fields): array
//    {
//        return collect($collection)->map(function ($row) use ($fields) {
//            return collect($fields)->map(function ($field) use ($row) {
//                $value = is_array($row) ? ($row[$field] ?? null) : ($row->{$field} ?? null);
//                if (str_contains($field, 'date') || str_contains($field, '_at')) {
//                    return $this->salesReportDateTime($value);
//                }
//                if (is_numeric($value) && !str_contains($field, 'count') && !str_contains($field, 'receipt')) {
//                    return $this->salesReportAmount($value);
//                }
//                return $value ?? '';
//            })->values()->all();
//        })->values()->all();
//    }
//
//    private function arraySummaryRows(array $summary): array
//    {
//        return collect($summary)->map(fn ($value, $key) => [str_replace('_', ' ', ucwords((string) $key, '_')), is_numeric($value) ? $this->salesReportAmount($value) : (string) $value])->values()->all();
//    }
//
//    private function resolveSalesBusinessMode(string $companyId, ?string $branchId, ?string $requestedMode = null): array
//    {
//        $bohEnabled = $this->salesSettingEnabledAny($companyId, $branchId, ['boh_enabled', 'enable_boh', 'back_of_house_enabled', 'enable_back_of_house', 'is_boh_enabled']);
//        $fastFoodModeEnabled = $this->salesSettingEnabledAny($companyId, $branchId, ['fast_food_mode_enabled', 'fast_food_mode', 'enable_fast_food_mode', 'is_fast_food_mode', 'restaurant_fast_food_mode']);
//        $detected = ($bohEnabled && $fastFoodModeEnabled) ? 'fast_food_boh' : 'retail';
//        $mode = in_array($requestedMode, ['fast_food_boh', 'retail'], true) ? $requestedMode : $detected;
//        return [
//            'business_mode' => $mode,
//            'label' => $mode === 'fast_food_boh' ? 'Fast Food / BOH' : 'Retail',
//            'boh_enabled' => $bohEnabled,
//            'fast_food_mode_enabled' => $fastFoodModeEnabled,
//        ];
//    }
//
//    private function salesSettingEnabledAny(string $companyId, ?string $branchId, array $keys): bool
//    {
//        foreach ($keys as $key) {
//            $setting = GeneralSetting::query()
//                ->where('company_id', $companyId)
//                ->where('setting_key', $key)
//                ->where('is_active', true)
//                ->where(function ($query) use ($branchId) {
//                    $query->whereNull('branch_id');
//                    if ($branchId) $query->orWhere('branch_id', $branchId);
//                })
//                ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
//                ->first();
//            if ($setting && filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN)) return true;
//        }
//        return false;
//    }
//
//    private function downloadSalesEnterpriseSpreadsheet(string $title, string $filename, array $sheets)
//    {
//        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
//            return response()->json(['message' => 'PhpSpreadsheet is not installed.'], 500);
//        }
//
//        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
//        $spreadsheet->getProperties()->setCreator('SALEXA POS')->setTitle($title)->setSubject($title);
//
//        foreach ($sheets as $sheetIndex => $sheetConfig) {
//            $sheet = $sheetIndex === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($sheetIndex);
//            $sheet->setTitle(substr((string) $sheetConfig['title'], 0, 31));
//            $headers = array_values($sheetConfig['headers'] ?? []);
//            $rows = array_values($sheetConfig['rows'] ?? []);
//            $lastColumn = $this->salesExcelColumn(max(count($headers), 1));
//
//            $sheet->setCellValue('A1', $title);
//            $sheet->setCellValue('A2', (string) $sheetConfig['title']);
//            $sheet->setCellValue('A3', 'Generated: ' . now()->toDateTimeString());
//            $sheet->mergeCells("A1:{$lastColumn}1");
//            $sheet->mergeCells("A2:{$lastColumn}2");
//
//            foreach ($headers as $i => $header) {
//                $sheet->setCellValue($this->salesExcelColumn($i + 1) . '5', $header);
//            }
//
//            foreach ($rows as $rowIndex => $row) {
//                foreach (array_values($row) as $colIndex => $value) {
//                    $sheet->setCellValue($this->salesExcelColumn($colIndex + 1) . ($rowIndex + 6), $value);
//                }
//            }
//
//            $lastRow = max(count($rows) + 5, 5);
//            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(16);
//            $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true)->setSize(12);
//            $sheet->getStyle("A5:{$lastColumn}5")->getFont()->setBold(true);
//            $sheet->getStyle("A5:{$lastColumn}5")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF002B49');
//            $sheet->getStyle("A5:{$lastColumn}5")->getFont()->getColor()->setARGB('FFFFFFFF');
//            $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setARGB('FFD7E0E8');
//            $sheet->freezePane('A6');
//            $sheet->setAutoFilter("A5:{$lastColumn}{$lastRow}");
//            for ($i = 1; $i <= max(count($headers), 1); $i++) {
//                $sheet->getColumnDimension($this->salesExcelColumn($i))->setAutoSize(true);
//            }
//        }
//
//        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
//        return response()->streamDownload(fn () => $writer->save('php://output'), $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
//    }
//
//    private function downloadSalesEnterprisePdf(string $title, string $filename, array $sections)
//    {
//        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
//            return response()->json(['message' => 'DomPDF is not installed.'], 500);
//        }
//        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->salesEnterprisePdfHtml($title, $sections))->setPaper('a4', 'landscape');
//        return $pdf->download($filename);
//    }
//
//    private function salesEnterprisePdfHtml(string $title, array $sections): string
//    {
//        $e = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
//        $html = '';
//        foreach ($sections as $section) {
//            $headers = array_values($section['headers'] ?? []);
//            $rows = array_values($section['rows'] ?? []);
//            $html .= '<section><h2>' . $e($section['title'] ?? '') . '</h2><table><thead><tr>' . collect($headers)->map(fn ($h) => '<th>' . $e($h) . '</th>')->implode('') . '</tr></thead><tbody>';
//            if (empty($rows)) {
//                $html .= '<tr><td colspan="' . max(count($headers), 1) . '" class="empty">No records found.</td></tr>';
//            } else {
//                foreach (array_slice($rows, 0, 700) as $row) {
//                    $html .= '<tr>' . collect(array_values($row))->map(fn ($v) => '<td>' . $e($v) . '</td>')->implode('') . '</tr>';
//                }
//            }
//            $html .= '</tbody></table></section>';
//        }
//        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:18px;}body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:9.5px;}h1{margin:0;color:#002B49;font-size:20px;text-transform:uppercase;}h2{font-size:12px;text-transform:uppercase;color:#002B49;margin:12px 0 6px;}table{width:100%;border-collapse:collapse;margin-bottom:10px;}th{background:#002B49;color:#fff;text-align:left;font-size:8px;padding:5px;border:1px solid #0F3B5A;}td{padding:4px;border:1px solid #D7E0E8;vertical-align:top;}tr:nth-child(even) td{background:#F8FAFC;}.cover{border:1px solid #002B49;background:#EEF3F7;padding:12px;margin-bottom:10px;}.meta{font-size:9px;color:#5D6B78;margin-top:4px;}.empty{text-align:center;color:#667085;padding:15px;}</style></head><body><div class="cover"><h1>' . $e($title) . '</h1><div class="meta">Generated ' . $e(now()->toDateTimeString()) . ' / SALEXA POS Enterprise Reports</div></div>' . $html . '</body></html>';
//    }
//
//    private function salesExcelColumn(int $index): string
//    {
//        $column = '';
//        while ($index > 0) {
//            $index--;
//            $column = chr(65 + ($index % 26)) . $column;
//            $index = intdiv($index, 26);
//        }
//        return $column ?: 'A';
//    }
//
//    private function salesReportAmount($value): string
//    {
//        return number_format((float) ($value ?? 0), 2, '.', '');
//    }
//
//    private function salesReportDateTime($value): string
//    {
//        if (!$value) return '';
//        try { return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s'); } catch (\Throwable $e) { return (string) $value; }
//    }
//
//}
//
//
//
//
//


namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\SalesTransaction;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Services\Cashups\CashupFinancialVisibilityService;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Sales\SalesCostingService;
use Carbon\Carbon;
use function App\Helpers\hasPermission;


class SalesReportController extends Controller
{
    private EnterpriseReportDownloadService $reportDownloads;
    private CashupFinancialVisibilityService $cashupFinancialVisibility;

    public function __construct(
        ?EnterpriseReportDownloadService $reportDownloads = null,
        ?CashupFinancialVisibilityService $cashupFinancialVisibility = null
    )
    {
        $this->reportDownloads = $reportDownloads ?? new EnterpriseReportDownloadService();
        $this->cashupFinancialVisibility = $cashupFinancialVisibility ?? new CashupFinancialVisibilityService();
    }

    /*
     * Till table note:
     * This project uses pos_tills, not tills.
     * All report joins alias pos_tills as tills so existing select aliases remain stable.
     */
    public function dashboard(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $currentSummary = $this->salesListSummary($company->id, $filters);
        $comparisonFilters = $filters;
        $comparisonFilters['date_from'] = Carbon::parse($filters['date_from'])->subMonthNoOverflow()->toDateString();
        $comparisonFilters['date_to'] = Carbon::parse($filters['date_to'])->subMonthNoOverflow()->toDateString();
        $previousSummary = $this->salesListSummary($company->id, $comparisonFilters);

        return response()->json([
            'message' => 'Sales dashboard fetched successfully.',
            'data' => [
                'costing' => $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters)),
                'base_summary' => $this->baseSummary($company->id, $filters),
                // Financial summary uses the same centralized costing query as the
                // sales register. This keeps dashboard profit consistent with every
                // downloadable sales/profit report.
                'management_summary' => $currentSummary,
                'activity_comparison' => $this->activityComparison($filters, $comparisonFilters, $currentSummary, $previousSummary),
                'currency_summary' => $this->salesByCurrency($company->id, $filters),
                'payment_summary' => $this->paymentSummaryByCurrency($company->id, $filters),
                'tax_summary' => $this->taxSummary($company->id, $filters),
                'hourly_sales' => $this->hourlySales($company->id, $filters),
                'daily_sales' => $this->dailySales($company->id, $filters),
                'top_products' => $this->topProducts($company->id, $filters, 20),
                'cashier_performance' => $this->cashierPerformance($company->id, $filters),
            ],
        ]);
    }


    public function shiftSessions(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $query = DB::table('pos_user_shift_sessions')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->selectRaw('
            pos_user_shift_sessions.id,
            pos_user_shift_sessions.company_id,
            pos_user_shift_sessions.branch_id,
            pos_user_shift_sessions.pos_user_id as cashier_id,
            COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
            pos_user_shift_sessions.shift_name,
            pos_user_shift_sessions.opened_at,
            pos_user_shift_sessions.closed_at,
            pos_user_shift_sessions.status
            ')
            ->where('pos_user_shift_sessions.company_id', $company->id)
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'pos_user_shift_sessions.branch_id'))
            ->when($request->filled('cashier_id'), function ($q) use ($request) {
                $q->where('pos_user_shift_sessions.pos_user_id', $request->cashier_id);
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('pos_user_shift_sessions.status', $request->status);
            })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                $q->whereDate('pos_user_shift_sessions.opened_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                $q->whereDate('pos_user_shift_sessions.opened_at', '<=', $request->date_to);
            })
            ->orderByDesc('pos_user_shift_sessions.opened_at');

        return response()->json([
            'message' => 'Shift sessions fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 100),
        ]);
    }

    public function salesList(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $lineSummary = $this->saleLineSummarySubquery($company->id, $filters);
        $costSummary = $this->saleCostSummarySubquery($company->id, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($company->id, $filters);

        $query = DB::table('sales_transactions')
            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($saleCreditAllocations, 'sale_credit_allocations', function ($join) {
                $join->on('sale_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
            ->selectRaw('
                sales_transactions.id,
                sales_transactions.company_id,
                sales_transactions.branch_id,
                sales_transactions.till_id,
                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
                tills.till_code,
                sales_transactions.shift_id,
                sales_transactions.shift_session_id,
                sales_transactions.cashier_id,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                sales_transactions.user_id,
                sales_transactions.invoice_number,
                sales_transactions.transaction_date,
                sales_transactions.document_type,
                sales_transactions.sale_type,
                sales_transactions.status,
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                sales_transactions.exchange_rate,
                sales_transactions.subtotal_amount,
                sales_transactions.discount_amount,
                sales_transactions.tax_amount,
                sales_transactions.total_amount,
                sales_transactions.base_total_amount,
                sales_transactions.payment_method,
                sales_transactions.amount_tendered,
                sales_transactions.change_amount,
                sales_transactions.customer_name,
                sales_transactions.customer_phone,
                COALESCE(line_summary.receipt_lines_count, 0) as receipt_lines_count,
                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,
                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as invoiced_value,
                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) as base_invoiced_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END as sales_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END as base_sales_value,
                COALESCE(sale_credit_allocations.credit_paid_value, 0) as credit_paid_value,
                GREATEST(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) ELSE 0 END - COALESCE(sale_credit_allocations.credit_paid_value, 0), 0) as credit_outstanding_value,
                COALESCE(cost_summary.cost_value, 0) as invoiced_cost_value,
                CASE
                    WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN
                        COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.cost_value, 0)
                END as cost_value,
                COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code) as cost_currency_code,
                (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END)
                    - (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0)) ELSE COALESCE(cost_summary.cost_value, 0) END) as estimated_profit,
                (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END)
                    - (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.base_cost_value, cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0)) ELSE COALESCE(cost_summary.base_cost_value, cost_summary.cost_value, 0) END) as base_estimated_profit
            ')
            ->where('sales_transactions.company_id', $company->id)
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->when($filters['status'], fn($q) => $q->where('sales_transactions.status', $filters['status']))
            ->orderByDesc('sales_transactions.transaction_date');

        return response()->json([
            'message' => 'Sales list fetched successfully.',
            'costing' => $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters)),
            'summary' => $this->salesListSummary($company->id, $filters),
            'data' => $query->paginate($request->per_page ?? 25),
        ]);
    }

    public function dailyReport(Request $request)
    {
        $company = app('company');

        $businessDate = $request->business_date
            ?: $request->date
                ?: $request->date_from
                    ?: now()->toDateString();

        $filters = $this->filters($request);
        $filters['date_from'] = $businessDate;
        $filters['date_to'] = $businessDate;
        $filters['status'] = 'completed';

        return response()->json([
            'message' => 'Daily sales report fetched successfully.',
            'data' => [
                'business_date' => $businessDate,
                'costing' => $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters)),
                'summary' => $this->dailyProfitSummary($company->id, $filters),
                'cashier_summary' => $this->dailyCashierSummary($company->id, $filters),
                'payment_type_summary' => $this->dailyPaymentTypeSummary($company->id, $filters),
                'category_summary' => $this->dailyCategorySummary($company->id, $filters),
                'product_sales' => $this->dailyProductSales($company->id, $filters, (int)($request->product_limit ?? 100)),
                'sales' => $this->dailySalesList($company->id, $filters, (int)($request->per_page ?? 50)),
            ],
        ]);
    }

    public function saleDrilldown(Request $request, string $saleId)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $sale = SalesTransaction::with([
            'branch',
            'till',
            'cashier',
            'payments',
            'receiptLines.taxes',
            ])
            ->where('company_id', $company->id)
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->where('id', $saleId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Sale not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Sale drill-down fetched successfully.',
            'data' => [
                'sale' => $sale,
                'payments_by_currency' => $this->salePaymentsByCurrency($saleId),
                'lines_by_currency' => $this->saleLinesByCurrency($saleId),
                'taxes_by_code' => $this->saleTaxesByCode($saleId),
            ],
        ]);
    }

    public function paymentSummary(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        return response()->json([
            'message' => 'Payment summary fetched successfully.',
            'data' => $this->paymentSummaryByCurrency($company->id, $filters),
        ]);
    }

    public function productSales(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        return response()->json([
            'message' => 'Product sales report fetched successfully.',
            'costing' => $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters)),
            'data' => $this->topProducts(
                $company->id,
                $filters,
                (int)($request->per_page ?? 50)
            ),
        ]);
    }

    public function productDrilldown(Request $request, string $productId)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $costing = app(SalesCostingService::class);
        $hospitality = $costing->isHospitality($company->id, $this->costingContextBranchId($filters));
        $unitCost = $costing->unitCostExpression($hospitality);
        $lineCost = $costing->lineCostExpression($hospitality);
        $costCurrency = $costing->costCurrencyExpression($hospitality, 'sales_transactions.base_currency_code');
        $reportingUnitCost = $this->costInSaleCurrencyExpression($unitCost, $costCurrency);
        $reportingLineCost = $this->costInSaleCurrencyExpression($lineCost, $costCurrency);
        $baseLineCost = $this->costInBaseCurrencyExpression($lineCost, $costCurrency);

        $rows = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
            ->leftJoin('pos_user_shift_sessions', 'pos_user_shift_sessions.id', '=', 'sales_transactions.shift_session_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            });

        if ($hospitality) {
            $rows
                ->leftJoinSub($costing->recipeMovementCostsByReceiptLine($company->id), 'recipe_line_costs', function ($join) {
                    $join->on('recipe_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                        ->on('recipe_line_costs.receipt_line_id', '=', 'receipt_lines.id');
                })
                ->leftJoinSub($costing->activeRecipeUnitCosts($company->id, $filters['branch_id']), 'active_recipe_costs', function ($join) {
                    $join->on('active_recipe_costs.menu_product_id', '=', 'receipt_lines.product_id')
                        ->on('active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id');
                });
        } else {
            $rows->leftJoinSub($costing->retailMovementCostsByReceiptLine($company->id), 'retail_line_costs', function ($join) {
                $join->on('retail_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                    ->on('retail_line_costs.receipt_line_id', '=', 'receipt_lines.id');
            });
        }

        $rows = $rows->selectRaw("
                sales_transactions.id as sale_id,
                sales_transactions.invoice_number,
                sales_transactions.transaction_date,
                sales_transactions.cashier_id,
                COALESCE(pos_users.name, pos_users.username, 'Unknown') as cashier_name,
                sales_transactions.till_id,
                COALESCE(tills.till_name, tills.till_code, 'Unknown Till') as till_name,
                tills.till_code,
                sales_transactions.shift_session_id,
                pos_user_shift_sessions.shift_name,
                receipt_lines.line_name,
                receipt_lines.line_quantity,
                receipt_lines.currency_code,
                receipt_lines.line_price,
                receipt_lines.line_subtotal,
                receipt_lines.line_discount,
                receipt_lines.line_tax,
                receipt_lines.line_total,
                {$reportingUnitCost} as average_cost,
                receipt_lines.currency_code as cost_currency_code,
                {$reportingLineCost} as cost_value,
                {$baseLineCost} as base_cost_value,
                receipt_lines.line_total - ({$reportingLineCost}) as gross_profit,
                receipt_lines.base_line_total - ({$baseLineCost}) as base_gross_profit,
                {$unitCost} as source_average_cost,
                {$lineCost} as source_cost_value,
                {$costCurrency} as source_cost_currency_code
            ")
            ->where('sales_transactions.company_id', $company->id)
            ->where('sales_transactions.status', 'completed')
            ->where('receipt_lines.product_id', $productId)
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->orderByDesc('sales_transactions.transaction_date')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Product drill-down fetched successfully.',
            'costing' => $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters)),
            'data' => $rows,
        ]);
    }

    public function cashierSales(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        return response()->json([
            'message' => 'Cashier sales report fetched successfully.',
            'data' => $this->cashierPerformance($company->id, $filters),
        ]);
    }

    public function branchTurnover(Request $request)
    {
        $company = app('company');
        $filters = $this->scopedAccessibleReportFilters($request, (string)$company->id, $this->filters($request));

        $rows = $this->branchTurnoverRows((string)$company->id, $filters);
        $baseCurrencyCode = $this->baseCurrencyCode((string)$company->id, $filters)
            ?: $this->defaultReportingCurrencyCode((string)$company->id, null);

        return response()->json([
            'message' => 'Branch turnover report fetched successfully.',
            'data' => [
                'filters' => $filters,
                'base_currency_code' => $baseCurrencyCode,
                'summary' => [
                    'branch_count' => $rows->pluck('branch_id')->filter()->unique()->count(),
                    'transaction_count' => (int)$rows->sum('transaction_count'),
                    'base_sales_value' => round((float)$rows->sum('base_sales_value'), 4),
                    'base_cost_value' => round((float)$rows->sum('base_cost_value'), 4),
                    'base_profit_value' => round((float)$rows->sum('base_profit_value'), 4),
                ],
                'rows' => $rows,
            ],
        ]);
    }

    private function branchTurnoverRows(string $companyId, array $filters)
    {
        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);

        $rows = $this->baseSalesQuery($companyId, $filters)
            ->leftJoinSub($costSummary, 'branch_cost_summary', fn($join) => $join->on('branch_cost_summary.sales_transaction_id', '=', 'sales_transactions.id'))
            ->leftJoin('company_branches', 'company_branches.id', '=', 'sales_transactions.branch_id')
            ->where('sales_transactions.status', 'completed')
            ->selectRaw('
                sales_transactions.branch_id,
                COALESCE(company_branches.branch_name, company_branches.branch_code, "Unassigned") as branch_name,
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                COUNT(DISTINCT sales_transactions.id) as transaction_count,
                SUM(sales_transactions.total_amount) as sales_value,
                SUM(sales_transactions.base_total_amount) as base_sales_value,
                SUM(COALESCE(branch_cost_summary.cost_value, 0)) as cost_value,
                SUM(COALESCE(branch_cost_summary.base_cost_value, 0)) as base_cost_value,
                SUM(sales_transactions.total_amount - COALESCE(branch_cost_summary.cost_value, 0)) as profit_value,
                SUM(sales_transactions.base_total_amount - COALESCE(branch_cost_summary.base_cost_value, 0)) as base_profit_value
            ')
            ->groupBy(
                'sales_transactions.branch_id',
                'company_branches.branch_name',
                'company_branches.branch_code',
                'sales_transactions.currency_code',
                'sales_transactions.base_currency_code'
            )
            ->orderBy('branch_name')
            ->orderBy('sales_transactions.currency_code')
            ->get()
            ->map(function ($row) {
                foreach (['sales_value', 'base_sales_value', 'cost_value', 'base_cost_value', 'profit_value', 'base_profit_value'] as $field) {
                    $row->{$field} = round((float)($row->{$field} ?? 0), 4);
                }
                $row->transaction_count = (int)($row->transaction_count ?? 0);
                return $row;
            });

        $baseCurrencyCode = $this->baseCurrencyCode($companyId, $filters)
            ?: $this->defaultReportingCurrencyCode($companyId, null)
            ?: 'USD';
        $currencyCodes = collect($filters['currency_codes'] ?? [])
            ->merge($rows->pluck('currency_code'))
            ->map(fn($currencyCode) => strtoupper(trim((string)$currencyCode)))
            ->filter()->unique()->values();
        if ($currencyCodes->isEmpty()) $currencyCodes->push($baseCurrencyCode);

        $branches = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when(!empty($filters['branch_ids']), fn($query) => $query->whereIn('id', $filters['branch_ids']))
            ->orderBy('branch_name')
            ->get(['id', 'branch_name', 'branch_code']);
        $existing = $rows->keyBy(fn($row) => (string)$row->branch_id . '|' . strtoupper((string)$row->currency_code));

        foreach ($branches as $branch) {
            foreach ($currencyCodes as $currencyCode) {
                $key = (string)$branch->id . '|' . $currencyCode;
                if ($existing->has($key)) continue;
                $rows->push((object)[
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->branch_name ?: ($branch->branch_code ?: 'Branch'),
                    'currency_code' => $currencyCode,
                    'base_currency_code' => $baseCurrencyCode,
                    'transaction_count' => 0,
                    'sales_value' => 0,
                    'base_sales_value' => 0,
                    'cost_value' => 0,
                    'base_cost_value' => 0,
                    'profit_value' => 0,
                    'base_profit_value' => 0,
                ]);
            }
        }

        return $rows->sortBy([
            ['branch_name', 'asc'],
            ['currency_code', 'asc'],
        ])->values();
    }

    private function accessibleReportBranchIds(Request $request, string $companyId): array
    {
        return app(BusinessModeBranchScopeService::class)->accessibleBranchIds(
            $request->user(),
            $companyId
        );
    }

    private function scopedAccessibleReportFilters(Request $request, string $companyId, array $filters): array
    {
        $requestedBranchIds = !empty($filters['branch_ids'])
            ? $filters['branch_ids']
            : ($filters['branch_id'] ? [$filters['branch_id']] : []);
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            $companyId,
            $requestedBranchIds
        );

        $filters['branch_ids'] = $scope['branch_ids'];
        $filters['costing_context_branch_id'] = $scope['context_branch_id'];
        $filters['business_mode'] = $scope['business_mode'];

        return $filters;
    }

    public function shiftSales(Request $request)
    {
        $company = app('company');
        $filters = $this->scopedAccessibleReportFilters($request, (string) $company->id, $this->filters($request));
        $canViewCashupAmounts = $this->canViewCashupFinancialAmounts((string) $company->id);

        $sessions = DB::table('pos_user_shift_sessions')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->selectRaw('
                pos_user_shift_sessions.id as shift_session_id,
                pos_user_shift_sessions.shift_configuration_id,
                pos_user_shift_sessions.shift_name,
                pos_user_shift_sessions.company_id,
                pos_user_shift_sessions.branch_id,
                pos_user_shift_sessions.pos_user_id as cashier_id,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                pos_user_shift_sessions.opening_float,
                pos_user_shift_sessions.closing_cash,
                pos_user_shift_sessions.opened_at,
                pos_user_shift_sessions.closed_at,
                pos_user_shift_sessions.status
            ')
            ->where('pos_user_shift_sessions.company_id', $company->id)
            ->whereDate('pos_user_shift_sessions.opened_at', '>=', $filters['date_from'])
            ->whereDate('pos_user_shift_sessions.opened_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'pos_user_shift_sessions.branch_id'))
            ->when($filters['cashier_id'], fn($q) => $q->where('pos_user_shift_sessions.pos_user_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('pos_user_shift_sessions.id', $filters['shift_session_id']))
            ->orderByDesc('pos_user_shift_sessions.opened_at')
            ->paginate($request->per_page ?? 25);

        $rows = collect($sessions->items())->map(function ($session) use ($company, $filters, $canViewCashupAmounts) {
            $shiftFilters = array_merge($filters, [
                'shift_session_id' => $session->shift_session_id,
            ]);

            $cashup = $this->cashupForShift($company->id, $session->shift_session_id);
            if (!$canViewCashupAmounts) {
                $session = $this->cashupFinancialVisibility->redact($session);
                $cashup = $this->cashupFinancialVisibility->redact($cashup);
            }

            $baseSummary = $this->baseSummary($company->id, $shiftFilters);
            $currencySummary = $this->salesByCurrency($company->id, $shiftFilters);
            $paymentSummary = $this->paymentSummaryByCurrency($company->id, $shiftFilters);

            if (!$canViewCashupAmounts) {
                $baseSummary = $this->cashupFinancialVisibility->redact($baseSummary);
                $currencySummary = $this->cashupFinancialVisibility->redact($currencySummary);
                $paymentSummary = $this->cashupFinancialVisibility->redact($paymentSummary);
            }

            return [
                'shift_session' => $session,
                'base_summary' => $baseSummary,
                'currency_summary' => $currencySummary,
                'payment_summary' => $paymentSummary,
                'cashup' => $cashup,
            ];
        });

        return response()->json([
            'message' => 'Shift sales report fetched successfully.',
            'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewCashupAmounts),
            'data' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'data' => $rows,
            ],
        ]);
    }

    public function shiftSalesDrilldown(Request $request, string $shiftSessionId)
    {
        $request->merge([
            'shift_session_id' => $shiftSessionId,
        ]);

        return $this->salesList($request);
    }

    public function dayEnds(Request $request)
    {
        $company = app('company');
        $filters = $this->scopedAccessibleReportFilters($request, (string) $company->id, $this->filters($request));
        $canViewCashupAmounts = $this->canViewCashupFinancialAmounts((string) $company->id);

        $salesByDayCurrency = DB::table('sales_transactions')
            ->selectRaw('
                DATE(transaction_date) as business_date,
                currency_code,
                base_currency_code,
                COUNT(*) as transaction_count,
                SUM(subtotal_amount) as subtotal,
                SUM(discount_amount) as discount,
                SUM(tax_amount) as tax,
                SUM(total_amount) as total,
                SUM(base_total_amount) as base_total
            ')
            ->where('company_id', $company->id)
            ->where('status', 'completed')
            ->whereDate('transaction_date', '>=', $filters['date_from'])
            ->whereDate('transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('currency_code', $filters['currency_codes']))
            ->groupByRaw('DATE(transaction_date), currency_code, base_currency_code')
            ->orderBy('business_date')
            ->get();

        /*
         * Do cashup aggregation in PHP so old records still work even when
         * top-level pos_cashups.cashout_total is 0 but nested JSON has:
         * sales_summary.cash.cashout_total
         * cashout_summary.cashout_total
         */
        $cashupRows = DB::table('pos_cashups')
            ->where('company_id', $company->id)
            ->whereDate('closed_at', '>=', $filters['date_from'])
            ->whereDate('closed_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->orderBy('closed_at')
            ->get()
            ->map(fn($cashup) => $this->normalizeCashupForReports($cashup));

        $cashupsByDay = $this->cashupRowsByDayAndCurrency($cashupRows, $filters['currency_codes']);
        if (!$canViewCashupAmounts) {
            $salesByDayCurrency = $this->cashupFinancialVisibility->redact($salesByDayCurrency);
            $cashupsByDay = $this->cashupFinancialVisibility->redact($cashupsByDay);
        }

        return response()->json([
            'message' => 'Day ends report fetched successfully.',
            'permissions' => $this->cashupFinancialVisibility->permissionFlags($canViewCashupAmounts),
            'data' => [
                'sales_by_day_currency' => $salesByDayCurrency,
                'cashups_by_day' => $cashupsByDay,
            ],
        ]);
    }

    private function filters(Request $request, bool $enforceBranchScope = true): array
    {
        $branchIds = $request->input('branch_ids', []);
        if (is_string($branchIds)) {
            $branchIds = array_filter(array_map('trim', explode(',', $branchIds)));
        }
        if (!is_array($branchIds)) $branchIds = [];

        $reportBranchId = trim((string) $request->input('branch_id', '')) ?: null;
        if ($reportBranchId && count($branchIds) === 0) $branchIds = [$reportBranchId];
        $branchIds = array_values(array_unique(array_filter(array_map(
            fn ($branchId) => trim((string) $branchId),
            $branchIds
        ))));

        $headerBranchId = trim((string) $request->header('X-Branch-ID', '')) ?: null;
        $validatedContextBranchId = trim((string) $request->attributes->get('company_branch_id', '')) ?: null;
        $costingContextBranchId = $headerBranchId
            ?: $reportBranchId
            ?: (count($branchIds) === 1 ? $branchIds[0] : null)
            ?: $validatedContextBranchId;

        $currencyCodes = $request->input('currency_codes', []);
        if (is_string($currencyCodes)) {
            $currencyCodes = array_filter(array_map('trim', explode(',', $currencyCodes)));
        }
        if (!is_array($currencyCodes)) $currencyCodes = [];

        $requestedCurrency = strtoupper(trim((string) $request->input('currency_code', '')));
        if ($requestedCurrency !== '' && count($currencyCodes) === 0) {
            $currencyCodes = [$requestedCurrency];
        }
        $currencyCodes = array_values(array_unique(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $currencyCodes
        ))));

        $filters = [
            'date_from' => $request->date_from ?? now()->toDateString(),
            'date_to' => $request->date_to ?? now()->toDateString(),
            // branch_id/branch_ids control report row scope. The separate
            // costing context keeps an All Branches report in the currently
            // selected workspace mode without narrowing its rows.
            'branch_id' => $reportBranchId,
            'branch_ids' => $branchIds,
            'costing_context_branch_id' => $costingContextBranchId,
            'till_id' => $request->till_id,
            'cashier_id' => $request->cashier_id,
            'shift_session_id' => $request->shift_session_id,
            // Keep the legacy single-currency field for calculations that may
            // only be labelled in one currency. Multi-currency detail remains
            // in each sale's original currency and summaries use base values.
            'currency_code' => count($currencyCodes) === 1 ? $currencyCodes[0] : null,
            'currency_codes' => $currencyCodes,
            'status' => $request->status,
        ];

        if (!$enforceBranchScope) {
            return $filters;
        }

        $company = app('company');

        return $this->scopedAccessibleReportFilters($request, (string) $company->id, $filters);
    }

    private function defaultReportingCurrencyCode(string $companyId, ?string $branchId = null): ?string
    {
        if (!$companyId || !Schema::hasTable('currencies')) {
            return null;
        }

        $query = DB::table('currencies')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_base_currency', true)
            ->whereNull('deleted_at');

        if ($branchId) {
            $query->where(function ($branchQuery) use ($branchId) {
                $branchQuery->where('branch_id', $branchId)->orWhereNull('branch_id');
            })->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId]);
        } else {
            $query->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END');
        }

        $code = $query->value('code');

        return $code ? strtoupper((string) $code) : null;
    }

    /**
     * A sale stores exchange_rate as base currency -> sale currency.
     * Example: 1 USD = 26 sale-currency units, therefore a USD cost is
     * multiplied by 26 to compare it with sale-currency revenue.
     */
    private function costInSaleCurrencyExpression(
        string $amountExpression,
        string $costCurrencyExpression,
        string $saleAlias = 'sales_transactions'
    ): string {
        $rate = 'COALESCE(NULLIF(' . $saleAlias . '.exchange_rate, 0), 1)';

        return '(CASE
            WHEN UPPER(COALESCE(' . $costCurrencyExpression . ', ' . $saleAlias . '.currency_code)) = UPPER(' . $saleAlias . '.currency_code)
                THEN (' . $amountExpression . ')
            WHEN UPPER(COALESCE(' . $costCurrencyExpression . ', ' . $saleAlias . '.base_currency_code)) = UPPER(' . $saleAlias . '.base_currency_code)
                THEN (' . $amountExpression . ') * ' . $rate . '
            ELSE (' . $amountExpression . ')
        END)';
    }

    private function costInBaseCurrencyExpression(
        string $amountExpression,
        string $costCurrencyExpression,
        string $saleAlias = 'sales_transactions'
    ): string {
        $rate = 'COALESCE(NULLIF(' . $saleAlias . '.exchange_rate, 0), 1)';

        return '(CASE
            WHEN UPPER(COALESCE(' . $costCurrencyExpression . ', ' . $saleAlias . '.base_currency_code)) = UPPER(' . $saleAlias . '.base_currency_code)
                THEN (' . $amountExpression . ')
            WHEN UPPER(COALESCE(' . $costCurrencyExpression . ', ' . $saleAlias . '.currency_code)) = UPPER(' . $saleAlias . '.currency_code)
                THEN (' . $amountExpression . ') / ' . $rate . '
            ELSE (' . $amountExpression . ')
        END)';
    }

    private function baseSalesQuery(string $companyId, array $filters)
    {
        return DB::table('sales_transactions')
            ->where('sales_transactions.company_id', $companyId)
            ->where(function ($query) use ($filters) {
                $query->where(function ($bySaleDate) use ($filters) {
                    $bySaleDate->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
                        ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to']);
                });

                $columns = $this->creditAllocationColumns();
                $dateColumn = $this->creditAllocationDateColumn($columns);

                if ($dateColumn && in_array('sales_transaction_id', $columns, true)) {
                    $query->orWhereExists(function ($exists) use ($filters, $dateColumn, $columns) {
                        $exists->selectRaw('1')
                            ->from('credit_customer_payment_allocations as report_date_allocations')
                            ->whereColumn('report_date_allocations.sales_transaction_id', 'sales_transactions.id')
                            ->whereDate('report_date_allocations.' . $dateColumn, '>=', $filters['date_from'])
                            ->whereDate('report_date_allocations.' . $dateColumn, '<=', $filters['date_to']);

                        if (in_array('allocation_status', $columns, true)) {
                            $exists->whereNotIn('report_date_allocations.allocation_status', ['reversed', 'voided', 'cancelled']);
                        }
                    });
                }
            })
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']));
    }

    private function baseSummary(string $companyId, array $filters)
    {
        $completed = $this->baseSalesQuery($companyId, $filters)
            ->where('sales_transactions.status', 'completed');

        $row = $completed
            ->selectRaw('
                COUNT(*) as transaction_count,
                SUM(subtotal_amount) as gross_sales,
                SUM(discount_amount) as discounts,
                SUM(tax_amount) as tax,
                SUM(total_amount) as net_sales,
                SUM(base_subtotal_amount) as base_gross_sales,
                SUM(base_discount_amount) as base_discounts,
                SUM(base_tax_amount) as base_tax,
                SUM(base_total_amount) as base_net_sales
            ')
            ->first();

        $voidCount = $this->baseSalesQuery($companyId, $filters)
            ->where('sales_transactions.status', 'voided')
            ->count();

        $refundCount = $this->baseSalesQuery($companyId, $filters)
            ->where('sales_transactions.sale_type', 'refund')
            ->count();

        $transactionCount = (int)($row->transaction_count ?? 0);
        $moneySummary = $this->creditAwareMoneySummary($companyId, $filters);
        $useBaseValues = count($filters['currency_codes']) !== 1;
        $baseCurrencyCode = $this->baseCurrencyCode($companyId, $filters)
            ?: $this->defaultReportingCurrencyCode($companyId, $this->costingContextBranchId($filters));
        $netSales = (float)($moneySummary[
            $useBaseValues ? 'base_money_received_value' : 'money_received_value'
        ] ?? 0);

        return [
            'transaction_count' => $transactionCount,
            'gross_sales' => round((float)($useBaseValues ? ($row->base_gross_sales ?? 0) : ($row->gross_sales ?? 0)), 4),
            'discounts' => round((float)($useBaseValues ? ($row->base_discounts ?? 0) : ($row->discounts ?? 0)), 4),
            'tax' => round((float)($useBaseValues ? ($row->base_tax ?? 0) : ($row->tax ?? 0)), 4),
            'net_sales' => round($netSales, 4),
            'base_gross_sales' => round((float)($row->base_gross_sales ?? 0), 4),
            'base_discounts' => round((float)($row->base_discounts ?? 0), 4),
            'base_tax' => round((float)($row->base_tax ?? 0), 4),
            'base_net_sales' => round((float)($moneySummary['base_money_received_value'] ?? 0), 4),
            'invoiced_value' => round((float)($moneySummary['invoiced_value'] ?? 0), 4),
            'base_invoiced_value' => round((float)($moneySummary['base_invoiced_value'] ?? 0), 4),
            'cash_sale_value' => round((float)($moneySummary['cash_sale_value'] ?? 0), 4),
            'base_cash_sale_value' => round((float)($moneySummary['base_cash_sale_value'] ?? 0), 4),
            'credit_issued_value' => round((float)($moneySummary['credit_issued_value'] ?? 0), 4),
            'base_credit_issued_value' => round((float)($moneySummary['base_credit_issued_value'] ?? 0), 4),
            'credit_paid_value' => round((float)($moneySummary['credit_paid_value'] ?? 0), 4),
            'base_credit_paid_value' => round((float)($moneySummary['base_credit_paid_value'] ?? 0), 4),
            'credit_outstanding_value' => round((float)($moneySummary['credit_outstanding_value'] ?? 0), 4),
            'base_credit_outstanding_value' => round((float)($moneySummary['base_credit_outstanding_value'] ?? 0), 4),
            'money_received_value' => round((float)($moneySummary['money_received_value'] ?? 0), 4),
            'base_money_received_value' => round((float)($moneySummary['base_money_received_value'] ?? 0), 4),
            'currency_code' => $useBaseValues ? $baseCurrencyCode : $filters['currency_code'],
            'base_currency_code' => $baseCurrencyCode,
            'void_count' => $voidCount,
            'refund_count' => $refundCount,
            'average_basket' => $transactionCount > 0
                ? round($netSales / $transactionCount, 4)
                : 0,
        ];
    }

    private function activityComparison(array $currentFilters, array $previousFilters, array $current, array $previous): array
    {
        $currentTransactions = (int) ($current['transaction_count'] ?? 0);
        $previousTransactions = (int) ($previous['transaction_count'] ?? 0);
        $currentSales = (float) ($current['sales_value'] ?? 0);
        $previousSales = (float) ($previous['sales_value'] ?? 0);
        $currentBasket = $currentTransactions > 0 ? $currentSales / $currentTransactions : 0;
        $previousBasket = $previousTransactions > 0 ? $previousSales / $previousTransactions : 0;

        $metric = function (string $key, string $label, float $currentValue, float $previousValue): array {
            $change = $currentValue - $previousValue;
            $percent = abs($previousValue) > 0.000001 ? ($change / abs($previousValue)) * 100 : ($currentValue > 0 ? 100 : 0);
            return [
                'key' => $key,
                'label' => $label,
                'current' => round($currentValue, 4),
                'previous' => round($previousValue, 4),
                'change' => round($change, 4),
                'change_percent' => round($percent, 2),
                'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            ];
        };

        $metrics = [
            $metric('sales_activity', 'Sales activity', $currentSales, $previousSales),
            $metric('transaction_activity', 'Transaction activity', $currentTransactions, $previousTransactions),
            $metric('average_basket', 'Average basket', $currentBasket, $previousBasket),
        ];

        $salesChange = $metrics[0]['change_percent'];
        $transactionChange = $metrics[1]['change_percent'];
        $basketChange = $metrics[2]['change_percent'];
        $insights = [];
        if ($salesChange < -10) $insights[] = 'Sales are materially below the comparable period; review lost transactions and high-value products first.';
        if ($transactionChange < -10) $insights[] = 'Fewer baskets are driving the decline; check traffic, availability and till uptime.';
        if ($basketChange < -10) $insights[] = 'Average basket value has fallen; consider bundles or relevant add-on prompts.';
        if ($salesChange > 10 && $basketChange > 0) $insights[] = 'Growth is supported by stronger basket value, not only transaction volume.';
        if ($insights === []) $insights[] = 'Activity is broadly stable against the comparable period.';

        return [
            'modal' => ['size' => 'xl', 'title' => 'Sales activity comparison'],
            'currency_code' => $current['currency_code'] ?? null,
            'current_period' => ['date_from' => $currentFilters['date_from'], 'date_to' => $currentFilters['date_to']],
            'previous_period' => ['date_from' => $previousFilters['date_from'], 'date_to' => $previousFilters['date_to']],
            'metrics' => $metrics,
            'insights' => $insights,
        ];
    }

    private function salesByCurrency(string $companyId, array $filters)
    {
        $salesRows = $this->baseSalesQuery($companyId, $filters)
            ->selectRaw('
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                COUNT(*) as transaction_count,
                SUM(sales_transactions.subtotal_amount) as subtotal,
                SUM(sales_transactions.discount_amount) as discount,
                SUM(sales_transactions.tax_amount) as tax,
                SUM(sales_transactions.total_amount) as invoiced_value,
                SUM(sales_transactions.base_subtotal_amount) as base_subtotal,
                SUM(sales_transactions.base_discount_amount) as base_discount,
                SUM(sales_transactions.base_tax_amount) as base_tax,
                SUM(sales_transactions.base_total_amount) as base_invoiced_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.total_amount END) as cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.base_total_amount END) as base_cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.total_amount ELSE 0 END) as credit_issued_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.base_total_amount ELSE 0 END) as base_credit_issued_value
            ')
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->groupBy(
                'sales_transactions.currency_code',
                'sales_transactions.base_currency_code'
            )
            ->get()
            ->keyBy(fn ($row) => strtoupper((string) ($row->currency_code ?? 'USD')));

        foreach ($this->creditAllocationCurrencyTotals($companyId, $filters) as $paid) {
            $currencyCode = strtoupper((string) ($paid->currency_code ?? 'USD'));

            if (!$salesRows->has($currencyCode)) {
                $salesRows->put($currencyCode, (object) [
                    'currency_code' => $currencyCode,
                    'base_currency_code' => $currencyCode,
                    'transaction_count' => 0,
                    'subtotal' => 0,
                    'discount' => 0,
                    'tax' => 0,
                    'invoiced_value' => 0,
                    'base_subtotal' => 0,
                    'base_discount' => 0,
                    'base_tax' => 0,
                    'base_invoiced_value' => 0,
                    'cash_sale_value' => 0,
                    'base_cash_sale_value' => 0,
                    'credit_issued_value' => 0,
                    'base_credit_issued_value' => 0,
                ]);
            }

            $row = $salesRows->get($currencyCode);
            $row->credit_paid_value = round(((float) ($row->credit_paid_value ?? 0)) + (float) ($paid->credit_paid_value ?? 0), 4);
            $row->base_credit_paid_value = round(((float) ($row->base_credit_paid_value ?? 0)) + (float) ($paid->base_credit_paid_value ?? 0), 4);
        }

        return $salesRows
            ->map(function ($row) {
                $row->credit_paid_value = round((float) ($row->credit_paid_value ?? 0), 4);
                $row->base_credit_paid_value = round((float) ($row->base_credit_paid_value ?? 0), 4);
                $row->credit_outstanding_value = round(max((float) ($row->credit_issued_value ?? 0) - $row->credit_paid_value, 0), 4);
                $row->base_credit_outstanding_value = round(max((float) ($row->base_credit_issued_value ?? 0) - $row->base_credit_paid_value, 0), 4);
                $row->money_received_value = round((float) ($row->cash_sale_value ?? 0) + $row->credit_paid_value, 4);
                $row->base_money_received_value = round((float) ($row->base_cash_sale_value ?? 0) + $row->base_credit_paid_value, 4);

                $row->total = $row->money_received_value;
                $row->base_total = $row->base_money_received_value;

                foreach (['subtotal', 'discount', 'tax', 'base_subtotal', 'base_discount', 'base_tax', 'invoiced_value', 'base_invoiced_value', 'cash_sale_value', 'base_cash_sale_value', 'credit_issued_value', 'base_credit_issued_value'] as $field) {
                    $row->{$field} = round((float) ($row->{$field} ?? 0), 4);
                }

                return $row;
            })
            ->sortBy('currency_code')
            ->values();
    }


    private function paymentSummaryByCurrency(string $companyId, array $filters)
    {
        $creditCondition = $this->creditPaymentRowConditionSql();

        $rows = DB::table('sales_transaction_payments')
            ->selectRaw("
                payment_name,
                money_code,
                currency_code,
                base_currency_code,
                COUNT(*) as payment_count,
                SUM(CASE WHEN {$creditCondition} THEN 0 ELSE amount END) as amount,
                SUM(CASE WHEN {$creditCondition} THEN 0 ELSE base_amount END) as base_amount,
                SUM(CASE WHEN {$creditCondition} THEN 0 ELSE amount_tendered END) as amount_tendered,
                SUM(CASE WHEN {$creditCondition} THEN 0 ELSE change_amount END) as change_amount,
                SUM(CASE WHEN {$creditCondition} THEN amount ELSE 0 END) as credit_issued_reference_value
            ")
            ->where('company_id', $companyId)
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('till_id', $filters['till_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('currency_code', $filters['currency_codes']))
            ->groupBy(
                'payment_name',
                'money_code',
                'currency_code',
                'base_currency_code'
            )
            ->orderBy('payment_name')
            ->orderBy('currency_code')
            ->get()
            ->filter(fn ($row) => abs((float) ($row->amount ?? 0)) > 0.0001
                || abs((float) ($row->base_amount ?? 0)) > 0.0001)
            ->values();

        foreach ($this->creditAllocationCurrencyTotals($companyId, $filters) as $paid) {
            if (abs((float) ($paid->credit_paid_value ?? 0)) <= 0.0001) {
                continue;
            }

            $rows->push((object) [
                'payment_name' => 'Credit Customer Payment',
                'money_code' => 'credit_customer_payment_allocation',
                'currency_code' => $paid->currency_code,
                'base_currency_code' => $paid->currency_code,
                'payment_count' => null,
                'amount' => round((float) ($paid->credit_paid_value ?? 0), 4),
                'base_amount' => round((float) ($paid->base_credit_paid_value ?? 0), 4),
                'amount_tendered' => round((float) ($paid->credit_paid_value ?? 0), 4),
                'change_amount' => 0,
                'credit_issued_reference_value' => 0,
            ]);
        }

        return $rows->values();
    }


    private function taxSummary(string $companyId, array $filters)
    {
        return DB::table('receipt_line_taxes')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_line_taxes.sales_transaction_id')
            ->selectRaw('
                receipt_line_taxes.tax_code,
                receipt_line_taxes.tax_percent,
                receipt_line_taxes.currency_code,
                COUNT(*) as line_count,
                SUM(receipt_line_taxes.tax_amount) as tax_amount,
                SUM(receipt_line_taxes.sales_amount_with_tax) as sales_amount_with_tax
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_line_taxes.currency_code', $filters['currency_codes']))
            ->groupBy(
                'receipt_line_taxes.tax_code',
                'receipt_line_taxes.tax_percent',
                'receipt_line_taxes.currency_code'
            )
            ->orderBy('receipt_line_taxes.tax_code')
            ->get();
    }

    private function hourlySales(string $companyId, array $filters)
    {
        $salesRows = $this->baseSalesQuery($companyId, $filters)
            ->selectRaw('
                HOUR(sales_transactions.transaction_date) as hour,
                sales_transactions.currency_code,
                COUNT(*) as transaction_count,
                SUM(sales_transactions.total_amount) as invoiced_value,
                SUM(sales_transactions.base_total_amount) as base_invoiced_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.total_amount END) as cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.base_total_amount END) as base_cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.total_amount ELSE 0 END) as credit_issued_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.base_total_amount ELSE 0 END) as base_credit_issued_value
            ')
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->groupByRaw('HOUR(sales_transactions.transaction_date), sales_transactions.currency_code')
            ->get()
            ->keyBy(fn ($row) => (string) $row->hour . '|' . strtoupper((string) ($row->currency_code ?? 'USD')));

        foreach ($this->creditAllocationDateCurrencyTotals($companyId, $filters, 'hour') as $paid) {
            $hour = (string) $paid->allocation_period;
            $currencyCode = strtoupper((string) ($paid->currency_code ?? 'USD'));
            $key = $hour . '|' . $currencyCode;

            if (!$salesRows->has($key)) {
                $salesRows->put($key, (object) [
                    'hour' => $hour,
                    'currency_code' => $currencyCode,
                    'transaction_count' => 0,
                    'invoiced_value' => 0,
                    'base_invoiced_value' => 0,
                    'cash_sale_value' => 0,
                    'base_cash_sale_value' => 0,
                    'credit_issued_value' => 0,
                    'base_credit_issued_value' => 0,
                ]);
            }

            $row = $salesRows->get($key);
            $row->credit_paid_value = round(((float) ($row->credit_paid_value ?? 0)) + (float) ($paid->credit_paid_value ?? 0), 4);
            $row->base_credit_paid_value = round(((float) ($row->base_credit_paid_value ?? 0)) + (float) ($paid->base_credit_paid_value ?? 0), 4);
        }

        return $salesRows->map(function ($row) {
            $row->credit_paid_value = round((float) ($row->credit_paid_value ?? 0), 4);
            $row->base_credit_paid_value = round((float) ($row->base_credit_paid_value ?? 0), 4);
            $row->money_received_value = round((float) ($row->cash_sale_value ?? 0) + $row->credit_paid_value, 4);
            $row->base_money_received_value = round((float) ($row->base_cash_sale_value ?? 0) + $row->base_credit_paid_value, 4);
            $row->total = $row->money_received_value;
            $row->base_total = $row->base_money_received_value;
            return $row;
        })->sortBy('hour')->values();
    }

    private function dailySales(string $companyId, array $filters)
    {
        $salesRows = $this->baseSalesQuery($companyId, $filters)
            ->selectRaw('
                DATE(sales_transactions.transaction_date) as sale_date,
                sales_transactions.currency_code,
                COUNT(*) as transaction_count,
                SUM(sales_transactions.total_amount) as invoiced_value,
                SUM(sales_transactions.base_total_amount) as base_invoiced_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.total_amount END) as cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN 0 ELSE sales_transactions.base_total_amount END) as base_cash_sale_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.total_amount ELSE 0 END) as credit_issued_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN sales_transactions.base_total_amount ELSE 0 END) as base_credit_issued_value
            ')
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->groupByRaw('DATE(sales_transactions.transaction_date), sales_transactions.currency_code')
            ->get()
            ->keyBy(fn ($row) => (string) $row->sale_date . '|' . strtoupper((string) ($row->currency_code ?? 'USD')));

        foreach ($this->creditAllocationDateCurrencyTotals($companyId, $filters) as $paid) {
            $date = (string) $paid->allocation_period;
            $currencyCode = strtoupper((string) ($paid->currency_code ?? 'USD'));
            $key = $date . '|' . $currencyCode;

            if (!$salesRows->has($key)) {
                $salesRows->put($key, (object) [
                    'sale_date' => $date,
                    'currency_code' => $currencyCode,
                    'transaction_count' => 0,
                    'invoiced_value' => 0,
                    'base_invoiced_value' => 0,
                    'cash_sale_value' => 0,
                    'base_cash_sale_value' => 0,
                    'credit_issued_value' => 0,
                    'base_credit_issued_value' => 0,
                ]);
            }

            $row = $salesRows->get($key);
            $row->credit_paid_value = round(((float) ($row->credit_paid_value ?? 0)) + (float) ($paid->credit_paid_value ?? 0), 4);
            $row->base_credit_paid_value = round(((float) ($row->base_credit_paid_value ?? 0)) + (float) ($paid->base_credit_paid_value ?? 0), 4);
        }

        return $salesRows->map(function ($row) {
            $row->credit_paid_value = round((float) ($row->credit_paid_value ?? 0), 4);
            $row->base_credit_paid_value = round((float) ($row->base_credit_paid_value ?? 0), 4);
            $row->money_received_value = round((float) ($row->cash_sale_value ?? 0) + $row->credit_paid_value, 4);
            $row->base_money_received_value = round((float) ($row->base_cash_sale_value ?? 0) + $row->base_credit_paid_value, 4);
            $row->total = $row->money_received_value;
            $row->base_total = $row->base_money_received_value;
            return $row;
        })->sortBy('sale_date')->values();
    }


    private function emptyCreditLineAllocationSubquery()
    {
        return DB::table('receipt_lines')
            ->selectRaw('receipt_lines.id as receipt_line_id, 0 as credit_paid_value, 0 as base_credit_paid_value')
            ->whereRaw('1 = 0');
    }

    private function emptyCreditSaleAllocationSubquery()
    {
        return DB::table('sales_transactions')
            ->selectRaw('sales_transactions.id as sales_transaction_id, 0 as credit_paid_value, 0 as base_credit_paid_value')
            ->whereRaw('1 = 0');
    }

    private function creditAllocationColumns(): array
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return [];
        }

        return Schema::getColumnListing('credit_customer_payment_allocations');
    }

    private function creditAllocationAmountColumn(array $columns): ?string
    {
        foreach (['allocated_amount', 'amount'] as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return null;
    }

    private function creditAllocationBaseAmountColumn(array $columns, string $amountColumn): string
    {
        return in_array('base_allocated_amount', $columns, true)
            ? 'base_allocated_amount'
            : $amountColumn;
    }

    private function creditAllocationAmountExpression(array $columns): ?string
    {
        $hasAllocatedAmount = in_array('allocated_amount', $columns, true);
        $hasLegacyAmount = in_array('amount', $columns, true);

        if ($hasAllocatedAmount && $hasLegacyAmount) {
            return 'COALESCE(NULLIF(allocated_amount, 0), amount, 0)';
        }

        if ($hasAllocatedAmount) {
            return 'COALESCE(allocated_amount, 0)';
        }

        if ($hasLegacyAmount) {
            return 'COALESCE(amount, 0)';
        }

        return null;
    }

    private function creditAllocationBaseAmountExpression(array $columns): ?string
    {
        $amountExpression = $this->creditAllocationAmountExpression($columns);

        if (!$amountExpression) {
            return null;
        }

        if (in_array('base_allocated_amount', $columns, true)) {
            return "COALESCE(NULLIF(base_allocated_amount, 0), {$amountExpression})";
        }

        return $amountExpression;
    }

    private function creditPaymentRowConditionSql(string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return "(LOWER(COALESCE({$prefix}money_code, '')) IN ('customer_credit', 'credit_customer', 'credit_account', 'account') OR LOWER(COALESCE({$prefix}payment_name, '')) LIKE '%credit%')";
    }

    private function creditAllocationCurrencyTotals(string $companyId, array $filters)
    {
        $columns = $this->creditAllocationColumns();
        $amountColumn = $this->creditAllocationAmountColumn($columns);

        if (!$amountColumn) {
            return collect();
        }

        $amountExpression = $this->creditAllocationAmountExpression($columns);
        $baseAmountExpression = $this->creditAllocationBaseAmountExpression($columns);

        $query = DB::table('credit_customer_payment_allocations')
            ->selectRaw("
                currency_code,
                SUM({$amountExpression}) as credit_paid_value,
                SUM({$baseAmountExpression}) as base_credit_paid_value
            ");

        $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

        return $query->groupBy('currency_code')->get();
    }

    private function creditAllocationDateCurrencyTotals(string $companyId, array $filters, ?string $datePart = null)
    {
        $columns = $this->creditAllocationColumns();
        $amountColumn = $this->creditAllocationAmountColumn($columns);
        $dateColumn = $this->creditAllocationDateColumn($columns);

        if (!$amountColumn || !$dateColumn) {
            return collect();
        }

        $amountExpression = $this->creditAllocationAmountExpression($columns);
        $baseAmountExpression = $this->creditAllocationBaseAmountExpression($columns);
        $dateExpression = $datePart === 'hour' ? "HOUR({$dateColumn})" : "DATE({$dateColumn})";

        $query = DB::table('credit_customer_payment_allocations')
            ->selectRaw("
                {$dateExpression} as allocation_period,
                currency_code,
                SUM({$amountExpression}) as credit_paid_value,
                SUM({$baseAmountExpression}) as base_credit_paid_value
            ");

        $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

        return $query->groupByRaw("{$dateExpression}, currency_code")->get();
    }

    private function creditAllocationDateColumn(array $columns): ?string
    {
        foreach (['payment_date', 'created_at', 'updated_at'] as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return null;
    }

    private function applyCreditAllocationFilters($query, string $companyId, array $filters, array $columns): void
    {
        if (in_array('company_id', $columns, true)) {
            $query->where('company_id', $companyId);
        }

        $dateColumn = $this->creditAllocationDateColumn($columns);
        if ($dateColumn) {
            $query->whereDate($dateColumn, '>=', $filters['date_from'])
                ->whereDate($dateColumn, '<=', $filters['date_to']);
        }

        if (in_array('branch_id', $columns, true)) {
            $this->applyBranchScope($query, $filters, 'branch_id');
        }

        if ($filters['currency_codes'] && in_array('currency_code', $columns, true)) {
            $query->whereIn('currency_code', $filters['currency_codes']);
        }

        if (in_array('allocation_status', $columns, true)) {
            $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
        }
    }

    private function creditAllocationByReceiptLineSubquery(string $companyId, array $filters)
    {
        $columns = $this->creditAllocationColumns();
        $amountColumn = $this->creditAllocationAmountColumn($columns);

        if (!$amountColumn || !in_array('receipt_line_id', $columns, true)) {
            return $this->emptyCreditLineAllocationSubquery();
        }

        $amountExpression = $this->creditAllocationAmountExpression($columns);
        $baseAmountExpression = $this->creditAllocationBaseAmountExpression($columns);

        $query = DB::table('credit_customer_payment_allocations')
            ->selectRaw("
                receipt_line_id,
                SUM({$amountExpression}) as credit_paid_value,
                SUM({$baseAmountExpression}) as base_credit_paid_value
            ")
            ->whereNotNull('receipt_line_id');

        $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

        return $query->groupBy('receipt_line_id');
    }

    private function creditAllocationBySaleSubquery(string $companyId, array $filters)
    {
        $columns = $this->creditAllocationColumns();
        $amountColumn = $this->creditAllocationAmountColumn($columns);

        if (!$amountColumn) {
            return $this->emptyCreditSaleAllocationSubquery();
        }

        $amountExpression = $this->creditAllocationAmountExpression($columns);
        $baseAmountExpression = $this->creditAllocationBaseAmountExpression($columns);

        if (in_array('sales_transaction_id', $columns, true)) {
            $query = DB::table('credit_customer_payment_allocations')
                ->selectRaw("
                    sales_transaction_id,
                    SUM({$amountExpression}) as credit_paid_value,
                    SUM({$baseAmountExpression}) as base_credit_paid_value
                ")
                ->whereNotNull('sales_transaction_id');

            $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

            return $query->groupBy('sales_transaction_id');
        }

        if (in_array('sale_ledger_id', $columns, true) && Schema::hasTable('credit_customer_ledgers')) {
            $query = DB::table('credit_customer_payment_allocations')
                ->join('credit_customer_ledgers as sale_ledgers', 'sale_ledgers.id', '=', 'credit_customer_payment_allocations.sale_ledger_id')
                ->selectRaw("
                    sale_ledgers.sales_transaction_id,
                    SUM({$amountExpression}) as credit_paid_value,
                    SUM({$baseAmountExpression}) as base_credit_paid_value
                ")
                ->whereNotNull('sale_ledgers.sales_transaction_id');

            $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

            return $query->groupBy('sale_ledgers.sales_transaction_id');
        }

        return $this->emptyCreditSaleAllocationSubquery();
    }


    private function creditPaymentTotals(string $companyId, array $filters): array
    {
        $columns = $this->creditAllocationColumns();
        $amountColumn = $this->creditAllocationAmountColumn($columns);

        if (!$amountColumn) {
            return [
                'credit_paid_value' => 0.0,
                'base_credit_paid_value' => 0.0,
            ];
        }

        $amountExpression = $this->creditAllocationAmountExpression($columns);
        $baseAmountExpression = $this->creditAllocationBaseAmountExpression($columns);

        $query = DB::table('credit_customer_payment_allocations');
        $this->applyCreditAllocationFilters($query, $companyId, $filters, $columns);

        $row = $query->selectRaw("
            COALESCE(SUM({$amountExpression}), 0) as credit_paid_value,
            COALESCE(SUM({$baseAmountExpression}), 0) as base_credit_paid_value
        ")->first();

        return [
            'credit_paid_value' => round((float) ($row->credit_paid_value ?? 0), 4),
            'base_credit_paid_value' => round((float) ($row->base_credit_paid_value ?? 0), 4),
        ];
    }

    private function creditAwareMoneySummary(string $companyId, array $filters): array
    {
        $isCreditSaleColumnExists = Schema::hasColumn('sales_transactions', 'is_credit_sale');

        $baseQuery = DB::table('sales_transactions')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']));

        $sumSales = function ($query): array {
            $row = $query->selectRaw('
                COALESCE(SUM(sales_transactions.total_amount), 0) as value,
                COALESCE(SUM(sales_transactions.base_total_amount), 0) as base_value
            ')->first();

            return [
                'value' => round((float) ($row->value ?? 0), 4),
                'base_value' => round((float) ($row->base_value ?? 0), 4),
            ];
        };

        $invoiced = $sumSales(clone $baseQuery);

        $cashQuery = clone $baseQuery;
        if ($isCreditSaleColumnExists) {
            $cashQuery->where(function ($query) {
                $query->whereNull('sales_transactions.is_credit_sale')
                    ->orWhere('sales_transactions.is_credit_sale', 0);
            });
        }
        $cashSales = $sumSales($cashQuery);

        $creditQuery = clone $baseQuery;
        if ($isCreditSaleColumnExists) {
            $creditQuery->where('sales_transactions.is_credit_sale', 1);
        } else {
            $creditQuery->whereRaw('1 = 0');
        }
        $creditIssued = $sumSales($creditQuery);

        $creditPaid = $this->creditPaymentTotals($companyId, $filters);

        $moneyReceived = $cashSales['value'] + $creditPaid['credit_paid_value'];
        $baseMoneyReceived = $cashSales['base_value'] + $creditPaid['base_credit_paid_value'];

        return [
            'invoiced_value' => $invoiced['value'],
            'base_invoiced_value' => $invoiced['base_value'],
            'cash_sale_value' => $cashSales['value'],
            'base_cash_sale_value' => $cashSales['base_value'],
            'credit_issued_value' => $creditIssued['value'],
            'base_credit_issued_value' => $creditIssued['base_value'],
            'credit_paid_value' => $creditPaid['credit_paid_value'],
            'base_credit_paid_value' => $creditPaid['base_credit_paid_value'],
            'credit_outstanding_value' => round(max($creditIssued['value'] - $creditPaid['credit_paid_value'], 0), 4),
            'base_credit_outstanding_value' => round(max($creditIssued['base_value'] - $creditPaid['base_credit_paid_value'], 0), 4),
            'money_received_value' => round($moneyReceived, 4),
            'base_money_received_value' => round($baseMoneyReceived, 4),
        ];
    }

    private function creditAwareProductReportRows(string $companyId, array $filters, int $limit = 100)
    {
        $lineCreditAllocations = $this->creditAllocationByReceiptLineSubquery($companyId, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($companyId, $filters);
        $costing = app(SalesCostingService::class);
        $hospitality = $costing->isHospitality($companyId, $this->costingContextBranchId($filters));
        $unitCost = $costing->unitCostExpression($hospitality);
        $lineCost = $costing->lineCostExpression($hospitality);
        $costCurrency = $costing->costCurrencyExpression($hospitality, 'sales_transactions.base_currency_code');
        $reportingUnitCost = $this->costInSaleCurrencyExpression($unitCost, $costCurrency);
        $reportingLineCost = $this->costInSaleCurrencyExpression($lineCost, $costCurrency);
        $baseLineCost = $this->costInBaseCurrencyExpression($lineCost, $costCurrency);

        $lineBase = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            })
            ->leftJoinSub($lineCreditAllocations, 'line_credit_allocations', function ($join) {
                $join->on('line_credit_allocations.receipt_line_id', '=', 'receipt_lines.id');
            })
            ->leftJoinSub($saleCreditAllocations, 'sale_credit_allocations', function ($join) {
                $join->on('sale_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id');
            });

        if ($hospitality) {
            $lineBase
                ->leftJoinSub($costing->recipeMovementCostsByReceiptLine($companyId), 'recipe_line_costs', function ($join) {
                    $join->on('recipe_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                        ->on('recipe_line_costs.receipt_line_id', '=', 'receipt_lines.id');
                })
                ->leftJoinSub($costing->activeRecipeUnitCosts($companyId, $filters['branch_id']), 'active_recipe_costs', function ($join) {
                    $join->on('active_recipe_costs.menu_product_id', '=', 'receipt_lines.product_id')
                        ->on('active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id');
                });
        } else {
            $lineBase->leftJoinSub($costing->retailMovementCostsByReceiptLine($companyId), 'retail_line_costs', function ($join) {
                $join->on('retail_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                    ->on('retail_line_costs.receipt_line_id', '=', 'receipt_lines.id');
            });
        }

        $lineBase->selectRaw("
                receipt_lines.id as receipt_line_id,
                receipt_lines.sales_transaction_id,
                receipt_lines.product_id,
                COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
                COALESCE(products.code, receipt_lines.product_code) as product_code,
                COALESCE(products.sku, receipt_lines.sku) as sku,
                COALESCE(product_categories.name, 'Uncategorised') as category_name,
                receipt_lines.currency_code,
                receipt_lines.base_currency_code,
                receipt_lines.line_quantity,
                receipt_lines.line_subtotal,
                receipt_lines.line_discount,
                receipt_lines.line_tax,
                receipt_lines.line_total,
                receipt_lines.base_line_total,
                {$reportingUnitCost} as average_cost,
                receipt_lines.currency_code as cost_currency_code,
                {$reportingLineCost} as line_cost,
                {$baseLineCost} as base_line_cost,
                {$unitCost} as source_average_cost,
                {$lineCost} as source_line_cost,
                {$costCurrency} as source_cost_currency_code,
                sales_transactions.is_credit_sale,
                sales_transactions.status,
                sales_transactions.total_amount as sale_total_amount,
                sales_transactions.base_total_amount as sale_base_total_amount,
                COALESCE(
                    line_credit_allocations.credit_paid_value,
                    CASE
                        WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 AND COALESCE(sales_transactions.total_amount, 0) > 0
                            THEN LEAST(receipt_lines.line_total, COALESCE(sale_credit_allocations.credit_paid_value, 0) * (receipt_lines.line_total / NULLIF(sales_transactions.total_amount, 0)))
                        ELSE 0
                    END,
                    0
                ) as credit_paid_value,
                COALESCE(
                    line_credit_allocations.base_credit_paid_value,
                    CASE
                        WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 AND COALESCE(sales_transactions.base_total_amount, 0) > 0
                            THEN LEAST(receipt_lines.base_line_total, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) * (receipt_lines.base_line_total / NULLIF(sales_transactions.base_total_amount, 0)))
                        ELSE 0
                    END,
                    0
                ) as base_credit_paid_value
            ")
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->where(function ($query) use ($filters) {
                $query->where(function ($bySaleDate) use ($filters) {
                    $bySaleDate->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
                        ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to']);
                });

                $columns = $this->creditAllocationColumns();
                $dateColumn = $this->creditAllocationDateColumn($columns);

                if ($dateColumn && in_array('sales_transaction_id', $columns, true)) {
                    $query->orWhereExists(function ($exists) use ($filters, $dateColumn, $columns) {
                        $exists->selectRaw('1')
                            ->from('credit_customer_payment_allocations as report_date_allocations')
                            ->whereColumn('report_date_allocations.sales_transaction_id', 'sales_transactions.id')
                            ->whereDate('report_date_allocations.' . $dateColumn, '>=', $filters['date_from'])
                            ->whereDate('report_date_allocations.' . $dateColumn, '<=', $filters['date_to']);

                        if (in_array('allocation_status', $columns, true)) {
                            $exists->whereNotIn('report_date_allocations.allocation_status', ['reversed', 'voided', 'cancelled']);
                        }
                    });
                }
            })
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']));

        return DB::query()
            ->fromSub($lineBase, 'lines')
            ->selectRaw('
                lines.product_id,
                lines.product_name,
                MAX(lines.product_code) as product_code,
                MAX(lines.sku) as sku,
                MAX(lines.category_name) as category_name,
                lines.currency_code,
                lines.base_currency_code,
                MAX(lines.cost_currency_code) as cost_currency_code,
                MAX(lines.average_cost) as average_cost,
                COUNT(DISTINCT lines.sales_transaction_id) as receipt_count,
                COUNT(lines.receipt_line_id) as line_count,
                SUM(lines.line_quantity) as quantity_sold,
                SUM(lines.line_subtotal) as subtotal,
                SUM(lines.line_discount) as discount,
                SUM(lines.line_tax) as tax,
                SUM(lines.line_total) as invoiced_value,
                SUM(lines.base_line_total) as base_invoiced_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.line_total END) as cash_sale_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.base_line_total END) as base_cash_sale_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.line_total ELSE 0 END) as credit_issued_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.base_line_total ELSE 0 END) as base_credit_issued_value,
                SUM(lines.credit_paid_value) as credit_paid_value,
                SUM(lines.base_credit_paid_value) as base_credit_paid_value,
                GREATEST(SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.line_total ELSE 0 END) - SUM(lines.credit_paid_value), 0) as credit_outstanding_value,
                GREATEST(SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.base_line_total ELSE 0 END) - SUM(lines.base_credit_paid_value), 0) as base_credit_outstanding_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.line_total END) + SUM(lines.credit_paid_value) as money_received_value,
                SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.base_line_total END) + SUM(lines.base_credit_paid_value) as base_money_received_value,
                SUM(lines.line_cost) as invoiced_cost_value,
                SUM(lines.base_line_cost) as base_invoiced_cost_value,
                SUM(CASE
                    WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN
                        lines.line_cost * LEAST(1, lines.credit_paid_value / NULLIF(lines.line_total, 0))
                    ELSE lines.line_cost
                END) as cost_value,
                SUM(CASE
                    WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN
                        lines.base_line_cost * LEAST(1, lines.base_credit_paid_value / NULLIF(lines.base_line_total, 0))
                    ELSE lines.base_line_cost
                END) as base_cost_value,
                SUM(lines.line_total) - SUM(lines.line_cost) as invoiced_gross_profit,
                (SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.line_total END) + SUM(lines.credit_paid_value))
                    - SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.line_cost * LEAST(1, lines.credit_paid_value / NULLIF(lines.line_total, 0)) ELSE lines.line_cost END) as money_received_gross_profit,
                (SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN 0 ELSE lines.base_line_total END) + SUM(lines.base_credit_paid_value))
                    - SUM(CASE WHEN COALESCE(lines.is_credit_sale, 0) = 1 THEN lines.base_line_cost * LEAST(1, lines.base_credit_paid_value / NULLIF(lines.base_line_total, 0)) ELSE lines.base_line_cost END) as base_money_received_gross_profit
            ')
            ->groupBy('lines.product_id', 'lines.product_name', 'lines.currency_code', 'lines.base_currency_code')
            ->orderByDesc('base_money_received_value')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $invoiced = (float)($row->invoiced_value ?? 0);
                $moneyReceived = (float)($row->money_received_value ?? 0);

                foreach ([
                             'quantity_sold', 'average_cost', 'subtotal', 'discount', 'tax', 'invoiced_value', 'base_invoiced_value',
                             'cash_sale_value', 'base_cash_sale_value', 'credit_issued_value', 'base_credit_issued_value',
                             'credit_paid_value', 'base_credit_paid_value', 'credit_outstanding_value', 'base_credit_outstanding_value',
                             'money_received_value', 'base_money_received_value', 'invoiced_cost_value', 'base_invoiced_cost_value',
                             'cost_value', 'base_cost_value', 'invoiced_gross_profit', 'money_received_gross_profit', 'base_money_received_gross_profit'
                         ] as $field) {
                    $row->{$field} = round((float)($row->{$field} ?? 0), 4);
                }

                // Backward-compatible names for old frontend now mean money actually received.
                // Unpaid credit sales remain in invoiced_value / credit_issued_value, not in sales_value.
                $row->sales_value = $row->money_received_value;
                $row->total = $row->money_received_value;
                $row->base_sales_value = $row->base_money_received_value;
                $row->base_total = $row->base_money_received_value;
                $row->gross_profit = $row->money_received_gross_profit;
                $row->estimated_profit = $row->money_received_gross_profit;
                $row->gross_margin_percent = $moneyReceived > 0 ? round(($row->money_received_gross_profit / $moneyReceived) * 100, 2) : 0;
                $row->invoiced_gross_margin_percent = $invoiced > 0 ? round(($row->invoiced_gross_profit / $invoiced) * 100, 2) : 0;
                $row->collection_percent = $row->credit_issued_value > 0 ? round(($row->credit_paid_value / $row->credit_issued_value) * 100, 2) : 100;
                $row->money_received_margin_percent = $moneyReceived > 0 ? round((($moneyReceived - $row->cost_value) / $moneyReceived) * 100, 2) : 0;

                return $row;
            })
            ->values();
    }

    private function topProducts(string $companyId, array $filters, int $limit = 20)
    {
        return $this->creditAwareProductReportRows($companyId, $filters, $limit);
    }

    private function cashierPerformance(string $companyId, array $filters)
    {
        return DB::table('sales_transactions')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->selectRaw('
                sales_transactions.cashier_id,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                COUNT(*) as transaction_count,
                SUM(sales_transactions.subtotal_amount) as subtotal,
                SUM(sales_transactions.discount_amount) as discount,
                SUM(sales_transactions.tax_amount) as tax,
                SUM(sales_transactions.total_amount) as total,
                SUM(sales_transactions.base_total_amount) as base_total
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->groupBy(
                'sales_transactions.cashier_id',
                'pos_users.name',
                'pos_users.username',
                'sales_transactions.currency_code',
                'sales_transactions.base_currency_code'
            )
            ->orderByDesc('base_total')
            ->get();
    }

    private function salePaymentsByCurrency(string $saleId)
    {
        return DB::table('sales_transaction_payments')
            ->selectRaw('
                payment_name,
                money_code,
                currency_code,
                base_currency_code,
                SUM(amount) as amount,
                SUM(base_amount) as base_amount,
                SUM(amount_tendered) as amount_tendered,
                SUM(change_amount) as change_amount
            ')
            ->where('sales_transaction_id', $saleId)
            ->groupBy(
                'payment_name',
                'money_code',
                'currency_code',
                'base_currency_code'
            )
            ->get();
    }

    private function saleLinesByCurrency(string $saleId)
    {
        return DB::table('receipt_lines')
            ->selectRaw('
                currency_code,
                base_currency_code,
                COUNT(*) as line_count,
                SUM(line_quantity) as quantity,
                SUM(line_subtotal) as subtotal,
                SUM(line_discount) as discount,
                SUM(line_tax) as tax,
                SUM(line_total) as total,
                SUM(base_line_total) as base_total
            ')
            ->where('sales_transaction_id', $saleId)
            ->groupBy('currency_code', 'base_currency_code')
            ->get();
    }

    private function saleTaxesByCode(string $saleId)
    {
        return DB::table('receipt_line_taxes')
            ->selectRaw('
                tax_code,
                tax_percent,
                currency_code,
                SUM(tax_amount) as tax_amount,
                SUM(sales_amount_with_tax) as sales_amount_with_tax
            ')
            ->where('sales_transaction_id', $saleId)
            ->groupBy('tax_code', 'tax_percent', 'currency_code')
            ->get();
    }


    private function baseCurrencyCode(string $companyId, array $filters): ?string
    {
        $row = DB::table('sales_transactions')
            ->selectRaw('MAX(base_currency_code) as base_currency_code')
            ->where('company_id', $companyId)
            ->whereDate('transaction_date', '>=', $filters['date_from'])
            ->whereDate('transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('shift_session_id', $filters['shift_session_id']))
            ->when($filters['status'], fn($q) => $q->where('status', $filters['status']))
            ->first();

        return $row->base_currency_code ?? null;
    }

    private function saleLineSummarySubquery(string $companyId, array $filters)
    {
        return DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->selectRaw('
                receipt_lines.sales_transaction_id,
                COUNT(*) as receipt_lines_count,
                SUM(receipt_lines.line_quantity) as product_sold_quantity,
                SUM(receipt_lines.line_subtotal) as sales_subtotal,
                SUM(receipt_lines.line_discount) as sales_discount,
                SUM(receipt_lines.line_tax) as sales_tax,
                SUM(receipt_lines.line_total) as sales_value,
                SUM(receipt_lines.base_line_total) as base_sales_value
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->where(function ($query) use ($filters) {
                $query->where(function ($bySaleDate) use ($filters) {
                    $bySaleDate->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
                        ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to']);
                });

                $columns = $this->creditAllocationColumns();
                $dateColumn = $this->creditAllocationDateColumn($columns);

                if ($dateColumn && in_array('sales_transaction_id', $columns, true)) {
                    $query->orWhereExists(function ($exists) use ($filters, $dateColumn, $columns) {
                        $exists->selectRaw('1')
                            ->from('credit_customer_payment_allocations as report_date_allocations')
                            ->whereColumn('report_date_allocations.sales_transaction_id', 'sales_transactions.id')
                            ->whereDate('report_date_allocations.' . $dateColumn, '>=', $filters['date_from'])
                            ->whereDate('report_date_allocations.' . $dateColumn, '<=', $filters['date_to']);

                        if (in_array('allocation_status', $columns, true)) {
                            $exists->whereNotIn('report_date_allocations.allocation_status', ['reversed', 'voided', 'cancelled']);
                        }
                    });
                }
            })
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->when($filters['status'], fn($q) => $q->where('sales_transactions.status', $filters['status']))
            ->groupBy('receipt_lines.sales_transaction_id');
    }

    private function saleCostSummarySubquery(string $companyId, array $filters)
    {
        $costing = app(SalesCostingService::class);
        $hospitality = $costing->isHospitality($companyId, $this->costingContextBranchId($filters));
        $lineCost = $costing->lineCostExpression($hospitality);
        $costCurrency = $costing->costCurrencyExpression($hospitality);
        $reportingLineCost = $this->costInSaleCurrencyExpression($lineCost, $costCurrency);
        $baseLineCost = $this->costInBaseCurrencyExpression($lineCost, $costCurrency);

        $query = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            });

        if ($hospitality) {
            $query
                ->leftJoinSub($costing->recipeMovementCostsByReceiptLine($companyId), 'recipe_line_costs', function ($join) {
                    $join->on('recipe_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                        ->on('recipe_line_costs.receipt_line_id', '=', 'receipt_lines.id');
                })
                ->leftJoinSub($costing->activeRecipeUnitCosts($companyId, $filters['branch_id']), 'active_recipe_costs', function ($join) {
                    $join->on('active_recipe_costs.menu_product_id', '=', 'receipt_lines.product_id')
                        ->on('active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id');
                });
        } else {
            $query->leftJoinSub($costing->retailMovementCostsByReceiptLine($companyId), 'retail_line_costs', function ($join) {
                $join->on('retail_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                    ->on('retail_line_costs.receipt_line_id', '=', 'receipt_lines.id');
            });
        }

        return $query->selectRaw("
                receipt_lines.sales_transaction_id,
                SUM({$reportingLineCost}) as cost_value,
                SUM({$reportingLineCost}) as reporting_currency_cost_value,
                SUM({$baseLineCost}) as base_cost_value,
                MAX(sales_transactions.currency_code) as cost_currency_code,
                SUM({$lineCost}) as source_cost_value,
                MAX({$costCurrency}) as source_cost_currency_code
            ")
            ->where('sales_transactions.company_id', $companyId)
            ->where(function ($query) use ($filters) {
                $query->where(function ($bySaleDate) use ($filters) {
                    $bySaleDate->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
                        ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to']);
                });

                $columns = $this->creditAllocationColumns();
                $dateColumn = $this->creditAllocationDateColumn($columns);

                if ($dateColumn && in_array('sales_transaction_id', $columns, true)) {
                    $query->orWhereExists(function ($exists) use ($filters, $dateColumn, $columns) {
                        $exists->selectRaw('1')
                            ->from('credit_customer_payment_allocations as report_date_allocations')
                            ->whereColumn('report_date_allocations.sales_transaction_id', 'sales_transactions.id')
                            ->whereDate('report_date_allocations.' . $dateColumn, '>=', $filters['date_from'])
                            ->whereDate('report_date_allocations.' . $dateColumn, '<=', $filters['date_to']);

                        if (in_array('allocation_status', $columns, true)) {
                            $exists->whereNotIn('report_date_allocations.allocation_status', ['reversed', 'voided', 'cancelled']);
                        }
                    });
                }
            })
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->when($filters['status'], fn($q) => $q->where('sales_transactions.status', $filters['status']))
            ->groupBy('receipt_lines.sales_transaction_id');
    }

    private function saleProductCostSubquery(string $companyId, array $filters)
    {
        $costing = app(SalesCostingService::class);
        $hospitality = $costing->isHospitality($companyId, $this->costingContextBranchId($filters));
        $lineCost = $costing->lineCostExpression($hospitality);
        $costCurrency = $costing->costCurrencyExpression($hospitality);
        $reportingLineCost = $this->costInSaleCurrencyExpression($lineCost, $costCurrency);
        $baseLineCost = $this->costInBaseCurrencyExpression($lineCost, $costCurrency);

        $query = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            });

        if ($hospitality) {
            $query
                ->leftJoinSub($costing->recipeMovementCostsByReceiptLine($companyId), 'recipe_line_costs', function ($join) {
                    $join->on('recipe_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                        ->on('recipe_line_costs.receipt_line_id', '=', 'receipt_lines.id');
                })
                ->leftJoinSub($costing->activeRecipeUnitCosts($companyId, $filters['branch_id']), 'active_recipe_costs', function ($join) {
                    $join->on('active_recipe_costs.menu_product_id', '=', 'receipt_lines.product_id')
                        ->on('active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id');
                });
        } else {
            $query->leftJoinSub($costing->retailMovementCostsByReceiptLine($companyId), 'retail_line_costs', function ($join) {
                $join->on('retail_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                    ->on('retail_line_costs.receipt_line_id', '=', 'receipt_lines.id');
            });
        }

        return $query
            ->selectRaw("receipt_lines.sales_transaction_id, receipt_lines.product_id,
                SUM({$reportingLineCost}) as cost_value,
                SUM({$baseLineCost}) as base_cost_value,
                SUM({$lineCost}) as source_cost_value")
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->groupBy('receipt_lines.sales_transaction_id', 'receipt_lines.product_id');
    }


    private function salesListSummary(string $companyId, array $filters): array
    {
        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($companyId, $filters);

        $row = DB::table('sales_transactions')
            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($saleCreditAllocations, 'summary_credit_allocations', function ($join) {
                $join->on('summary_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->selectRaw('
                COUNT(*) as transaction_count,
                SUM(COALESCE(line_summary.product_sold_quantity, 0)) as product_sold_quantity,

                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0)) as sales_value,
                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0)) as base_sales_value,

                SUM(COALESCE(cost_summary.cost_value, 0)) as invoiced_cost_value,
                SUM(COALESCE(cost_summary.reporting_currency_cost_value, 0)) as invoiced_reporting_currency_cost_value,
                SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_invoiced_cost_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1
                    THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(summary_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.cost_value, 0) END) as cost_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1
                    THEN COALESCE(cost_summary.base_cost_value, 0) * LEAST(1, COALESCE(summary_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.base_cost_value, 0) END) as base_cost_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1
                    THEN COALESCE(cost_summary.reporting_currency_cost_value, 0) * LEAST(1, COALESCE(summary_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.reporting_currency_cost_value, 0) END) as reporting_currency_cost_value,

                SUM(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0))
                    - SUM(COALESCE(cost_summary.cost_value, 0)) as estimated_profit,

                SUM(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0))
                    - SUM(COALESCE(cost_summary.base_cost_value, 0)) as base_estimated_profit,

                MAX(sales_transactions.currency_code) as currency_code,
                MAX(sales_transactions.base_currency_code) as base_currency_code,
                MAX(COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code)) as cost_currency_code
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->when($filters['status'], fn($q) => $q->where('sales_transactions.status', $filters['status']))
            ->first();

        $baseCurrencyCode = $this->baseCurrencyCode($companyId, $filters)
            ?: $this->defaultReportingCurrencyCode($companyId, $this->costingContextBranchId($filters));
        $moneySummary = $this->creditAwareMoneySummary($companyId, $filters);
        $baseCostValue = round((float)($row->base_cost_value ?? 0), 4);
        $selectedCurrency = $filters['currency_code'] ? strtoupper((string) $filters['currency_code']) : null;
        $displaySalesValue = $selectedCurrency
            ? round((float) ($moneySummary['money_received_value'] ?? 0), 4)
            : round((float) ($moneySummary['base_money_received_value'] ?? 0), 4);
        $displayCostValue = $selectedCurrency
            ? round((float) ($row->reporting_currency_cost_value ?? 0), 4)
            : $baseCostValue;

        return [
            'transaction_count' => (int)($row->transaction_count ?? 0),
            'product_sold_quantity' => round((float)($row->product_sold_quantity ?? 0), 4),

            // Summary cards stay in the selected transaction currency.
            'sales_value' => $displaySalesValue,
            'invoiced_value' => round((float)($moneySummary['invoiced_value'] ?? 0), 4),
            'cash_sale_value' => round((float)($moneySummary['cash_sale_value'] ?? 0), 4),
            'credit_issued_value' => round((float)($moneySummary['credit_issued_value'] ?? 0), 4),
            'credit_paid_value' => round((float)($moneySummary['credit_paid_value'] ?? 0), 4),
            'credit_outstanding_value' => round((float)($moneySummary['credit_outstanding_value'] ?? 0), 4),
            'money_received_value' => round((float)($moneySummary['money_received_value'] ?? 0), 4),
            'cost_value' => $displayCostValue,
            'estimated_profit' => round(
                $displaySalesValue - $displayCostValue,
                4
            ),

            'currency_code' => $selectedCurrency ?: $baseCurrencyCode,
            'cost_currency_code' => $selectedCurrency ?: $baseCurrencyCode,
            'base_currency_code' => $baseCurrencyCode,

            // Transaction-currency reference values.
            'transaction_sales_value' => round((float)($row->sales_value ?? 0), 4),
            'transaction_cost_value' => round((float)($row->invoiced_cost_value ?? 0), 4),
            'transaction_estimated_profit' => round(
                (float)(($row->sales_value ?? 0) - ($row->invoiced_cost_value ?? 0)),
                4
            ),
            'transaction_currency_code' => $row->currency_code ?? null,

            'base_sales_value' => round((float)($moneySummary['base_invoiced_value'] ?? 0), 4),
            'base_invoiced_value' => round((float)($moneySummary['base_invoiced_value'] ?? 0), 4),
            'base_cash_sale_value' => round((float)($moneySummary['base_cash_sale_value'] ?? 0), 4),
            'base_credit_issued_value' => round((float)($moneySummary['base_credit_issued_value'] ?? 0), 4),
            'base_credit_paid_value' => round((float)($moneySummary['base_credit_paid_value'] ?? 0), 4),
            'base_credit_outstanding_value' => round((float)($moneySummary['base_credit_outstanding_value'] ?? 0), 4),
            'base_money_received_value' => round((float)($moneySummary['base_money_received_value'] ?? 0), 4),
            'base_cost_value' => $baseCostValue,
            'base_estimated_profit' => round(
                (float)(($moneySummary['base_money_received_value'] ?? 0) - $baseCostValue),
                4
            ),
        ];
    }

    private function dailyProfitSummary(string $companyId, array $filters): array
    {
        $summary = $this->salesListSummary($companyId, $filters);
        $base = $this->baseSummary($companyId, $filters);

        return array_merge($base, [
            'product_sold_quantity' => $summary['product_sold_quantity'],

            // These values are in the independently selected report currency.
            'sales_value' => $summary['sales_value'],
            'cost_value' => $summary['cost_value'],
            'estimated_profit' => $summary['estimated_profit'],

            'base_sales_value' => $summary['base_sales_value'],
            'base_cost_value' => $summary['base_cost_value'],
            'base_estimated_profit' => $summary['base_estimated_profit'],
            'currency_code' => $summary['currency_code'] ?? $summary['base_currency_code'],
            'cost_currency_code' => $summary['cost_currency_code'] ?? $summary['currency_code'] ?? $summary['base_currency_code'],
            'base_currency_code' => $summary['base_currency_code'] ?? $summary['currency_code'],
        ]);
    }

    private function dailySalesList(string $companyId, array $filters, int $perPage = 50)
    {
        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($companyId, $filters);

        return DB::table('sales_transactions')
            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($saleCreditAllocations, 'sale_credit_allocations', function ($join) {
                $join->on('sale_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
            ->selectRaw('
                sales_transactions.id,
                sales_transactions.invoice_number,
                sales_transactions.transaction_date,
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                sales_transactions.cashier_id,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                sales_transactions.till_id,
                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
                tills.till_code,
                sales_transactions.payment_method,
                sales_transactions.customer_name,
                sales_transactions.is_credit_sale,
                COALESCE(line_summary.receipt_lines_count, 0) as receipt_lines_count,
                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,

                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as invoiced_value,
                COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) as base_invoiced_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END as sales_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END as base_sales_value,
                COALESCE(sale_credit_allocations.credit_paid_value, 0) as credit_paid_value,
                GREATEST(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) ELSE 0 END - COALESCE(sale_credit_allocations.credit_paid_value, 0), 0) as credit_outstanding_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1
                    THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.cost_value, 0) END as cost_value,
                (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END)
                    - (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0)) ELSE COALESCE(cost_summary.cost_value, 0) END) as estimated_profit,

                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1
                    THEN COALESCE(cost_summary.base_cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.base_cost_value, 0) END as base_cost_value,
                (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END)
                    - (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.base_cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0)) ELSE COALESCE(cost_summary.base_cost_value, 0) END) as base_estimated_profit,

                COALESCE(cost_summary.cost_currency_code, sales_transactions.currency_code) as cost_currency_code,
                sales_transactions.status
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->where('sales_transactions.status', 'completed')
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->orderByDesc('sales_transactions.transaction_date')
            ->paginate($perPage);
    }


    private function dailyCashierSummary(string $companyId, array $filters)
    {
        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($companyId, $filters);

        return DB::table('sales_transactions')
            ->leftJoinSub($lineSummary, 'line_summary', function ($join) {
                $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($costSummary, 'cost_summary', function ($join) {
                $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoinSub($saleCreditAllocations, 'sale_credit_allocations', function ($join) {
                $join->on('sale_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id');
            })
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->selectRaw('
                sales_transactions.cashier_id,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                sales_transactions.currency_code,
                sales_transactions.base_currency_code,
                COUNT(*) as transaction_count,
                SUM(COALESCE(line_summary.product_sold_quantity, 0)) as product_sold_quantity,
                SUM(sales_transactions.subtotal_amount) as subtotal,
                SUM(sales_transactions.discount_amount) as discount,
                SUM(sales_transactions.tax_amount) as tax,

                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END) as sales_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0)) ELSE COALESCE(cost_summary.cost_value, 0) END) as cost_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END)
                    - SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0)) ELSE COALESCE(cost_summary.cost_value, 0) END) as estimated_profit,

                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END) as base_sales_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.base_cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0)) ELSE COALESCE(cost_summary.base_cost_value, 0) END) as base_cost_value,
                SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.base_credit_paid_value, 0) ELSE COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0) END)
                    - SUM(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.base_cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.base_credit_paid_value, 0) / NULLIF(COALESCE(line_summary.base_sales_value, sales_transactions.base_total_amount, 0), 0)) ELSE COALESCE(cost_summary.base_cost_value, 0) END) as base_estimated_profit
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->groupBy(
                'sales_transactions.cashier_id',
                'pos_users.name',
                'pos_users.username',
                'sales_transactions.currency_code',
                'sales_transactions.base_currency_code'
            )
            ->orderByDesc('sales_value')
            ->get();
    }


    private function dailyPaymentTypeSummary(string $companyId, array $filters)
    {
        return $this->paymentSummaryByCurrency($companyId, $filters);
    }

    private function dailyCategorySummary(string $companyId, array $filters)
    {
        $lineByProductCategory = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->selectRaw('
                receipt_lines.sales_transaction_id,
                receipt_lines.product_id,
                products.category_id,
                COALESCE(product_categories.name, "Uncategorised") as category_name,
                receipt_lines.currency_code,
                receipt_lines.base_currency_code,
                SUM(receipt_lines.line_quantity) as quantity_sold,
                SUM(receipt_lines.line_subtotal) as subtotal,
                SUM(receipt_lines.line_discount) as discount,
                SUM(receipt_lines.line_tax) as tax,
                SUM(receipt_lines.line_total) as sales_value,
                SUM(receipt_lines.base_line_total) as base_sales_value
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->groupBy(
                'receipt_lines.sales_transaction_id',
                'receipt_lines.product_id',
                'products.category_id',
                'product_categories.name',
                'receipt_lines.currency_code',
                'receipt_lines.base_currency_code'
            );

        $costBySaleProduct = $this->saleProductCostSubquery($companyId, $filters);

        return DB::query()
            ->fromSub($lineByProductCategory, 'category_lines')
            ->leftJoinSub($costBySaleProduct, 'category_costs', function ($join) {
                $join->on('category_costs.sales_transaction_id', '=', 'category_lines.sales_transaction_id')
                    ->on('category_costs.product_id', '=', 'category_lines.product_id');
            })
            ->selectRaw('
                category_lines.category_id,
                category_lines.category_name,
                category_lines.currency_code,
                category_lines.base_currency_code,
                COUNT(DISTINCT category_lines.sales_transaction_id) as receipt_count,
                SUM(category_lines.quantity_sold) as quantity_sold,
                SUM(category_lines.subtotal) as subtotal,
                SUM(category_lines.discount) as discount,
                SUM(category_lines.tax) as tax,
                SUM(category_lines.sales_value) as sales_value,
                SUM(category_lines.base_sales_value) as base_sales_value,
                SUM(COALESCE(category_costs.cost_value, 0)) as cost_value,
                SUM(COALESCE(category_costs.base_cost_value, 0)) as base_cost_value,
                SUM(category_lines.sales_value) - SUM(COALESCE(category_costs.cost_value, 0)) as estimated_profit
            ')
            ->groupBy(
                'category_lines.category_id',
                'category_lines.category_name',
                'category_lines.currency_code',
                'category_lines.base_currency_code'
            )
            ->orderByDesc('sales_value')
            ->get();
    }

    private function dailyProductSales(string $companyId, array $filters, int $limit = 100)
    {
        return $this->creditAwareProductReportRows($companyId, $filters, $limit);
    }

    private function cashupForShift(string $companyId, string $shiftSessionId)
    {
        $cashup = DB::table('pos_cashups')
            ->where('company_id', $companyId)
            ->where('shift_session_id', $shiftSessionId)
            ->first();

        return $cashup ? $this->normalizeCashupForReports($cashup) : null;
    }

    private function normalizeCashupForReports($cashup)
    {
        $salesSummary = $this->toArrayValue($cashup->sales_summary ?? null);
        $cashoutSummary = $this->toArrayValue($cashup->cashout_summary ?? null);
        $nestedCash = $salesSummary['cash'] ?? [];
        $isMultiCurrency = strtoupper((string) ($cashup->cashup_currency_code ?? '')) === 'MULTI'
            || (bool) ($salesSummary['is_multi_currency'] ?? false);

        $cashoutRows = $cashoutSummary['cash_out_rows'] ?? [];

        $cashoutTotal = $this->numberValue($cashup->cashout_total ?? 0);

        if ($cashoutTotal <= 0) {
            $cashoutTotal = $this->numberValue($cashoutSummary['cashout_total'] ?? 0);
        }

        if ($cashoutTotal <= 0) {
            $cashoutTotal = $this->numberValue($nestedCash['cashout_total'] ?? 0);
        }

        if ($cashoutTotal <= 0 && count($cashoutRows) > 0) {
            $cashoutTotal = collect($cashoutRows)->sum(fn($row) => $this->numberValue($row['amount'] ?? 0));
        }

        $cashoutCount = (int)(($cashup->cashout_count ?? 0) ?: ($cashoutSummary['cashout_count'] ?? count($cashoutRows)));

        $expectedAfterCashouts = $this->numberValue($cashup->expected_cash_after_cashouts ?? 0);

        if ($expectedAfterCashouts <= 0) {
            $expectedAfterCashouts = $this->numberValue($nestedCash['expected_cash_after_cashouts'] ?? 0);
        }

        if ($expectedAfterCashouts <= 0) {
            $expectedAfterCashouts = $this->numberValue($cashup->expected_cash ?? ($nestedCash['expected_cash'] ?? 0)) - $cashoutTotal;
        }

        $cashup->cashout_total = $isMultiCurrency ? null : round($cashoutTotal, 4);
        $cashup->cashout_count = $cashoutCount;
        $cashup->expected_cash_after_cashouts = $isMultiCurrency ? null : round($expectedAfterCashouts, 4);

        if ($isMultiCurrency) {
            $reconciliationTotals = $this->toArrayValue($salesSummary['reconciliation_totals'] ?? []);
            $cashup->base_expected_total = $reconciliationTotals['base_expected_total'] ?? null;
            $cashup->base_counted_total = $reconciliationTotals['base_counted_total'] ?? null;
            $cashup->base_total_variance = $reconciliationTotals['base_total_variance'] ?? null;
            $cashup->expected_total = null;
            $cashup->counted_total = null;
            $cashup->total_variance = null;
            $cashup->expected_cash = null;
            $cashup->counted_cash = null;
            $cashup->cash_variance = null;
        }

        return $cashup;
    }

    private function cashupRowsByDayAndCurrency($cashups, array $requestedCurrencyCodes = [])
    {
        $requestedCurrencyCodes = collect($requestedCurrencyCodes)
            ->map(fn($currencyCode) => strtoupper(trim((string)$currencyCode)))
            ->filter()
            ->unique()
            ->values();

        return collect($cashups)
            ->flatMap(function ($cashup) use ($requestedCurrencyCodes) {
                $salesSummary = $this->toArrayValue($cashup->sales_summary ?? []);
                $cashoutSummary = $this->toArrayValue($cashup->cashout_summary ?? []);
                $currencySummary = collect($this->toArrayValue($cashup->currency_summary ?? []));
                $cashByCurrency = collect($this->toArrayValue($salesSummary['cash_by_currency'] ?? []));
                $cashoutRows = collect($this->toArrayValue($cashoutSummary['cash_out_rows'] ?? []));

                $currencyCodes = $currencySummary->pluck('currency_code')
                    ->merge($cashByCurrency->pluck('currency_code'))
                    ->merge($cashoutRows->pluck('currency_code'))
                    ->map(fn($currencyCode) => strtoupper(trim((string)$currencyCode)))
                    ->filter()
                    ->unique()
                    ->values();

                $storedCurrencyCode = strtoupper(trim((string)($cashup->cashup_currency_code ?? '')));
                if ($currencyCodes->isEmpty() && $storedCurrencyCode !== '' && $storedCurrencyCode !== 'MULTI') {
                    $currencyCodes->push($storedCurrencyCode);
                }
                if ($currencyCodes->isEmpty()) {
                    $currencyCodes->push(strtoupper(trim((string)($cashup->base_currency_code ?? 'USD'))) ?: 'USD');
                }
                $sourceCurrencyCount = $currencyCodes->count();
                if ($requestedCurrencyCodes->isNotEmpty()) {
                    $currencyCodes = $currencyCodes->intersect($requestedCurrencyCodes)->values();
                }

                $businessDate = substr((string)($cashup->cashup_closed_at ?? $cashup->closed_at ?? ''), 0, 10);
                $singleCurrency = $sourceCurrencyCount === 1;

                return $currencyCodes->map(function ($currencyCode) use ($cashup, $businessDate, $singleCurrency, $currencySummary, $cashByCurrency, $cashoutRows) {
                    $currencyTotal = $currencySummary->first(fn($row) => strtoupper((string)data_get($row, 'currency_code')) === $currencyCode) ?? [];
                    $currencyCash = $cashByCurrency->first(fn($row) => strtoupper((string)data_get($row, 'currency_code')) === $currencyCode) ?? [];
                    $currencyCashoutRows = $cashoutRows->filter(fn($row) => strtoupper((string)data_get($row, 'currency_code')) === $currencyCode);
                    $fallback = fn($field) => $singleCurrency ? $this->numberValue($cashup->{$field} ?? 0) : 0.0;

                    return [
                        'business_date' => $businessDate,
                        'currency_code' => $currencyCode,
                        'cashup_count' => 1,
                        'expected_cash' => $this->numberValue(data_get($currencyCash, 'expected_cash', $fallback('expected_cash'))),
                        'cashout_total' => $this->numberValue(data_get($currencyCash, 'cashout_total', $currencyCashoutRows->isNotEmpty() ? $currencyCashoutRows->sum(fn($row) => $this->numberValue(data_get($row, 'amount'))) : $fallback('cashout_total'))),
                        'cashout_count' => $currencyCashoutRows->isNotEmpty() ? $currencyCashoutRows->count() : ($singleCurrency ? (int)($cashup->cashout_count ?? 0) : 0),
                        'expected_cash_after_cashouts' => $this->numberValue(data_get($currencyCash, 'expected_cash_after_cashouts', $fallback('expected_cash_after_cashouts'))),
                        'counted_cash' => $this->numberValue(data_get($currencyCash, 'counted_cash', $fallback('counted_cash'))),
                        'cash_variance' => $this->numberValue(data_get($currencyCash, 'cash_variance', $fallback('cash_variance'))),
                        'expected_total' => $this->numberValue(data_get($currencyTotal, 'expected_amount', $fallback('expected_total'))),
                        'counted_total' => $this->numberValue(data_get($currencyTotal, 'counted_amount', $fallback('counted_total'))),
                        'total_variance' => $this->numberValue(data_get($currencyTotal, 'variance', $fallback('total_variance'))),
                    ];
                });
            })
            ->groupBy(fn($row) => $row['business_date'] . '|' . $row['currency_code'])
            ->map(function ($rows) {
                $first = $rows->first();
                return [
                    'business_date' => $first['business_date'],
                    'currency_code' => $first['currency_code'],
                    'cashup_count' => (int)$rows->sum('cashup_count'),
                    'expected_cash' => round((float)$rows->sum('expected_cash'), 4),
                    'cashout_total' => round((float)$rows->sum('cashout_total'), 4),
                    'cashout_count' => (int)$rows->sum('cashout_count'),
                    'expected_cash_after_cashouts' => round((float)$rows->sum('expected_cash_after_cashouts'), 4),
                    'counted_cash' => round((float)$rows->sum('counted_cash'), 4),
                    'cash_variance' => round((float)$rows->sum('cash_variance'), 4),
                    'expected_total' => round((float)$rows->sum('expected_total'), 4),
                    'counted_total' => round((float)$rows->sum('counted_total'), 4),
                    'total_variance' => round((float)$rows->sum('total_variance'), 4),
                ];
            })
            ->sortBy(fn($row) => $row['business_date'] . '|' . $row['currency_code'])
            ->values();
    }

    private function toArrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }

    private function numberValue($value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }


    public function profitAndLoss(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $costing = $this->resolveSalesBusinessMode(
            $company->id,
            $this->costingContextBranchId($filters)
        );

        $productSummary = $this->creditAwareProductReportRows($company->id, $filters, (int)($request->product_limit ?? 10000));
        $productLines = $this->profitAndLossProductLines($company->id, $filters, (int)($request->per_page ?? 100));

        $baseCurrencyCode = $this->baseCurrencyCode($company->id, $filters)
            ?: $this->defaultReportingCurrencyCode($company->id, $this->costingContextBranchId($filters));
        $reportCurrencyCode = $filters['currency_code'] ?: $baseCurrencyCode;
        $cashouts = $this->profitAndLossCashouts($company->id, $filters, $reportCurrencyCode);

        // Cash-basis: unpaid credit invoices stay in credit_outstanding_value and do not inflate sales_value.
        // Every displayed amount is in the independently selected transaction currency.
        $useBaseValues = count($filters['currency_codes']) !== 1;
        $salesValue = round((float)$productSummary->sum(
            $useBaseValues ? 'base_money_received_value' : 'money_received_value'
        ), 4);
        $costValue = round((float)$productSummary->sum(
            $useBaseValues ? 'base_cost_value' : 'cost_value'
        ), 4);
        $grossProfit = round($salesValue - $costValue, 4);
        $baseSalesValue = round((float)$productSummary->sum('base_money_received_value'), 4);
        $baseCostValue = round((float)$productSummary->sum('base_cost_value'), 4);
        $baseGrossProfit = round($baseSalesValue - $baseCostValue, 4);

        $afterCashMovements = $this->profitAndLossAfterCashMovements(
            $grossProfit,
            $salesValue,
            $cashouts['summary']
        );

        return response()->json([
            'message' => 'Profit and loss report fetched successfully.',
            'data' => [
                'costing' => $costing,
                'summary' => [
                    'product_count' => $productSummary->count(),
                    'quantity_sold' => round((float)$productSummary->sum('quantity_sold'), 4),

                    'sales_value' => $salesValue,
                    'cost_value' => $costValue,
                    'gross_profit' => $grossProfit,

                    'base_sales_value' => $baseSalesValue,
                    'base_cost_value' => $baseCostValue,
                    'base_gross_profit' => $baseGrossProfit,

                    'cashout_total' => $afterCashMovements['cashout_total'],
                    'cashin_total' => $afterCashMovements['cashin_total'],
                    'net_cash_movement' => $afterCashMovements['net_cash_movement'],
                    'net_profit_after_cashouts' => $afterCashMovements['net_profit_after_cashouts'],

                    'gross_margin_percent' => $salesValue > 0
                        ? round(($grossProfit / $salesValue) * 100, 2)
                        : 0,

                    'net_margin_after_cashouts_percent' => $afterCashMovements['net_margin_after_cashouts_percent'],

                    'cash_movement_currency_compatible' => $afterCashMovements['currency_compatible'],
                    'cash_movement_is_mixed_currency' => $afterCashMovements['is_mixed_currency'],
                    'cash_movement_has_unconverted_amounts' => $afterCashMovements['has_unconverted_amounts'],
                    'cash_movement_message' => $afterCashMovements['message'],

                    'base_currency_code' => $baseCurrencyCode,
                    'currency_code' => $reportCurrencyCode,
                    'cost_currency_code' => $reportCurrencyCode,
                ],

                'product_summary' => $productSummary,
                'product_lines' => $productLines,
                'cashouts' => $cashouts,
                'monthly_profit_comparison' => $costing['business_mode'] === 'hospitality'
                    ? $this->monthlyProfitComparison($company->id, $filters, $baseCurrencyCode)
                    : null,
            ],
        ]);
    }

    /**
     * Compare the completed month before the selected report month with the
     * selected month through its date_to. With the default report dates this
     * is previous month versus current month-to-date. Every number is still
     * calculated by the backend using the Hospitality recipe-cost source; the
     * browser only visualises it.
     */
    private function monthlyProfitComparison(
        string $companyId,
        array $filters,
        ?string $baseCurrencyCode,
        ?Carbon $asOf = null
    ): array {
        $comparisonDate = $asOf
            ?: Carbon::parse($filters['date_to'] ?? now()->toDateString());
        $periods = $this->monthlyProfitComparisonPeriods($comparisonDate);
        $rows = collect($periods)->map(function (array $period) use ($companyId, $filters, $baseCurrencyCode) {
            $periodFilters = array_merge($filters, [
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
            ]);
            $products = $this->creditAwareProductReportRows($companyId, $periodFilters, 1000000);
            $reportCurrencyCode = $periodFilters['currency_code'] ?: $baseCurrencyCode;
            $useBaseValues = count($periodFilters['currency_codes']) !== 1;
            $salesValue = round((float) $products->sum(
                $useBaseValues ? 'base_money_received_value' : 'money_received_value'
            ), 4);
            $costValue = round((float) $products->sum(
                $useBaseValues ? 'base_cost_value' : 'cost_value'
            ), 4);
            $grossProfit = round($salesValue - $costValue, 4);
            $cashMovements = $this->profitAndLossCashouts(
                $companyId,
                $periodFilters,
                $reportCurrencyCode
            );
            $afterCashMovements = $this->profitAndLossAfterCashMovements(
                $grossProfit,
                $salesValue,
                $cashMovements['summary']
            );

            return $period + [
                'sales_value' => $salesValue,
                'cost_value' => $costValue,
                'gross_profit' => $grossProfit,
                'gross_margin_percent' => $salesValue > 0
                    ? round(($grossProfit / $salesValue) * 100, 2)
                    : 0,
                'cashout_total' => $afterCashMovements['cashout_total'],
                'cashin_total' => $afterCashMovements['cashin_total'],
                'net_profit_after_cashouts' => $afterCashMovements['net_profit_after_cashouts'],
                'net_margin_after_cashouts_percent' => $afterCashMovements['net_margin_after_cashouts_percent'],
                'currency_code' => $reportCurrencyCode,
                'cash_movement_currency_compatible' => $afterCashMovements['currency_compatible'],
            ];
        })->values();

        $previous = $rows->firstWhere('key', 'previous_month') ?? [];
        $current = $rows->firstWhere('key', 'current_month') ?? [];
        $previousGrossProfit = (float) ($previous['gross_profit'] ?? 0);
        $currentGrossProfit = (float) ($current['gross_profit'] ?? 0);
        $grossProfitChange = round($currentGrossProfit - $previousGrossProfit, 4);

        return [
            'periods' => $rows->all(),
            'gross_profit_change' => $grossProfitChange,
            'gross_profit_change_percent' => abs($previousGrossProfit) > 0.0000001
                ? round(($grossProfitChange / abs($previousGrossProfit)) * 100, 2)
                : null,
            'comparison_basis' => 'previous_full_month_vs_selected_month_to_date',
        ];
    }

    /** @return array<int, array{key:string,label:string,date_from:string,date_to:string}> */
    private function monthlyProfitComparisonPeriods(?Carbon $asOf = null): array
    {
        $currentDate = ($asOf ?: now())->copy()->startOfDay();
        $previousMonth = $currentDate->copy()->subMonthNoOverflow();

        return [
            [
                'key' => 'previous_month',
                'label' => $previousMonth->format('F Y'),
                'date_from' => $previousMonth->copy()->startOfMonth()->toDateString(),
                'date_to' => $previousMonth->copy()->endOfMonth()->toDateString(),
            ],
            [
                'key' => 'current_month',
                'label' => $currentDate->format('F Y')
                    . ($currentDate->isSameDay($currentDate->copy()->endOfMonth()) ? '' : ' MTD'),
                'date_from' => $currentDate->copy()->startOfMonth()->toDateString(),
                'date_to' => $currentDate->toDateString(),
            ],
        ];
    }

    private function profitAndLossProductSummary(string $companyId, array $filters)
    {
        $costExpression = '
        COALESCE(
            NULLIF(MAX(product_costs.average_cost), 0),
            NULLIF(MAX(product_costs.last_cost), 0),
            0
        )
    ';

        $productCostCurrency = app(SalesCostingService::class)
            ->productCostCurrencyExpression() ?: 'NULL';
        $costCurrencyExpression = "
        COALESCE(MAX({$productCostCurrency}), receipt_lines.currency_code)
    ";

        return DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            })
            ->selectRaw("
            receipt_lines.product_id,
            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
            COALESCE(products.code, receipt_lines.product_code) as product_code,
            COALESCE(products.sku, receipt_lines.sku) as sku,
            COALESCE(product_categories.name, 'Uncategorised') as category_name,

            receipt_lines.currency_code,
            receipt_lines.base_currency_code,

            COUNT(DISTINCT sales_transactions.id) as receipt_count,
            COUNT(receipt_lines.id) as line_count,

            SUM(receipt_lines.line_quantity) as quantity_sold,

            AVG(receipt_lines.line_price) as average_selling_price,
            MIN(receipt_lines.line_price) as minimum_selling_price,
            MAX(receipt_lines.line_price) as maximum_selling_price,

            {$costExpression} as average_cost,
            {$costCurrencyExpression} as cost_currency_code,

            SUM(receipt_lines.line_subtotal) as subtotal,
            SUM(receipt_lines.line_discount) as discount,
            SUM(receipt_lines.line_tax) as tax,
            SUM(receipt_lines.line_total) as sales_value,
            SUM(receipt_lines.base_line_total) as base_sales_value,

            SUM(receipt_lines.line_quantity) * {$costExpression} as cost_value,

            SUM(receipt_lines.line_quantity) *
            {$costExpression} *
            CASE
                WHEN {$costCurrencyExpression} = sales_transactions.base_currency_code
                    THEN 1
                ELSE COALESCE(MAX(sales_transactions.exchange_rate), 1)
            END as base_cost_value,

            SUM(receipt_lines.line_total) -
            (
                SUM(receipt_lines.line_quantity) * {$costExpression}
            ) as gross_profit,

            SUM(receipt_lines.base_line_total) -
            (
                SUM(receipt_lines.line_quantity) *
                {$costExpression} *
                CASE
                    WHEN {$costCurrencyExpression} = sales_transactions.base_currency_code
                        THEN 1
                    ELSE COALESCE(MAX(sales_transactions.exchange_rate), 1)
                END
            ) as base_gross_profit
        ")
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->groupBy(
                'receipt_lines.product_id',
                DB::raw("COALESCE(products.name, receipt_lines.line_name, 'Unknown Product')"),
                DB::raw('COALESCE(products.code, receipt_lines.product_code)'),
                DB::raw('COALESCE(products.sku, receipt_lines.sku)'),
                DB::raw("COALESCE(product_categories.name, 'Uncategorised')"),
                'receipt_lines.currency_code',
                'receipt_lines.base_currency_code',
                'sales_transactions.base_currency_code'
            )
            ->orderByDesc('base_gross_profit')
            ->get()
            ->map(function ($row) {
                $salesValue = (float)($row->sales_value ?? 0);
                $baseSalesValue = (float)($row->base_sales_value ?? 0);

                $row->quantity_sold = round((float)($row->quantity_sold ?? 0), 4);
                $row->average_selling_price = round((float)($row->average_selling_price ?? 0), 4);
                $row->minimum_selling_price = round((float)($row->minimum_selling_price ?? 0), 4);
                $row->maximum_selling_price = round((float)($row->maximum_selling_price ?? 0), 4);
                $row->average_cost = round((float)($row->average_cost ?? 0), 4);

                $row->subtotal = round((float)($row->subtotal ?? 0), 4);
                $row->discount = round((float)($row->discount ?? 0), 4);
                $row->tax = round((float)($row->tax ?? 0), 4);
                $row->sales_value = round($salesValue, 4);
                $row->base_sales_value = round($baseSalesValue, 4);

                $row->cost_value = round((float)($row->cost_value ?? 0), 4);
                $row->base_cost_value = round((float)($row->base_cost_value ?? 0), 4);
                $row->gross_profit = round((float)($row->gross_profit ?? 0), 4);
                $row->base_gross_profit = round((float)($row->base_gross_profit ?? 0), 4);

                $row->gross_margin_percent = $salesValue > 0
                    ? round(((float)$row->gross_profit / $salesValue) * 100, 2)
                    : 0;

                $row->base_gross_margin_percent = $baseSalesValue > 0
                    ? round(((float)$row->base_gross_profit / $baseSalesValue) * 100, 2)
                    : 0;

                return $row;
            })
            ->values();
    }

    private function profitAndLossProductLines(string $companyId, array $filters, int $perPage = 100)
    {
        $costing = app(SalesCostingService::class);
        $hospitality = $costing->isHospitality($companyId, $this->costingContextBranchId($filters));
        $unitCost = $costing->unitCostExpression($hospitality);
        $lineCost = $costing->lineCostExpression($hospitality);
        $costCurrency = $costing->costCurrencyExpression($hospitality, 'sales_transactions.base_currency_code');
        $reportingUnitCost = $this->costInSaleCurrencyExpression($unitCost, $costCurrency);
        $reportingLineCost = $this->costInSaleCurrencyExpression($lineCost, $costCurrency);
        $baseLineCost = $this->costInBaseCurrencyExpression($lineCost, $costCurrency);

        $query = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            });

        if ($hospitality) {
            $query
                ->leftJoinSub($costing->recipeMovementCostsByReceiptLine($companyId), 'recipe_line_costs', function ($join) {
                    $join->on('recipe_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                        ->on('recipe_line_costs.receipt_line_id', '=', 'receipt_lines.id');
                })
                ->leftJoinSub($costing->activeRecipeUnitCosts($companyId, $filters['branch_id']), 'active_recipe_costs', function ($join) {
                    $join->on('active_recipe_costs.menu_product_id', '=', 'receipt_lines.product_id')
                        ->on('active_recipe_costs.branch_id', '=', 'sales_transactions.branch_id');
                });
        } else {
            $query->leftJoinSub($costing->retailMovementCostsByReceiptLine($companyId), 'retail_line_costs', function ($join) {
                $join->on('retail_line_costs.sales_transaction_id', '=', 'receipt_lines.sales_transaction_id')
                    ->on('retail_line_costs.receipt_line_id', '=', 'receipt_lines.id');
            });
        }

        return $query->selectRaw("
            receipt_lines.id,
            receipt_lines.sales_transaction_id,
            sales_transactions.invoice_number,
            sales_transactions.transaction_date,
            sales_transactions.branch_id,
            sales_transactions.till_id,
            COALESCE(tills.till_name, tills.till_code, 'Unknown Till') as till_name,
            tills.till_code,
            sales_transactions.cashier_id,
            COALESCE(pos_users.name, pos_users.username, 'Unknown') as cashier_name,
            sales_transactions.shift_session_id,

            receipt_lines.product_id,
            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
            COALESCE(products.code, receipt_lines.product_code) as product_code,
            COALESCE(products.sku, receipt_lines.sku) as sku,
            COALESCE(product_categories.name, 'Uncategorised') as category_name,

            receipt_lines.currency_code,
            receipt_lines.base_currency_code,

            receipt_lines.line_quantity as quantity_sold,
            receipt_lines.line_price as selling_price,
            receipt_lines.line_subtotal as subtotal,
            receipt_lines.line_discount as discount,
            receipt_lines.line_tax as tax,
            receipt_lines.line_total as sales_value,
            receipt_lines.base_line_total as base_sales_value,

            {$reportingUnitCost} as average_cost,

            receipt_lines.currency_code as cost_currency_code,

            {$reportingLineCost} as cost_value,

            {$baseLineCost} as base_cost_value,

            receipt_lines.line_total - ({$reportingLineCost}) as gross_profit,

            receipt_lines.base_line_total - ({$baseLineCost}) as base_gross_profit,

            {$unitCost} as source_average_cost,
            {$lineCost} as source_cost_value,
            {$costCurrency} as source_cost_currency_code
        ")
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->orderByDesc('sales_transactions.transaction_date')
            ->paginate($perPage);
    }


    private function profitAndLossProductSummaryRows(string $companyId, array $filters)
    {
        $unitCostExpression = '
        COALESCE(
            NULLIF(MAX(product_costs.average_cost), 0),
            NULLIF(MAX(product_costs.last_cost), 0),
            0
        )
    ';

        $productCostCurrency = app(SalesCostingService::class)
            ->productCostCurrencyExpression() ?: 'NULL';
        $costCurrencyExpression = "
        COALESCE(MAX({$productCostCurrency}), MAX(receipt_lines.currency_code))
    ";

        $exchangeRateExpression = 'COALESCE(MAX(sales_transactions.exchange_rate), 1)';

        return DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->leftJoin('products', 'products.id', '=', 'receipt_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_costs', function ($join) {
                $join->on('product_costs.product_id', '=', 'receipt_lines.product_id')
                    ->on('product_costs.company_id', '=', 'sales_transactions.company_id')
                    ->on('product_costs.branch_id', '=', 'sales_transactions.branch_id');
            })
            ->selectRaw("
            receipt_lines.product_id,
            COALESCE(products.name, receipt_lines.line_name, 'Unknown Product') as product_name,
            COALESCE(products.code, receipt_lines.product_code) as product_code,
            COALESCE(products.sku, receipt_lines.sku) as sku,
            COALESCE(product_categories.name, 'Uncategorised') as category_name,

            MAX(receipt_lines.currency_code) as currency_code,
            MAX(receipt_lines.base_currency_code) as base_currency_code,
            {$costCurrencyExpression} as cost_currency_code,

            COUNT(DISTINCT sales_transactions.id) as receipt_count,
            COUNT(receipt_lines.id) as line_count,
            SUM(receipt_lines.line_quantity) as quantity_sold,

            CASE
                WHEN SUM(receipt_lines.line_quantity) > 0
                    THEN SUM(receipt_lines.line_total) / SUM(receipt_lines.line_quantity)
                ELSE 0
            END as average_selling_price,

            MIN(receipt_lines.line_price) as minimum_selling_price,
            MAX(receipt_lines.line_price) as maximum_selling_price,
            {$unitCostExpression} as average_cost,

            SUM(receipt_lines.line_subtotal) as subtotal,
            SUM(receipt_lines.line_discount) as discount,
            SUM(receipt_lines.line_tax) as tax,
            SUM(receipt_lines.line_total) as sales_value,
            SUM(receipt_lines.base_line_total) as base_sales_value,

            SUM(receipt_lines.line_quantity) * {$unitCostExpression} as cost_value,

            SUM(receipt_lines.line_quantity) * {$unitCostExpression} *
            CASE
                WHEN {$costCurrencyExpression} = MAX(sales_transactions.base_currency_code)
                    THEN 1
                ELSE {$exchangeRateExpression}
            END as base_cost_value,

            SUM(receipt_lines.line_total) -
            (SUM(receipt_lines.line_quantity) * {$unitCostExpression}) as gross_profit,

            SUM(receipt_lines.base_line_total) -
            (
                SUM(receipt_lines.line_quantity) * {$unitCostExpression} *
                CASE
                    WHEN {$costCurrencyExpression} = MAX(sales_transactions.base_currency_code)
                        THEN 1
                    ELSE {$exchangeRateExpression}
                END
            ) as base_gross_profit
        ")
            ->where('sales_transactions.company_id', $companyId)
            ->where('sales_transactions.status', 'completed')
            ->where('sales_transactions.sale_type', 'sale')
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('receipt_lines.currency_code', $filters['currency_codes']))
            ->groupBy(
                'receipt_lines.product_id',
                DB::raw("COALESCE(products.name, receipt_lines.line_name, 'Unknown Product')"),
                DB::raw('COALESCE(products.code, receipt_lines.product_code)'),
                DB::raw('COALESCE(products.sku, receipt_lines.sku)'),
                DB::raw("COALESCE(product_categories.name, 'Uncategorised')")
            )
            ->orderByDesc('base_gross_profit');
    }


    private function profitAndLossCashouts(
        string $companyId,
        array $filters,
        ?string $reportCurrencyCode = null
    ): array
    {
        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
        $cashInTypes = ['cash_in', 'float_in'];

        $baseQuery = DB::table('pos_cash_drawer_movements')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('occurred_at', '>=', $filters['date_from'])
            ->whereDate('occurred_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('currency_code', $filters['currency_codes']));

        $summaryByTypeCurrency = (clone $baseQuery)
            ->selectRaw('
            movement_type,
            currency_code,
            COUNT(*) as movement_count,
            SUM(amount) as amount
        ')
            ->groupBy('movement_type', 'currency_code')
            ->orderBy('movement_type')
            ->orderBy('currency_code')
            ->get()
            ->map(fn($row) => [
                'movement_type' => $row->movement_type,
                'currency_code' => $row->currency_code,
                'movement_count' => (int)$row->movement_count,
                'amount' => round((float)$row->amount, 4),
            ])
            ->values();

        $currencySummary = $this->summarizeProfitAndLossCashMovements(
            $summaryByTypeCurrency,
            $cashOutTypes,
            $cashInTypes,
            $reportCurrencyCode ?: ($filters['currency_code'] ?? null)
        );

        $byDate = (clone $baseQuery)
            ->selectRaw('
            DATE(occurred_at) as movement_date,
            currency_code,
            movement_type,
            COUNT(*) as movement_count,
            SUM(amount) as amount
        ')
            ->groupByRaw('DATE(occurred_at), currency_code, movement_type')
            ->orderBy('movement_date')
            ->orderBy('currency_code')
            ->orderBy('movement_type')
            ->get()
            ->map(fn($row) => [
                'movement_date' => $row->movement_date,
                'currency_code' => $row->currency_code,
                'movement_type' => $row->movement_type,
                'movement_count' => (int)$row->movement_count,
                'amount' => round((float)$row->amount, 4),
            ])
            ->values();

        return [
            'summary' => $currencySummary,
            'by_date' => $byDate,
        ];
    }

    /**
     * Drawer movements only store their native currency amount. They do not
     * retain the historical exchange-rate snapshot needed for a defensible
     * conversion into another reporting currency. Keep the per-currency
     * buckets authoritative and expose combined scalars only when every
     * relevant movement is already in the report currency.
     */
    private function summarizeProfitAndLossCashMovements(
        $summaryByTypeCurrency,
        array $cashOutTypes,
        array $cashInTypes,
        ?string $reportCurrencyCode
    ): array {
        $rows = collect($summaryByTypeCurrency)->map(function ($row) {
            $currencyCode = strtoupper(trim((string)data_get($row, 'currency_code')));

            return [
                'movement_type' => (string)data_get($row, 'movement_type'),
                'currency_code' => $currencyCode !== '' ? $currencyCode : null,
                'movement_count' => (int)data_get($row, 'movement_count', 0),
                'amount' => round((float)data_get($row, 'amount', 0), 4),
            ];
        })->values();

        $relevantTypes = array_values(array_unique(array_merge($cashOutTypes, $cashInTypes)));
        $relevantRows = $rows->filter(
            fn($row) => in_array($row['movement_type'], $relevantTypes, true)
        )->values();

        $totalsByCurrency = $relevantRows
            ->groupBy(fn($row) => $row['currency_code'] ?? '__UNSPECIFIED__')
            ->map(function ($currencyRows, $currencyKey) use ($cashOutTypes, $cashInTypes) {
                $cashoutRows = $currencyRows->filter(
                    fn($row) => in_array($row['movement_type'], $cashOutTypes, true)
                );
                $cashinRows = $currencyRows->filter(
                    fn($row) => in_array($row['movement_type'], $cashInTypes, true)
                );
                $cashoutTotal = round((float)$cashoutRows->sum('amount'), 4);
                $cashinTotal = round((float)$cashinRows->sum('amount'), 4);

                return [
                    'currency_code' => $currencyKey === '__UNSPECIFIED__' ? null : $currencyKey,
                    'movement_count' => (int)$currencyRows->sum('movement_count'),
                    'cashout_count' => (int)$cashoutRows->sum('movement_count'),
                    'cashin_count' => (int)$cashinRows->sum('movement_count'),
                    'cashout_total' => $cashoutTotal,
                    'cashin_total' => $cashinTotal,
                    'net_cash_movement' => round($cashinTotal - $cashoutTotal, 4),
                ];
            })
            ->sortBy(fn($row) => $row['currency_code'] ?? '')
            ->values();

        $normalizedReportCurrency = strtoupper(trim((string)$reportCurrencyCode));
        $movementCurrencies = $totalsByCurrency
            ->map(fn($row) => $row['currency_code'])
            ->uniqueStrict()
            ->values();
        $hasRelevantMovements = $relevantRows->isNotEmpty();
        $currencyCompatible = !$hasRelevantMovements || (
            $normalizedReportCurrency !== ''
            && $movementCurrencies->every(
                fn($currencyCode) => $currencyCode === $normalizedReportCurrency
            )
        );
        $isMixedCurrency = $movementCurrencies->count() > 1;
        $hasUnconvertedAmounts = $hasRelevantMovements && !$currencyCompatible;

        $cashoutTotal = null;
        $cashinTotal = null;
        $netCashMovement = null;

        if ($currencyCompatible) {
            $cashoutTotal = round((float)$totalsByCurrency->sum('cashout_total'), 4);
            $cashinTotal = round((float)$totalsByCurrency->sum('cashin_total'), 4);
            $netCashMovement = round($cashinTotal - $cashoutTotal, 4);
        }

        $message = null;
        if ($hasUnconvertedAmounts) {
            $movementCurrencyLabel = $movementCurrencies
                ->map(fn($currencyCode) => $currencyCode ?: 'unspecified currency')
                ->implode(', ');
            $reportCurrencyLabel = $normalizedReportCurrency ?: 'an unspecified report currency';
            $message = 'Cash movement headline totals are unavailable because drawer movements are recorded in '
                . $movementCurrencyLabel . ' while this profit and loss report is in '
                . $reportCurrencyLabel . '. Historical exchange-rate snapshots are not stored for drawer '
                . 'movements; use totals_by_currency instead.';
        }

        return [
            'cashout_total' => $cashoutTotal,
            'cashin_total' => $cashinTotal,
            'net_cash_movement' => $netCashMovement,
            'movement_count' => (int)$relevantRows->sum('movement_count'),
            'report_currency_code' => $normalizedReportCurrency ?: null,
            'currency_code' => $currencyCompatible ? ($normalizedReportCurrency ?: null) : null,
            'currency_compatible' => $currencyCompatible,
            'is_mixed_currency' => $isMixedCurrency,
            'has_unconverted_amounts' => $hasUnconvertedAmounts,
            'message' => $message,
            'totals_by_currency' => $totalsByCurrency->all(),
            'summary_by_type_currency' => $rows->all(),
        ];
    }

    private function profitAndLossAfterCashMovements(
        float $grossProfit,
        float $salesValue,
        array $cashMovementSummary
    ): array {
        $currencyCompatible = ($cashMovementSummary['currency_compatible'] ?? false) === true;
        $cashoutTotal = $currencyCompatible
            ? round((float)$cashMovementSummary['cashout_total'], 4)
            : null;
        $cashinTotal = $currencyCompatible
            ? round((float)$cashMovementSummary['cashin_total'], 4)
            : null;
        $netProfit = $currencyCompatible
            ? round($grossProfit - $cashoutTotal + $cashinTotal, 4)
            : null;
        $netCashMovement = $currencyCompatible
            ? round($cashinTotal - $cashoutTotal, 4)
            : null;

        return [
            'cashout_total' => $cashoutTotal,
            'cashin_total' => $cashinTotal,
            'net_cash_movement' => $netCashMovement,
            'net_profit_after_cashouts' => $netProfit,
            'net_margin_after_cashouts_percent' => $currencyCompatible
                ? ($salesValue > 0 ? round(($netProfit / $salesValue) * 100, 2) : 0)
                : null,
            'currency_compatible' => $currencyCompatible,
            'is_mixed_currency' => (bool)($cashMovementSummary['is_mixed_currency'] ?? false),
            'has_unconverted_amounts' => (bool)($cashMovementSummary['has_unconverted_amounts'] ?? false),
            'message' => $cashMovementSummary['message'] ?? null,
        ];
    }


    private function profitAndLossCashoutsByDate(string $companyId, array $filters)
    {
        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];

        return DB::table('pos_cash_drawer_movements')
            ->selectRaw('
            DATE(occurred_at) as movement_date,
            currency_code,
            movement_type,
            COUNT(*) as movement_count,
            SUM(amount) as amount
        ')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('movement_type', $cashOutTypes)
            ->whereDate('occurred_at', '>=', $filters['date_from'])
            ->whereDate('occurred_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('currency_code', $filters['currency_codes']))
            ->groupByRaw('DATE(occurred_at), currency_code, movement_type')
            ->orderBy('movement_date')
            ->orderBy('currency_code')
            ->orderBy('movement_type')
            ->get()
            ->map(fn($row) => [
                'movement_date' => $row->movement_date,
                'currency_code' => $row->currency_code,
                'movement_type' => $row->movement_type,
                'movement_count' => (int)$row->movement_count,
                'amount' => round((float)$row->amount, 4),
            ])
            ->values();
    }

    private function profitAndLossCashoutRows(string $companyId, array $filters, int $perPage = 100)
    {
        $cashOutTypes = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];

        return DB::table('pos_cash_drawer_movements')
            ->leftJoin('pos_users as cashiers', 'cashiers.id', '=', 'pos_cash_drawer_movements.cashier_id')
            ->leftJoin('pos_users as creators', 'creators.id', '=', 'pos_cash_drawer_movements.created_by')
            ->leftJoin('pos_users as approvers', 'approvers.id', '=', 'pos_cash_drawer_movements.approved_by')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'pos_cash_drawer_movements.till_id')
            ->selectRaw('
            pos_cash_drawer_movements.id,
            pos_cash_drawer_movements.company_id,
            pos_cash_drawer_movements.branch_id,
            pos_cash_drawer_movements.till_id,
            COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
            tills.till_code,

            pos_cash_drawer_movements.shift_session_id,
            pos_cash_drawer_movements.cashier_id,
            COALESCE(cashiers.name, cashiers.username, "Unknown") as cashier_name,

            pos_cash_drawer_movements.movement_type,
            pos_cash_drawer_movements.amount,
            pos_cash_drawer_movements.currency_code,
            pos_cash_drawer_movements.reason,
            pos_cash_drawer_movements.notes,
            pos_cash_drawer_movements.reference_no,

            pos_cash_drawer_movements.approved_by,
            COALESCE(approvers.name, approvers.username, "N/A") as approved_by_name,

            pos_cash_drawer_movements.created_by,
            COALESCE(creators.name, creators.username, "Unknown") as created_by_name,

            pos_cash_drawer_movements.occurred_at,
            pos_cash_drawer_movements.created_at
        ')
            ->where('pos_cash_drawer_movements.company_id', $companyId)
            ->whereNull('pos_cash_drawer_movements.deleted_at')
            ->whereIn('pos_cash_drawer_movements.movement_type', $cashOutTypes)
            ->whereDate('pos_cash_drawer_movements.occurred_at', '>=', $filters['date_from'])
            ->whereDate('pos_cash_drawer_movements.occurred_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'pos_cash_drawer_movements.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('pos_cash_drawer_movements.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('pos_cash_drawer_movements.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('pos_cash_drawer_movements.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('pos_cash_drawer_movements.currency_code', $filters['currency_codes']))
            ->orderByDesc('pos_cash_drawer_movements.occurred_at')
            ->paginate($perPage);
    }


    public function dashboardExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'dashboard', 'excel');
    }

    public function dashboardPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'dashboard', 'pdf');
    }

    public function dailySalesExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'daily-sales', 'excel');
    }

    public function dailySalesPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'daily-sales', 'pdf');
    }

    public function salesListExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'sales-list', 'excel');
    }

    public function salesListPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'sales-list', 'pdf');
    }

    public function paymentsExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'payments', 'excel');
    }

    public function paymentsPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'payments', 'pdf');
    }

    public function productsExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'products', 'excel');
    }

    public function productsPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'products', 'pdf');
    }

    public function cashiersExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'cashiers', 'excel');
    }

    public function cashiersPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'cashiers', 'pdf');
    }

    public function shiftsExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'shifts', 'excel');
    }

    public function shiftsPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'shifts', 'pdf');
    }

    public function dayEndsExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'day-ends', 'excel');
    }

    public function dayEndsPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'day-ends', 'pdf');
    }

    public function profitAndLossExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'profit-loss', 'excel');
    }

    public function profitAndLossPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'profit-loss', 'pdf');
    }

    public function branchTurnoverExcel(Request $request)
    {
        return $this->salesReportDownload($request, 'branch-turnover', 'excel');
    }

    public function branchTurnoverPdf(Request $request)
    {
        return $this->salesReportDownload($request, 'branch-turnover', 'pdf');
    }

    private function salesReportDownload(Request $request, string $reportKey, string $format)
    {
        $data = $this->salesReportExportData($request, $reportKey);
        $filename = $data['slug'] . '-' . now()->format('Ymd-His') . ($format === 'pdf' ? '.pdf' : '.xlsx');

        if ($format === 'pdf') {
            return $this->reportDownloads->downloadPdf(
                $data['title'],
                $filename,
                $data['sections'],
                rowLimit: 700
            );
        }

        return $this->reportDownloads->downloadSpreadsheet(
            $data['title'],
            $filename,
            $data['sheets'],
            autoFilter: true
        );
    }

    private function salesReportExportData(Request $request, string $reportKey): array
    {
        $company = app('company');
        $canViewCashupAmounts = $this->canViewCashupFinancialAmounts((string) $company->id);
        $filters = $this->filters($request);
        if (in_array($reportKey, ['branch-turnover', 'shifts', 'day-ends'], true)) {
            $filters = $this->scopedAccessibleReportFilters($request, (string)$company->id, $filters);
        }
        $limit = (int)$request->input('export_limit', 10000);
        $businessMode = $this->resolveSalesBusinessMode($company->id, $this->costingContextBranchId($filters));
        $baseCurrencyCode = $this->baseCurrencyCode($company->id, $filters)
            ?: $this->defaultReportingCurrencyCode($company->id, $this->costingContextBranchId($filters))
            ?: 'USD';
        $currencyCodes = $this->reportCurrencyCodes($company->id, $filters, $baseCurrencyCode);
        $summaryRows = [
            ['Business Mode', $businessMode['label']],
            ['Cost Source', $businessMode['cost_source']],
            ['BOH Enabled', $businessMode['boh_enabled'] ? 'Yes' : 'No'],
            ['Fast Food Mode Enabled', $businessMode['fast_food_mode_enabled'] ? 'Yes' : 'No'],
            ['Date From', $filters['date_from']],
            ['Date To', $filters['date_to']],
            ['Currency Filter', $filters['currency_codes'] ? implode(', ', $filters['currency_codes']) : 'All'],
            ['Base Currency', $baseCurrencyCode],
            ['Generated At', now()->toDateTimeString()],
        ];

        if ($reportKey === 'dashboard') {
            $title = 'Sales Dashboard Report';
            $currencySummary = $this->salesByCurrency($company->id, $filters);
            $paymentSummary = $this->paymentSummaryByCurrency($company->id, $filters);
            $topProducts = $this->topProducts($company->id, $filters, 100);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Currency Summary', 'headers' => array_merge(['Metric'], $currencyCodes), 'rows' => $this->currencyMetricRows($currencySummary, $currencyCodes, ['Transactions' => 'transaction_count', 'Subtotal' => 'subtotal', 'Discount' => 'discount', 'Tax' => 'tax', 'Total' => 'total'])],
                ['title' => 'Payment Summary', 'headers' => $this->currencyPivotHeaders(['Payment', 'Money Code'], ['Count'], $currencyCodes, ['Amount', 'Tendered', 'Change']), 'rows' => $this->currencyPivotRows($paymentSummary, $currencyCodes, ['payment_name', 'money_code'], ['payment_name', 'money_code'], ['payment_count'], ['amount', 'amount_tendered', 'change_amount'])],
                ['title' => 'Top Products With Credit Collection', 'headers' => $this->currencyPivotHeaders(['Product'], ['Receipts', 'Qty Sold'], $currencyCodes, ['Invoiced', 'Cash Sales', 'Credit Issued', 'Credit Paid', 'Outstanding', 'Received', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($topProducts, $currencyCodes, ['product_id', 'product_name'], ['product_name'], ['receipt_count', 'quantity_sold'], ['invoiced_value', 'cash_sale_value', 'credit_issued_value', 'credit_paid_value', 'credit_outstanding_value', 'money_received_value', 'cost_value', 'gross_profit'])],
            ];
            return $this->buildReportPayload($title, 'sales-dashboard-report', $sections);
        }

        if ($reportKey === 'daily-sales') {
            $businessDate = $request->business_date ?: $request->date ?: $filters['date_from'];
            $filters['date_from'] = $businessDate;
            $filters['date_to'] = $businessDate;
            $filters['status'] = 'completed';
            $summary = $this->dailyProfitSummary($company->id, $filters);
            $summaryRows = array_merge($summaryRows, $this->arraySummaryRows($summary));
            $cashiers = $this->dailyCashierSummary($company->id, $filters);
            $payments = $this->dailyPaymentTypeSummary($company->id, $filters);
            $products = $this->dailyProductSales($company->id, $filters, $limit);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Cashier Summary', 'headers' => $this->currencyPivotHeaders(['Cashier'], ['Transactions', 'Products Sold'], $currencyCodes, ['Subtotal', 'Discount', 'Tax', 'Sales', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($cashiers, $currencyCodes, ['cashier_id', 'cashier_name'], ['cashier_name'], ['transaction_count', 'product_sold_quantity'], ['subtotal', 'discount', 'tax', 'sales_value', 'cost_value', 'estimated_profit'])],
                ['title' => 'Payment Summary', 'headers' => $this->currencyPivotHeaders(['Payment', 'Money Code'], ['Count'], $currencyCodes, ['Amount', 'Tendered', 'Change']), 'rows' => $this->currencyPivotRows($payments, $currencyCodes, ['payment_name', 'money_code'], ['payment_name', 'money_code'], ['payment_count'], ['amount', 'amount_tendered', 'change_amount'])],
                ['title' => 'Product Sales With Credit Collection', 'headers' => $this->currencyPivotHeaders(['Product'], ['Receipts', 'Qty Sold'], $currencyCodes, ['Invoiced', 'Cash Sales', 'Credit Issued', 'Credit Paid', 'Outstanding', 'Received', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($products, $currencyCodes, ['product_id', 'product_name'], ['product_name'], ['receipt_count', 'quantity_sold'], ['invoiced_value', 'cash_sale_value', 'credit_issued_value', 'credit_paid_value', 'credit_outstanding_value', 'money_received_value', 'cost_value', 'gross_profit'])],
            ];
            return $this->buildReportPayload('Daily Sales Report', 'daily-sales-report', $sections);
        }

        if ($reportKey === 'sales-list') {
            $records = $this->salesListExportRows($company->id, $filters, $limit);
            $summaryRows = array_merge($summaryRows, $this->arraySummaryRows($this->salesListSummary($company->id, $filters)));
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Sales Transactions', 'headers' => $this->currencyPivotHeaders(['Receipt', 'Date', 'Cashier', 'Till', 'Customer', 'Payment', 'Status'], ['Products Sold'], $currencyCodes, ['Invoiced', 'Received', 'Credit Paid', 'Outstanding', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($records, $currencyCodes, ['invoice_number'], ['invoice_number', 'transaction_date', 'cashier_name', 'till_name', 'customer_name', 'payment_method', 'status'], ['product_sold_quantity'], ['invoiced_value', 'sales_value', 'credit_paid_value', 'credit_outstanding_value', 'cost_value', 'estimated_profit'])],
            ];
            return $this->buildReportPayload('Sales List Report', 'sales-list-report', $sections);
        }

        if ($reportKey === 'payments') {
            $payments = $this->paymentSummaryByCurrency($company->id, $filters);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Payment Summary', 'headers' => $this->currencyPivotHeaders(['Payment', 'Money Code'], ['Count'], $currencyCodes, ['Amount', 'Tendered', 'Change']), 'rows' => $this->currencyPivotRows($payments, $currencyCodes, ['payment_name', 'money_code'], ['payment_name', 'money_code'], ['payment_count'], ['amount', 'amount_tendered', 'change_amount'])],
            ];
            return $this->buildReportPayload('Payment Summary Report', 'payment-summary-report', $sections);
        }

        if ($reportKey === 'products') {
            $products = $this->topProducts($company->id, $filters, $limit);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Product Sales With Credit Collection', 'headers' => $this->currencyPivotHeaders(['Product'], ['Receipts', 'Qty Sold'], $currencyCodes, ['Average Cost', 'Invoiced', 'Cash Sales', 'Credit Issued', 'Credit Paid', 'Outstanding', 'Received', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($products, $currencyCodes, ['product_id', 'product_name'], ['product_name'], ['receipt_count', 'quantity_sold'], ['average_cost', 'invoiced_value', 'cash_sale_value', 'credit_issued_value', 'credit_paid_value', 'credit_outstanding_value', 'money_received_value', 'cost_value', 'gross_profit'])],
            ];
            return $this->buildReportPayload('Product Sales Report', 'product-sales-report', $sections);
        }

        if ($reportKey === 'cashiers') {
            $cashiers = $this->cashierPerformance($company->id, $filters);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Cashier Sales', 'headers' => $this->currencyPivotHeaders(['Cashier'], ['Transactions'], $currencyCodes, ['Subtotal', 'Discount', 'Tax', 'Sales']), 'rows' => $this->currencyPivotRows($cashiers, $currencyCodes, ['cashier_id', 'cashier_name'], ['cashier_name'], ['transaction_count'], ['subtotal', 'discount', 'tax', 'total'])],
            ];
            return $this->buildReportPayload('Cashier Sales Report', 'cashier-sales-report', $sections);
        }

        if ($reportKey === 'branch-turnover') {
            $branchRows = $this->branchTurnoverRows((string)$company->id, $filters);
            $summaryRows = array_merge($summaryRows, [
                ['Accessible Branches', $branchRows->pluck('branch_id')->filter()->unique()->count()],
                ['Transactions', (int)$branchRows->sum('transaction_count')],
                ['Base Sales', $this->salesReportAmount($branchRows->sum('base_sales_value'))],
                ['Base Cost', $this->salesReportAmount($branchRows->sum('base_cost_value'))],
                ['Base Profit', $this->salesReportAmount($branchRows->sum('base_profit_value'))],
            ]);
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Branch Turnover', 'headers' => $this->currencyPivotHeaders(['Branch'], ['Transactions'], $currencyCodes, ['Sales', 'Cost', 'Profit']), 'rows' => $this->currencyPivotRows($branchRows, $currencyCodes, ['branch_id', 'branch_name'], ['branch_name'], ['transaction_count'], ['sales_value', 'cost_value', 'profit_value'])],
            ];
            return $this->buildReportPayload('Branch Turnover Report', 'branch-turnover-report', $sections);
        }

        if ($reportKey === 'shifts') {
            $records = $this->shiftSalesExportRows($company->id, $filters, $limit);
            $shiftIdentityHeaders = ['Shift', 'Cashier', 'Opened At', 'Closed At', 'Status'];
            $shiftIdentityFields = ['shift_name', 'cashier_name', 'opened_at', 'closed_at', 'status'];
            $shiftCurrencyHeaders = [];
            $shiftCurrencyFields = [];
            if ($canViewCashupAmounts) {
                $shiftIdentityHeaders[] = 'Cashup Variance';
                $shiftIdentityFields[] = 'cashup_variance';
                $shiftCurrencyHeaders[] = 'Sales';
                $shiftCurrencyFields[] = 'sales_value';
            } else {
                $summaryRows[] = ['Cashup Financial Amounts', 'Restricted by role permission'];
            }
            $sections = [
                ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Shift Sales', 'headers' => $this->currencyPivotHeaders($shiftIdentityHeaders, ['Transactions'], $currencyCodes, $shiftCurrencyHeaders), 'rows' => $this->currencyPivotRows($records, $currencyCodes, ['shift_session_id'], $shiftIdentityFields, ['transaction_count'], $shiftCurrencyFields)],
            ];
            return $this->buildReportPayload('Shift Sales Report', 'shift-sales-report', $sections);
        }

        if ($reportKey === 'profit-loss') {
            $products = $this->creditAwareProductReportRows($company->id, $filters, $limit);
            $reportCurrencyCode = $filters['currency_code']
                ?: $baseCurrencyCode;
            $cashMovements = $this->profitAndLossCashouts(
                $company->id,
                $filters,
                $reportCurrencyCode
            );
            $useBaseValues = count($filters['currency_codes']) !== 1;
            $salesValue = round((float) $products->sum(
                $useBaseValues ? 'base_money_received_value' : 'money_received_value'
            ), 4);
            $costValue = round((float) $products->sum(
                $useBaseValues ? 'base_cost_value' : 'cost_value'
            ), 4);
            $grossProfit = round($salesValue - $costValue, 4);
            $afterCashMovements = $this->profitAndLossAfterCashMovements(
                $grossProfit,
                $salesValue,
                $cashMovements['summary']
            );
            $cashoutTotal = $afterCashMovements['cashout_total'];
            $cashinTotal = $afterCashMovements['cashin_total'];
            $netProfit = $afterCashMovements['net_profit_after_cashouts'];
            $unavailableLabel = 'Per currency only';

            $summaryRows = array_merge($summaryRows, [
                ['Report Currency', $reportCurrencyCode],
                ['Money Received', $this->salesReportAmount($salesValue)],
                ['Cost of Sales', $this->salesReportAmount($costValue)],
                ['Gross Profit', $this->salesReportAmount($grossProfit)],
                ['Gross Margin %', $this->salesReportAmount($salesValue > 0 ? ($grossProfit / $salesValue) * 100 : 0)],
                ['Cash Outs', $cashoutTotal === null ? $unavailableLabel : $this->salesReportAmount($cashoutTotal)],
                ['Cash Ins', $cashinTotal === null ? $unavailableLabel : $this->salesReportAmount($cashinTotal)],
                ['Net Profit After Cash Movements', $netProfit === null ? $unavailableLabel : $this->salesReportAmount($netProfit)],
                ['Net Margin After Cash Movements %', $afterCashMovements['net_margin_after_cashouts_percent'] === null
                    ? $unavailableLabel
                    : $this->salesReportAmount($afterCashMovements['net_margin_after_cashouts_percent'])],
                ['Cash Movement Currency Status', $afterCashMovements['currency_compatible']
                    ? 'Compatible with report currency'
                    : 'Unconverted; see per-currency totals'],
            ]);

            if ($afterCashMovements['message']) {
                $summaryRows[] = ['Cash Movement Warning', $afterCashMovements['message']];
            }

            $cashMovementTotals = collect($cashMovements['summary']['totals_by_currency'] ?? [])
                ->map(fn($row) => [
                    data_get($row, 'currency_code') ?: 'Unspecified',
                    (int)data_get($row, 'movement_count', 0),
                    $this->salesReportAmount(data_get($row, 'cashout_total', 0)),
                    $this->salesReportAmount(data_get($row, 'cashin_total', 0)),
                    $this->salesReportAmount(data_get($row, 'net_cash_movement', 0)),
                ])
                ->values()
                ->all();
            $cashMovementCurrencyCodes = collect($currencyCodes)
                ->merge(collect($cashMovements['summary']['totals_by_currency'] ?? [])->pluck('currency_code'))
                ->map(fn($code) => strtoupper(trim((string)$code)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sections = [
                ['title' => 'Profit and Loss Summary', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Profit and Loss by Currency', 'headers' => array_merge(['Metric'], $currencyCodes), 'rows' => $this->currencyMetricRows($products->groupBy(fn($row) => strtoupper((string)$row->currency_code))->map(function ($rows, $currencyCode) {
                    return (object)[
                        'currency_code' => $currencyCode,
                        'sales' => $rows->sum('money_received_value'),
                        'cost' => $rows->sum('cost_value'),
                        'profit' => $rows->sum('money_received_gross_profit'),
                    ];
                })->values(), $currencyCodes, ['Sales' => 'sales', 'Cost of Sales' => 'cost', 'Gross Profit' => 'profit'])],
                ['title' => 'Product Profitability', 'headers' => $this->currencyPivotHeaders(['Product', 'Category'], ['Qty'], $currencyCodes, ['Invoiced', 'Money Received', 'Unit Cost', 'Cost', 'Gross Profit']), 'rows' => $this->currencyPivotRows($products, $currencyCodes, ['product_id', 'product_name'], ['product_name', 'category_name'], ['quantity_sold'], ['invoiced_value', 'money_received_value', 'average_cost', 'cost_value', 'money_received_gross_profit'])],
                ['title' => 'Cash Movement Totals by Currency', 'headers' => ['Currency', 'Count', 'Cash Outs', 'Cash Ins', 'Net Movement'], 'rows' => $cashMovementTotals],
                ['title' => 'Cash Movements', 'headers' => $this->currencyPivotHeaders(['Date', 'Type'], ['Count'], $cashMovementCurrencyCodes, ['Amount']), 'rows' => $this->currencyPivotRows($cashMovements['by_date'] ?? [], $cashMovementCurrencyCodes, ['movement_date', 'movement_type'], ['movement_date', 'movement_type'], ['movement_count'], ['amount'])],
            ];

            if (($businessMode['business_mode'] ?? null) === 'hospitality') {
                $comparison = $this->monthlyProfitComparison(
                    $company->id,
                    $filters,
                    $baseCurrencyCode
                );
                $sections[] = [
                    'title' => 'Monthly Profit Comparison',
                    'headers' => [
                        'Period', 'From', 'To', 'Sales', 'Cost of Sales', 'Gross Profit',
                        'Gross Margin %', 'Cash Outs', 'Cash Ins', 'Net Profit', 'Net Margin %', 'Currency',
                    ],
                    'rows' => collect($comparison['periods'] ?? [])->map(fn($period) => [
                        data_get($period, 'label'),
                        data_get($period, 'date_from'),
                        data_get($period, 'date_to'),
                        $this->salesReportAmount(data_get($period, 'sales_value')),
                        $this->salesReportAmount(data_get($period, 'cost_value')),
                        $this->salesReportAmount(data_get($period, 'gross_profit')),
                        $this->salesReportAmount(data_get($period, 'gross_margin_percent')),
                        data_get($period, 'cashout_total') === null
                            ? $unavailableLabel
                            : $this->salesReportAmount(data_get($period, 'cashout_total')),
                        data_get($period, 'cashin_total') === null
                            ? $unavailableLabel
                            : $this->salesReportAmount(data_get($period, 'cashin_total')),
                        data_get($period, 'net_profit_after_cashouts') === null
                            ? $unavailableLabel
                            : $this->salesReportAmount(data_get($period, 'net_profit_after_cashouts')),
                        data_get($period, 'net_margin_after_cashouts_percent') === null
                            ? $unavailableLabel
                            : $this->salesReportAmount(data_get($period, 'net_margin_after_cashouts_percent')),
                        data_get($period, 'currency_code'),
                    ])->values()->all(),
                ];
                $sections[] = [
                    'title' => 'Monthly Profit Change',
                    'headers' => ['Metric', 'Value'],
                    'rows' => [
                        ['Gross Profit Change', $this->salesReportAmount($comparison['gross_profit_change'] ?? 0)],
                        ['Gross Profit Change %', ($comparison['gross_profit_change_percent'] ?? null) === null
                            ? 'Not available (previous month was zero)'
                            : $this->salesReportAmount($comparison['gross_profit_change_percent']) . '%'],
                        ['Comparison Basis', (string)($comparison['comparison_basis'] ?? '')],
                    ],
                ];
            }

            return $this->buildReportPayload('Profit and Loss Report', 'profit-loss-report', $sections);
        }

        $dayEnd = $this->dayEndExportRows($company->id, $filters);
        $cashupMetrics = $canViewCashupAmounts
            ? ['Cashups', 'Expected Cash', 'Cashouts', 'Expected After Cashouts', 'Counted Cash', 'Cash Variance', 'Expected Total', 'Counted Total', 'Total Variance']
            : ['Cashups'];
        $cashupMetricFields = $canViewCashupAmounts
            ? ['cashup_count', 'expected_cash', 'cashout_total', 'expected_cash_after_cashouts', 'counted_cash', 'cash_variance', 'expected_total', 'counted_total', 'total_variance']
            : ['cashup_count'];
        if (!$canViewCashupAmounts) {
            $summaryRows[] = ['Cashup Financial Amounts', 'Restricted by role permission'];
        }
        $dayEndSalesMetrics = $canViewCashupAmounts ? ['Subtotal', 'Discount', 'Tax', 'Total'] : [];
        $dayEndSalesMetricFields = $canViewCashupAmounts ? ['subtotal', 'discount', 'tax', 'total'] : [];
        $sections = [
            ['title' => 'Report Header', 'headers' => ['Metric', 'Value'], 'rows' => $summaryRows],
            ['title' => 'Sales by Day and Currency', 'headers' => $this->currencyPivotHeaders(['Date'], ['Transactions'], $currencyCodes, $dayEndSalesMetrics), 'rows' => $this->currencyPivotRows($dayEnd['sales_records'], $currencyCodes, ['business_date'], ['business_date'], ['transaction_count'], $dayEndSalesMetricFields)],
            ['title' => 'Cashups by Day', 'headers' => $this->currencyPivotHeaders(['Date'], [], $currencyCodes, $cashupMetrics), 'rows' => $this->currencyPivotRows($dayEnd['cashup_records'], $currencyCodes, ['business_date'], ['business_date'], [], $cashupMetricFields)],
        ];
        return $this->buildReportPayload('Day Ends Report', 'day-ends-report', $sections);
    }

    private function salesListExportRows(string $companyId, array $filters, int $limit)
    {
        $lineSummary = $this->saleLineSummarySubquery($companyId, $filters);
        $costSummary = $this->saleCostSummarySubquery($companyId, $filters);
        $saleCreditAllocations = $this->creditAllocationBySaleSubquery($companyId, $filters);

        return DB::table('sales_transactions')
            ->leftJoinSub($lineSummary, 'line_summary', fn($join) => $join->on('line_summary.sales_transaction_id', '=', 'sales_transactions.id'))
            ->leftJoinSub($costSummary, 'cost_summary', fn($join) => $join->on('cost_summary.sales_transaction_id', '=', 'sales_transactions.id'))
            ->leftJoinSub($saleCreditAllocations, 'sale_credit_allocations', fn($join) => $join->on('sale_credit_allocations.sales_transaction_id', '=', 'sales_transactions.id'))
            ->leftJoin('pos_users', 'pos_users.id', '=', 'sales_transactions.cashier_id')
            ->leftJoin('pos_tills as tills', 'tills.id', '=', 'sales_transactions.till_id')
            ->selectRaw('
                sales_transactions.invoice_number,
                sales_transactions.transaction_date,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                COALESCE(tills.till_name, tills.till_code, "Unknown Till") as till_name,
                sales_transactions.customer_name,
                sales_transactions.payment_method,
                sales_transactions.currency_code,
                COALESCE(line_summary.product_sold_quantity, 0) as product_sold_quantity,
                COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) as invoiced_value,
                CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END as sales_value,
                COALESCE(sale_credit_allocations.credit_paid_value, 0) as credit_paid_value,
                GREATEST(CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) ELSE 0 END - COALESCE(sale_credit_allocations.credit_paid_value, 0), 0) as credit_outstanding_value,
                CASE
                    WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN
                        COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0))
                    ELSE COALESCE(cost_summary.cost_value, 0)
                END as cost_value,
                (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(sale_credit_allocations.credit_paid_value, 0) ELSE COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0) END)
                    - (CASE WHEN COALESCE(sales_transactions.is_credit_sale, 0) = 1 THEN COALESCE(cost_summary.cost_value, 0) * LEAST(1, COALESCE(sale_credit_allocations.credit_paid_value, 0) / NULLIF(COALESCE(line_summary.sales_value, sales_transactions.total_amount, 0), 0)) ELSE COALESCE(cost_summary.cost_value, 0) END) as estimated_profit,
                sales_transactions.status
            ')
            ->where('sales_transactions.company_id', $companyId)
            ->whereDate('sales_transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('sales_transactions.transaction_date', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('sales_transactions.currency_code', $filters['currency_codes']))
            ->orderByDesc('sales_transactions.transaction_date')
            ->limit($limit)
            ->get();
    }

    private function shiftSalesExportRows(string $companyId, array $filters, int $limit)
    {
        $sessions = DB::table('pos_user_shift_sessions')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_user_shift_sessions.pos_user_id')
            ->leftJoin('pos_cashups', function ($join) {
                $join->on('pos_cashups.company_id', '=', 'pos_user_shift_sessions.company_id')
                    ->on('pos_cashups.shift_session_id', '=', 'pos_user_shift_sessions.id');
            })
            ->where('pos_user_shift_sessions.company_id', $companyId)
            ->whereDate('pos_user_shift_sessions.opened_at', '>=', $filters['date_from'])
            ->whereDate('pos_user_shift_sessions.opened_at', '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'pos_user_shift_sessions.branch_id'))
            ->when($filters['cashier_id'], fn($q) => $q->where('pos_user_shift_sessions.pos_user_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn($q) => $q->where('pos_user_shift_sessions.id', $filters['shift_session_id']))
            ->selectRaw('
                pos_user_shift_sessions.id as shift_session_id,
                pos_user_shift_sessions.shift_name,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as cashier_name,
                pos_user_shift_sessions.opened_at,
                pos_user_shift_sessions.closed_at,
                pos_user_shift_sessions.status,
                pos_cashups.total_variance,
                pos_cashups.cashup_currency_code
            ')
            ->orderByDesc('pos_user_shift_sessions.opened_at')
            ->limit($limit)
            ->get();

        if ($sessions->isEmpty()) return collect();

        $salesByShift = DB::table('sales_transactions')
            ->selectRaw('shift_session_id, currency_code, COUNT(*) as transaction_count, SUM(total_amount) as sales_value')
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereIn('shift_session_id', $sessions->pluck('shift_session_id'))
            ->when($filters['currency_codes'], fn($q) => $q->whereIn('currency_code', $filters['currency_codes']))
            ->groupBy('shift_session_id', 'currency_code')
            ->get()
            ->groupBy('shift_session_id');

        $fallbackCurrency = $filters['currency_codes'][0] ?? $this->defaultReportingCurrencyCode($companyId, $this->costingContextBranchId($filters)) ?? 'USD';

        return $sessions->flatMap(function ($session) use ($salesByShift, $fallbackCurrency) {
            $salesRows = $salesByShift->get($session->shift_session_id, collect());
            if ($salesRows->isEmpty()) {
                $salesRows = collect([(object)[
                    'currency_code' => $fallbackCurrency,
                    'transaction_count' => 0,
                    'sales_value' => 0,
                ]]);
            }

            return $salesRows->map(fn($sales) => (object)[
                'shift_session_id' => $session->shift_session_id,
                'shift_name' => $session->shift_name ?: 'Shift',
                'cashier_name' => $session->cashier_name,
                'opened_at' => $this->salesReportDateTime($session->opened_at),
                'closed_at' => $this->salesReportDateTime($session->closed_at),
                'status' => $session->status,
                'cashup_variance' => strtoupper((string) ($session->cashup_currency_code ?? '')) === 'MULTI'
                    ? null
                    : $this->salesReportAmount($session->total_variance),
                'currency_code' => $sales->currency_code,
                'transaction_count' => (int)($sales->transaction_count ?? 0),
                'sales_value' => $this->salesReportAmount($sales->sales_value),
            ]);
        })->values();
    }

    private function dayEndExportRows(string $companyId, array $filters): array
    {
        $salesRows = $this->baseSalesQuery($companyId, $filters)
            ->where('sales_transactions.status', 'completed')
            ->selectRaw('DATE(sales_transactions.transaction_date) as business_date, sales_transactions.currency_code, sales_transactions.base_currency_code, COUNT(*) as transaction_count, SUM(sales_transactions.subtotal_amount) as subtotal, SUM(sales_transactions.discount_amount) as discount, SUM(sales_transactions.tax_amount) as tax, SUM(sales_transactions.total_amount) as total, SUM(sales_transactions.base_total_amount) as base_total')
            ->groupByRaw('DATE(sales_transactions.transaction_date), sales_transactions.currency_code, sales_transactions.base_currency_code')
            ->orderBy('business_date')
            ->get();

        $dateColumn = Schema::hasColumn('pos_cashups', 'cashup_closed_at') ? 'cashup_closed_at' : 'closed_at';
        $cashupRows = DB::table('pos_cashups')
            ->where('company_id', $companyId)
            ->whereDate($dateColumn, '>=', $filters['date_from'])
            ->whereDate($dateColumn, '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->get()
            ->map(fn($cashup) => $this->normalizeCashupForReports($cashup));

        return [
            'sales_records' => $salesRows,
            'cashup_records' => $this->cashupRowsByDayAndCurrency($cashupRows, $filters['currency_codes']),
        ];
    }

    private function buildReportPayload(string $title, string $slug, array $sections): array
    {
        return [
            'title' => $title,
            'slug' => $slug,
            'sections' => $sections,
            'sheets' => collect($sections)->map(fn($section) => [
                'title' => $section['title'],
                'headers' => $section['headers'],
                'rows' => $section['rows'],
            ])->values()->all(),
        ];
    }

    private function rowsFromCollection($collection, array $fields): array
    {
        return collect($collection)->map(function ($row) use ($fields) {
            return collect($fields)->map(function ($field) use ($row) {
                $value = is_array($row) ? ($row[$field] ?? null) : ($row->{$field} ?? null);
                if (str_contains($field, 'date') || str_contains($field, '_at')) {
                    return $this->salesReportDateTime($value);
                }
                if (is_numeric($value) && !str_contains($field, 'count') && !str_contains($field, 'receipt')) {
                    return $this->salesReportAmount($value);
                }
                return $value ?? '';
            })->values()->all();
        })->values()->all();
    }

    private function currencyPivotHeaders(array $identityHeaders, array $totalHeaders, array $currencyCodes, array $metricHeaders): array
    {
        $headers = array_merge($identityHeaders, $totalHeaders);
        foreach ($currencyCodes as $currencyCode) {
            foreach ($metricHeaders as $metricHeader) {
                $headers[] = strtoupper((string)$currencyCode) . ' ' . $metricHeader;
            }
        }
        return $headers;
    }

    private function currencyPivotRows(
        $rows,
        array $currencyCodes,
        array $keyFields,
        array $identityFields,
        array $totalFields,
        array $metricFields
    ): array {
        return collect($rows)
            ->groupBy(function ($row) use ($keyFields) {
                return json_encode(collect($keyFields)->map(fn($field) => data_get($row, $field))->all());
            })
            ->map(function ($group) use ($currencyCodes, $identityFields, $totalFields, $metricFields) {
                $first = $group->first();
                $output = collect($identityFields)->map(fn($field) => data_get($first, $field))->all();

                foreach ($totalFields as $field) {
                    $output[] = $group->sum(fn($row) => $this->numberValue(data_get($row, $field)));
                }

                foreach ($currencyCodes as $currencyCode) {
                    $currencyRow = $group->first(fn($row) => strtoupper((string)data_get($row, 'currency_code')) === strtoupper((string)$currencyCode));
                    foreach ($metricFields as $field) {
                        $output[] = $currencyRow ? data_get($currencyRow, $field) : null;
                    }
                }

                return $output;
            })
            ->values()
            ->all();
    }

    private function reportCurrencyCodes(string $companyId, array $filters, ?string $fallback = null): array
    {
        $codes = collect($filters['currency_codes'] ?? [])
            ->map(fn($value) => strtoupper(trim((string)$value)))
            ->filter();

        if ($codes->isEmpty()) {
            $codes = $this->baseSalesQuery($companyId, $filters)
                ->whereNotNull('sales_transactions.currency_code')
                ->distinct()
                ->orderBy('sales_transactions.currency_code')
                ->pluck('sales_transactions.currency_code')
                ->map(fn($value) => strtoupper(trim((string)$value)))
                ->filter();
        }

        if ($codes->isEmpty() && $fallback) {
            $codes->push(strtoupper(trim($fallback)));
        }

        return $codes->unique()->values()->all();
    }

    private function currencyMetricRows($rows, array $currencyCodes, array $metrics): array
    {
        $byCurrency = collect($rows)->keyBy(fn($row) => strtoupper((string)data_get($row, 'currency_code')));

        return collect($metrics)->map(function ($field, $label) use ($byCurrency, $currencyCodes) {
            $row = [$label];
            foreach ($currencyCodes as $currencyCode) {
                $row[] = data_get($byCurrency->get(strtoupper((string)$currencyCode)), $field, 0);
            }
            return $row;
        })->values()->all();
    }

    private function arraySummaryRows(array $summary): array
    {
        return collect($summary)->map(fn($value, $key) => [str_replace('_', ' ', ucwords((string)$key, '_')), is_numeric($value) ? $this->salesReportAmount($value) : (string)$value])->values()->all();
    }

    private function resolveSalesBusinessMode(string $companyId, ?string $branchId): array
    {
        return app(SalesCostingService::class)->mode($companyId, $branchId) + [
            'context_branch_id' => $branchId,
        ];
    }

    private function costingContextBranchId(array $filters): ?string
    {
        return $filters['costing_context_branch_id'] ?? $filters['branch_id'] ?? null;
    }

    private function applyBranchScope(mixed $query, array $filters, string $column = 'branch_id'): void
    {
        $branchIds = array_values(array_filter($filters['branch_ids'] ?? []));

        if ($branchIds !== []) {
            $query->whereIn($column, $branchIds);
            return;
        }

        if (!empty($filters['branch_id'])) {
            $query->where($column, $filters['branch_id']);
            return;
        }

        // A report query without a resolved branch scope must never fall back
        // to company-wide data in a mixed-mode tenant.
        $query->whereRaw('1 = 0');
    }

    private function canViewCashupFinancialAmounts(string $companyId): bool
    {
        $user = auth()->user();

        return $user && hasPermission(
            $user->role_id,
            CashupFinancialVisibilityService::PERMISSION,
            $companyId
        ) === true;
    }

    private function salesReportAmount($value): string
    {
        return number_format((float)($value ?? 0), 2, '.', '');
    }

    private function salesReportDateTime($value): string
    {
        if (!$value) return '';
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string)$value;
        }
    }

}
