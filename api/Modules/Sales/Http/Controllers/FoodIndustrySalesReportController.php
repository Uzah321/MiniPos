<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\Product\Models\TradingDay;
use Modules\Sales\Exports\FoodIndustrySalesSpreadsheetExport;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Sales\SalesCostingService;

class FoodIndustrySalesReportController extends Controller
{

    public function currencies(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);
        $baseCurrencyCode = $this->defaultReportingCurrencyCode($company->id, $filters['mode_context_branch_id']);

        $salesCurrencies = DB::table('sales_transactions')
            ->selectRaw('sales_transactions.currency_code as code')
            ->where('sales_transactions.company_id', $company->id)
            ->whereDate($this->salesDateColumn('sales_transactions'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('sales_transactions'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->whereNotNull('sales_transactions.currency_code');

        $lineCurrencies = DB::table('receipt_lines')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'receipt_lines.sales_transaction_id')
            ->selectRaw('receipt_lines.currency_code as code')
            ->where('sales_transactions.company_id', $company->id)
            ->whereDate($this->salesDateColumn('sales_transactions'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('sales_transactions'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transactions.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->whereNotNull('receipt_lines.currency_code');

        $paymentCurrencies = DB::table('sales_transaction_payments')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'sales_transaction_payments.sales_transaction_id')
            ->selectRaw('sales_transaction_payments.currency_code as code')
            ->where('sales_transaction_payments.company_id', $company->id)
            ->whereDate($this->salesDateColumn('sales_transactions'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('sales_transactions'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'sales_transaction_payments.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('sales_transactions.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('sales_transactions.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('sales_transactions.shift_session_id', $filters['shift_session_id']))
            ->whereNotNull('sales_transaction_payments.currency_code');

        $currencyMaster = null;

        if (Schema::hasTable('currencies')) {
            $currencyColumns = Schema::getColumnListing('currencies');
            $currencyCodeColumn = in_array('code', $currencyColumns, true)
                ? 'code'
                : (in_array('currency_code', $currencyColumns, true) ? 'currency_code' : null);

            if ($currencyCodeColumn) {
                $currencyMaster = DB::table('currencies')
                    ->selectRaw($currencyCodeColumn . ' as code');

                if (in_array('company_id', $currencyColumns, true)) {
                    $currencyMaster->where(function ($q) use ($company) {
                        $q->whereNull('company_id')->orWhere('company_id', $company->id);
                    });
                }

                if (in_array('is_active', $currencyColumns, true)) {
                    $currencyMaster->where(function ($q) {
                        $q->whereNull('is_active')->orWhere('is_active', true);
                    });
                }

                if (in_array('branch_id', $currencyColumns, true)) {
                    $currencyMaster->where(function ($q) use ($filters) {
                        $q->whereNull('branch_id')
                            ->orWhereIn('branch_id', $filters['branch_ids']);
                    });
                }

                if (in_array('deleted_at', $currencyColumns, true)) {
                    $currencyMaster->whereNull('deleted_at');
                }
            }
        }

        $union = $salesCurrencies->union($lineCurrencies)->union($paymentCurrencies);

        if ($currencyMaster) {
            $union->union($currencyMaster);
        }

        $rows = DB::query()
            ->fromSub($union, 'currency_rows')
            ->selectRaw('UPPER(code) as code')
            ->whereNotNull('code')
            ->where('code', '<>', '')
            ->groupBy(DB::raw('UPPER(code)'))
            ->orderBy('code')
            ->get()
            ->map(fn ($row) => [
                'code' => strtoupper((string) $row->code),
                'currency_code' => strtoupper((string) $row->code),
                'is_base_currency' => strtoupper((string) $row->code) === strtoupper((string) $baseCurrencyCode),
            ])
            ->values();

        return response()->json([
            'message' => 'Food industry sales currencies fetched.',
            'data' => [
                'currencies' => $rows,
                'base_currency_code' => $baseCurrencyCode,
            ],
        ]);
    }


    public function businessDates(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);

        $openDay = TradingDay::query()
            ->where('company_id', $company->id)
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->where('status', 'open')
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->first();

        $currentBusinessDate = $openDay
            ? Carbon::parse($openDay->getRawOriginal('trading_date') ?: $openDay->trading_date)->toDateString()
            : now()->toDateString();

        $tradingDays = TradingDay::query()
            ->where('company_id', $company->id)
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'branch_id'))
            ->orderByDesc('trading_date')
            ->limit(90)
            ->get()
            ->map(fn ($day) => [
                'id' => $day->id,
                'business_date' => Carbon::parse($day->getRawOriginal('trading_date') ?: $day->trading_date)->toDateString(),
                'trading_date' => Carbon::parse($day->getRawOriginal('trading_date') ?: $day->trading_date)->toDateString(),
                'status' => $day->status,
                'is_current_open' => $openDay && (string) $openDay->id === (string) $day->id,
                'receipt_count' => 0,
            ]);

        $receiptDateSource = DB::table('sales_transactions as st')
            ->selectRaw($this->salesDateExpression('st') . ' as business_date')
            ->where('st.company_id', $company->id)
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->where('st.status', 'completed');

        $receiptCounts = DB::query()
            ->fromSub($receiptDateSource, 'sale_dates')
            ->selectRaw('business_date, COUNT(*) as receipt_count')
            ->groupBy('business_date')
            ->orderByDesc('business_date')
            ->limit(90)
            ->get()
            ->keyBy('business_date');

        $rows = $tradingDays
            ->map(function ($row) use ($receiptCounts) {
                $row['receipt_count'] = (int) ($receiptCounts->get($row['business_date'])->receipt_count ?? 0);
                return $row;
            })
            ->values();

        if ($rows->isEmpty()) {
            $rows = collect([[
                'id' => null,
                'business_date' => $currentBusinessDate,
                'trading_date' => $currentBusinessDate,
                'status' => 'open',
                'is_current_open' => true,
                'receipt_count' => (int) ($receiptCounts->get($currentBusinessDate)->receipt_count ?? 0),
            ]]);
        }

        return response()->json([
            'message' => 'Food industry business dates fetched.',
            'data' => [
                'current_business_date' => $currentBusinessDate,
                'current_open_business_date' => $currentBusinessDate,
                'business_dates' => $rows,
                'dates' => $rows,
            ],
        ]);
    }

    public function turnover(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);
        return response()->json(['message' => 'Food industry sales turnover report fetched.', 'data' => $this->buildReport($company->id, $filters)]);
    }

    public function daily(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);
        $date = $request->input('business_date') ?: $request->input('date') ?: $filters['date_from'];
        $filters['date_from'] = $date;
        $filters['date_to'] = $date;
        return response()->json(['message' => 'Food industry daily sales report fetched.', 'data' => $this->buildReport($company->id, $filters)]);
    }

    public function categorySales(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);
        return response()->json(['message' => 'Food category sales report fetched.', 'data' => [
            'filters' => $filters,
            'category_sales' => $this->categorySalesRows($company->id, $filters),
            'category_profit' => $this->categoryProfitRows($company->id, $filters),
        ]]);
    }

    public function menuProfitability(Request $request): JsonResponse
    {
        $company = app('company');
        $filters = $this->filters($request);
        return response()->json(['message' => 'Menu item profitability report fetched.', 'data' => [
            'filters' => $filters,
            'menu_profitability' => $this->menuProfitabilityRows($company->id, $filters),
            'ingredient_consumption' => $this->ingredientConsumptionRows($company->id, $filters),
        ]]);
    }

    public function downloadExcel(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $report = $this->buildReport($company->id, $filters);
        $spreadsheet = (new FoodIndustrySalesSpreadsheetExport($report))->spreadsheet();
        $writer = new Xlsx($spreadsheet);
        $filename = 'food-industry-sales-report-' . $filters['date_from'] . '-to-' . $filters['date_to'] . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function downloadPdf(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $report = $this->buildReport($company->id, $filters);
        $html = $this->pdfHtml($report);
        $filename = 'food-industry-sales-report-' . $filters['date_from'] . '-to-' . $filters['date_to'] . '.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }


    public function downloadDailyExcel(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $date = $request->input('business_date') ?: $request->input('date') ?: $filters['date_from'];
        $filters['date_from'] = $date;
        $filters['date_to'] = $date;

        $report = $this->buildReport($company->id, $filters);
        $report['report_title'] = 'Daily Sales Report';

        $spreadsheet = (new FoodIndustrySalesSpreadsheetExport($report))->spreadsheet();
        $writer = new Xlsx($spreadsheet);
        $filename = 'daily-sales-report-' . $date . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function downloadDailyPdf(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $date = $request->input('business_date') ?: $request->input('date') ?: $filters['date_from'];
        $filters['date_from'] = $date;
        $filters['date_to'] = $date;

        $report = $this->buildReport($company->id, $filters);
        $report['report_title'] = 'Daily Sales Report';
        $html = $this->pdfHtml($report);
        $filename = 'daily-sales-report-' . $date . '.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    private function filters(Request $request): array
    {
        $branchId = trim((string) $request->input('branch_id', '')) ?: null;
        $branchIds = $request->input('branch_ids', []);
        if (is_string($branchIds)) {
            $branchIds = explode(',', $branchIds);
        }
        $branchIds = collect(is_array($branchIds) ? $branchIds : [])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($branchId && $branchIds === []) {
            $branchIds = [$branchId];
        }
        $requestedCurrency = strtoupper(trim((string) $request->input('currency_code', '')));
        $requestedCurrencies = $request->input('currency_codes', []);

        if (is_string($requestedCurrencies)) {
            $requestedCurrencies = explode(',', $requestedCurrencies);
        }

        $requestedCurrencies = collect(is_array($requestedCurrencies) ? $requestedCurrencies : [])
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($requestedCurrency && empty($requestedCurrencies)) {
            $requestedCurrencies = [$requestedCurrency];
        }

        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) app('company')->id,
            $branchIds
        );

        return [
            'date_from' => $request->input('date_from', now()->toDateString()),
            'date_to' => $request->input('date_to', now()->toDateString()),
            'branch_id' => $branchId,
            'branch_ids' => $scope['branch_ids'],
            'mode_context_branch_id' => $scope['context_branch_id'],
            'business_mode' => $scope['business_mode'],
            'till_id' => $request->input('till_id'),
            'cashier_id' => $request->input('cashier_id'),
            'shift_session_id' => $request->input('shift_session_id'),
            'currency_code' => count($requestedCurrencies) === 1 ? $requestedCurrencies[0] : null,
            'currency_codes' => $requestedCurrencies,
            'category_id' => $request->input('category_id'),
            'menu_product_id' => $request->input('menu_product_id'),
            'status' => $request->input('status', 'completed'),
        ];
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

    private function applyBranchScope(mixed $query, array $filters, string $column): void
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

        $query->whereRaw('1 = 0');
    }

    private function costInSaleCurrencyExpression(
        string $amountExpression,
        string $costCurrencyExpression,
        string $saleAlias = 'st'
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
        string $saleAlias = 'st'
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


    private function salesDateColumn(string $alias = 'st')
    {
        return DB::raw($this->salesDateExpression($alias));
    }

    private function salesDateExpression(string $alias = 'st'): string
    {
        /*
         |--------------------------------------------------------------------------
         | Hospitality / Food Industry business date resolver
         |--------------------------------------------------------------------------
         | Reports must use the current open trading day, not the system date.
         | Some already-created sales may only have the trading date in metadata,
         | and older rows may only have transaction_date. This expression reads in
         | this order:
         | 1. sales_transactions.business_date
         | 2. metadata.business_date
         | 3. trading_days.trading_date through trading_day_id
         | 4. DATE(transaction_date) as legacy fallback
         */
        $parts = [];

        if (Schema::hasColumn('sales_transactions', 'business_date')) {
            $parts[] = 'DATE(NULLIF(CAST(' . $alias . '.business_date AS CHAR), \'\'))';
        }

        if (Schema::hasColumn('sales_transactions', 'metadata')) {
            $parts[] = "DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(" . $alias . ".metadata) THEN " . $alias . ".metadata ELSE JSON_OBJECT() END, '$.business_date')), ''))";
        }

        if (Schema::hasColumn('sales_transactions', 'trading_day_id') && Schema::hasTable('trading_days')) {
            $parts[] = '(SELECT DATE(td.trading_date) FROM trading_days td WHERE td.id = ' . $alias . '.trading_day_id LIMIT 1)';
        }

        $parts[] = 'DATE(' . $alias . '.transaction_date)';

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function buildReport(string $companyId, array $filters): array
    {
        $menuProfit = $this->menuProfitabilityRows($companyId, $filters);
        $currencySummary = $this->currencySummaryRows($companyId, $filters, $menuProfit);
        $categoryProfit = $this->categoryProfitRows($companyId, $filters);
        $ingredientConsumption = $this->ingredientConsumptionRows($companyId, $filters);
        $dailySales = $this->dailySalesRows($companyId, $filters, $menuProfit);
        $hourlySales = $this->hourlySalesRows($companyId, $filters);
        $payments = $this->paymentRows($companyId, $filters);
        $cashiers = $this->cashierRows($companyId, $filters);
        $receiptDetail = $this->receiptRows($companyId, $filters);

        return [
            'filters' => $filters,
            'generated_at' => now()->toDateTimeString(),
            'summary' => $this->summary($menuProfit, $receiptDetail, $filters),
            'currency_summary' => $currencySummary,
            'daily_sales' => $dailySales,
            'hourly_sales' => $hourlySales,
            'category_sales' => $this->categorySalesRows($companyId, $filters),
            'category_profit' => $categoryProfit,
            'menu_profitability' => $menuProfit,
            'ingredient_consumption' => $ingredientConsumption,
            'payments' => $payments,
            'cashiers' => $cashiers,
            'receipt_detail' => $receiptDetail,
            'insights' => $this->insights($menuProfit, $categoryProfit, $ingredientConsumption),
        ];
    }

    private function baseSales(Request|string $companyId, array $filters = [])
    {
        if ($companyId instanceof Request) { $companyId = app('company')->id; }
        return DB::table('sales_transactions as st')
            ->where('st.company_id', $companyId)
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->where('st.status', $filters['status'] ?: 'completed')
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('st.currency_code', $filters['currency_codes']));
    }

    private function menuProfitabilityRows(string $companyId, array $filters)
    {
        $ingredientCostSub = $this->ingredientCostBySaleMenuSubquery($companyId, $filters);
        $recipeEstimateSub = $this->recipeEstimatedCostSubquery($companyId, $filters);
        $estimatedUnitCost = 'COALESCE(recipe_estimates.recipe_unit_cost, 0)';
        $estimatedCostCurrency = 'COALESCE(recipe_estimates.cost_currency_code, st.base_currency_code)';
        $estimatedSaleUnitCost = $this->costInSaleCurrencyExpression($estimatedUnitCost, $estimatedCostCurrency, 'st');
        $estimatedBaseUnitCost = $this->costInBaseCurrencyExpression($estimatedUnitCost, $estimatedCostCurrency, 'st');

        return DB::table('receipt_lines as rl')
            ->join('sales_transactions as st', 'st.id', '=', 'rl.sales_transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoinSub($ingredientCostSub, 'ingredient_costs', function ($join) {
                $join->on('ingredient_costs.sales_transaction_id', '=', 'rl.sales_transaction_id')
                    ->on('ingredient_costs.receipt_line_id', '=', 'rl.id');
            })
            ->leftJoinSub($recipeEstimateSub, 'recipe_estimates', function ($join) {
                $join->on('recipe_estimates.menu_product_id', '=', 'rl.product_id')
                    ->on('recipe_estimates.branch_id', '=', 'st.branch_id');
            })
            ->selectRaw("
                rl.product_id as menu_product_id,
                COALESCE(p.name, rl.line_name, 'Menu Item') as menu_item_name,
                COALESCE(p.code, rl.product_code, '-') as menu_item_code,
                COALESCE(pc.name, 'Uncategorised') as category_name,
                pc.id as category_id,
                rl.currency_code,
                rl.base_currency_code,
                COUNT(DISTINCT st.id) as receipt_count,
                COUNT(rl.id) as line_count,
                SUM(rl.line_quantity) as quantity_sold,
                CASE WHEN SUM(rl.line_quantity) > 0 THEN SUM(rl.line_total) / SUM(rl.line_quantity) ELSE 0 END as average_selling_price,
                SUM(rl.line_subtotal) as gross_sales,
                SUM(rl.line_discount) as discounts,
                SUM(rl.line_tax) as tax,
                SUM(rl.line_total) as net_sales,
                SUM(rl.base_line_total) as base_net_sales,
                SUM(COALESCE(ingredient_costs.ingredient_cost, ({$estimatedSaleUnitCost}) * rl.line_quantity, 0)) as ingredient_cost,
                SUM(COALESCE(ingredient_costs.base_ingredient_cost, ({$estimatedBaseUnitCost}) * rl.line_quantity, 0)) as base_ingredient_cost,
                MAX(rl.currency_code) as cost_currency_code
            ")
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('rl.currency_code', $filters['currency_codes']))
            ->when($filters['category_id'], fn ($q) => $q->where('p.category_id', $filters['category_id']))
            ->when($filters['menu_product_id'], fn ($q) => $q->where('rl.product_id', $filters['menu_product_id']))
            ->groupBy('rl.product_id', DB::raw('COALESCE(p.name, rl.line_name, "Menu Item")'), DB::raw('COALESCE(p.code, rl.product_code, "-")'), DB::raw('COALESCE(pc.name, "Uncategorised")'), 'pc.id', 'rl.currency_code', 'rl.base_currency_code')
            ->orderByDesc('base_net_sales')
            ->get()
            ->map(function ($row) {
                $row->quantity_sold = round((float) $row->quantity_sold, 4);
                $row->average_selling_price = round((float) $row->average_selling_price, 4);
                $row->gross_sales = round((float) $row->gross_sales, 4);
                $row->discounts = round((float) $row->discounts, 4);
                $row->tax = round((float) $row->tax, 4);
                $row->net_sales = round((float) $row->net_sales, 4);
                $row->base_net_sales = round((float) $row->base_net_sales, 4);
                $row->ingredient_cost = round((float) $row->ingredient_cost, 4);
                $row->base_ingredient_cost = round((float) $row->base_ingredient_cost, 4);
                $row->cost_of_sales = $row->ingredient_cost;
                $row->base_cost_of_sales = $row->base_ingredient_cost;
                $row->gross_profit = round($row->net_sales - $row->ingredient_cost, 4);
                $row->base_gross_profit = round($row->base_net_sales - $row->base_ingredient_cost, 4);
                $row->food_cost_percent = $row->net_sales > 0 ? round(($row->ingredient_cost / $row->net_sales) * 100, 2) : 0;
                $row->gross_margin_percent = $row->net_sales > 0 ? round(($row->gross_profit / $row->net_sales) * 100, 2) : 0;
                $row->profit_per_item = $row->quantity_sold > 0 ? round($row->gross_profit / $row->quantity_sold, 4) : 0;
                return $row;
            })
            ->values();
    }

    private function ingredientCostBySaleMenuSubquery(string $companyId, array $filters)
    {
        $sourceCost = 'recorded_recipe_costs.recipe_cost';
        $sourceCurrency = 'COALESCE(recorded_recipe_costs.cost_currency_code, recorded_recipe_sales.currency_code)';
        $saleCost = $this->costInSaleCurrencyExpression($sourceCost, $sourceCurrency, 'recorded_recipe_sales');
        $baseCost = $this->costInBaseCurrencyExpression($sourceCost, $sourceCurrency, 'recorded_recipe_sales');

        return DB::query()
            ->fromSub(app(SalesCostingService::class)->recipeMovementCostsByReceiptLine($companyId), 'recorded_recipe_costs')
            ->join('sales_transactions as recorded_recipe_sales', 'recorded_recipe_sales.id', '=', 'recorded_recipe_costs.sales_transaction_id')
            ->selectRaw('recorded_recipe_costs.sales_transaction_id, recorded_recipe_costs.receipt_line_id')
            ->selectRaw($saleCost . ' as ingredient_cost')
            ->selectRaw($baseCost . ' as base_ingredient_cost')
            ->selectRaw('recorded_recipe_sales.currency_code as cost_currency_code')
            ->selectRaw($sourceCost . ' as source_ingredient_cost')
            ->selectRaw($sourceCurrency . ' as source_cost_currency_code');
    }

    private function recipeEstimatedCostSubquery(string $companyId, array $filters)
    {
        return DB::query()
            ->fromSub(app(SalesCostingService::class)->activeRecipeUnitCosts($companyId, $filters['branch_id']), 'active_recipe_costs_source')
            ->selectRaw('active_recipe_costs_source.branch_id, active_recipe_costs_source.menu_product_id, active_recipe_costs_source.recipe_unit_cost')
            ->selectRaw('active_recipe_costs_source.recipe_unit_cost as base_recipe_unit_cost')
            ->selectRaw('active_recipe_costs_source.cost_currency_code');
    }

    private function ingredientConsumptionRows(string $companyId, array $filters)
    {
        /*
         * Strict MySQL / ONLY_FULL_GROUP_BY fix:
         * First resolve the hospitality business date inside a derived row source,
         * then aggregate the derived table. This avoids MySQL rejecting
         * st.business_date inside grouped expressions.
         */
        $sourceLineCost = 'COALESCE(NULLIF(sm.total_cost,0), sm.quantity_out * sm.unit_cost, 0)';
        $sourceCostCurrency = 'COALESCE(sm.currency_code, sm.currency, st.base_currency_code)';
        $saleLineCost = $this->costInSaleCurrencyExpression($sourceLineCost, $sourceCostCurrency, 'st');
        $baseLineCost = $this->costInBaseCurrencyExpression($sourceLineCost, $sourceCostCurrency, 'st');
        $saleDateExpression = $this->salesDateExpression('st');
        $receiptLineId = 'JSON_UNQUOTE(JSON_EXTRACT(sm.metadata, "$.receipt_line_id"))';

        $source = DB::table('stock_movements as sm')
            ->join('sales_transactions as st', 'st.id', '=', 'sm.reference_id')
            ->join('receipt_lines as movement_receipt_line', function ($join) use ($receiptLineId) {
                $join->on('movement_receipt_line.sales_transaction_id', '=', 'st.id')
                    ->whereRaw("movement_receipt_line.id = {$receiptLineId}");
            })
            ->leftJoin('products as ingredient', 'ingredient.id', '=', 'sm.product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'ingredient.base_uom_id')
            ->selectRaw("
                {$saleDateExpression} as sale_date,
                st.id as sale_id,
                sm.product_id as ingredient_product_id,
                COALESCE(ingredient.name, 'Ingredient') as ingredient_name,
                COALESCE(ingredient.code, ingredient.sku, '-') as ingredient_code,
                COALESCE(u.code, u.name, 'EACH') as uom_code,
                st.currency_code as currency_code,
                {$sourceCostCurrency} as source_cost_currency_code,
                COALESCE(sm.quantity_out, 0) as quantity_out,
                {$saleLineCost} as line_cost,
                {$baseLineCost} as base_line_cost
            ")
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->where(function ($query) {
                $query->whereIn('sm.reference_type', ['sale_recipe', 'sale_modifier_recipe'])
                    ->orWhere(function ($legacyBatchQuery) {
                        $legacyBatchQuery->where('sm.reference_type', 'sale')
                            ->whereNotNull('sm.batch_id')
                            ->whereColumn('sm.product_id', '<>', 'movement_receipt_line.product_id');
                    });
            })
            ->whereRaw('COALESCE(sm.quantity_out,0) > 0')
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('st.currency_code', $filters['currency_codes']));

        return DB::query()
            ->fromSub($source, 'ingredient_source')
            ->selectRaw('
                sale_date,
                ingredient_product_id,
                ingredient_name,
                ingredient_code,
                uom_code,
                currency_code,
                COUNT(DISTINCT sale_id) as receipt_count,
                SUM(quantity_out) as consumed_quantity,
                CASE WHEN SUM(quantity_out) > 0 THEN SUM(line_cost) / SUM(quantity_out) ELSE 0 END as average_unit_cost,
                SUM(line_cost) as consumed_cost,
                SUM(base_line_cost) as base_consumed_cost
            ')
            ->groupBy('sale_date', 'ingredient_product_id', 'ingredient_name', 'ingredient_code', 'uom_code', 'currency_code')
            ->orderByDesc('consumed_cost')
            ->get()
            ->map(function ($row) {
                $row->consumed_quantity = round((float) $row->consumed_quantity, 4);
                $row->average_unit_cost = round((float) $row->average_unit_cost, 4);
                $row->consumed_cost = round((float) $row->consumed_cost, 4);
                $row->base_consumed_cost = round((float) $row->base_consumed_cost, 4);
                return $row;
            })
            ->values();
    }

    private function categorySalesRows(string $companyId, array $filters)
    {
        return DB::table('receipt_lines as rl')
            ->join('sales_transactions as st', 'st.id', '=', 'rl.sales_transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->selectRaw('
                pc.id as category_id,
                COALESCE(pc.name, "Uncategorised") as category_name,
                rl.currency_code,
                rl.base_currency_code,
                COUNT(DISTINCT st.id) as receipt_count,
                COUNT(rl.id) as line_count,
                SUM(rl.line_quantity) as quantity_sold,
                SUM(rl.line_subtotal) as gross_sales,
                SUM(rl.line_discount) as discounts,
                SUM(rl.line_tax) as tax,
                SUM(rl.line_total) as net_sales,
                SUM(rl.base_line_total) as base_net_sales
            ')
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('rl.currency_code', $filters['currency_codes']))
            ->when($filters['category_id'], fn ($q) => $q->where('p.category_id', $filters['category_id']))
            ->when($filters['menu_product_id'], fn ($q) => $q->where('rl.product_id', $filters['menu_product_id']))
            ->groupBy('pc.id', DB::raw('COALESCE(pc.name, "Uncategorised")'), 'rl.currency_code', 'rl.base_currency_code')
            ->orderByDesc('base_net_sales')
            ->get();
    }

    private function categoryProfitRows(string $companyId, array $filters)
    {
        return collect($this->menuProfitabilityRows($companyId, $filters))
            ->groupBy(fn ($row) => ($row->category_id ?: 'none') . '|' . $row->category_name . '|' . $row->currency_code)
            ->map(function ($rows) {
                $first = $rows->first();
                $sales = (float) $rows->sum('net_sales');
                $cost = (float) $rows->sum('ingredient_cost');
                $profit = $sales - $cost;
                $baseSales = (float) $rows->sum('base_net_sales');
                $baseCost = (float) $rows->sum('base_ingredient_cost');
                return [
                    'category_id' => $first->category_id,
                    'category_name' => $first->category_name,
                    'currency_code' => $first->currency_code,
                    'base_currency_code' => $first->base_currency_code,
                    'menu_count' => $rows->count(),
                    'quantity_sold' => round((float) $rows->sum('quantity_sold'), 4),
                    'net_sales' => round($sales, 4),
                    'ingredient_cost' => round($cost, 4),
                    'cost_of_sales' => round($cost, 4),
                    'gross_profit' => round($profit, 4),
                    'base_net_sales' => round($baseSales, 4),
                    'base_ingredient_cost' => round($baseCost, 4),
                    'base_cost_of_sales' => round($baseCost, 4),
                    'base_gross_profit' => round($baseSales - $baseCost, 4),
                    'food_cost_percent' => $sales > 0 ? round(($cost / $sales) * 100, 2) : 0,
                    'gross_margin_percent' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('gross_profit')
            ->values();
    }

    private function dailySalesRows(string $companyId, array $filters, $menuProfit)
    {
        return collect($menuProfit)
            ->groupBy(fn ($row) => $row->currency_code)
            ->map(function ($rows, $currency) use ($filters) {
                $sales = (float) $rows->sum('net_sales');
                $cost = (float) $rows->sum('ingredient_cost');
                $baseSales = (float) $rows->sum('base_net_sales');
                $baseCost = (float) $rows->sum('base_ingredient_cost');
                return [
                    'sale_date' => $filters['date_from'] === $filters['date_to'] ? $filters['date_from'] : $filters['date_from'] . ' to ' . $filters['date_to'],
                    'currency_code' => $currency,
                    'menu_count' => $rows->count(),
                    'quantity_sold' => round((float) $rows->sum('quantity_sold'), 4),
                    'net_sales' => round($sales, 4),
                    'ingredient_cost' => round($cost, 4),
                    'cost_of_sales' => round($cost, 4),
                    'gross_profit' => round($sales - $cost, 4),
                    'base_currency_code' => $rows->pluck('base_currency_code')->filter()->first(),
                    'base_net_sales' => round($baseSales, 4),
                    'base_ingredient_cost' => round($baseCost, 4),
                    'base_cost_of_sales' => round($baseCost, 4),
                    'base_gross_profit' => round($baseSales - $baseCost, 4),
                    'food_cost_percent' => $sales > 0 ? round(($cost / $sales) * 100, 2) : 0,
                ];
            })->values();
    }

    private function hourlySalesRows(string $companyId, array $filters)
    {
        return DB::table('sales_transactions as st')
            ->selectRaw('HOUR(st.transaction_date) as hour, st.currency_code, COUNT(*) as receipt_count, SUM(st.total_amount) as net_sales, SUM(st.base_total_amount) as base_net_sales')
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('st.currency_code', $filters['currency_codes']))
            ->groupByRaw('HOUR(st.transaction_date), st.currency_code')
            ->orderBy('hour')
            ->get();
    }

    private function paymentRows(string $companyId, array $filters)
    {
        return DB::table('sales_transaction_payments as p')
            ->join('sales_transactions as st', 'st.id', '=', 'p.sales_transaction_id')
            ->selectRaw('p.payment_name, p.money_code, p.currency_code, p.base_currency_code, COUNT(*) as payment_count, SUM(p.amount) as amount, SUM(p.amount_tendered) as amount_tendered, SUM(p.base_amount) as base_amount, SUM(p.change_amount) as change_amount')
            ->where('p.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'p.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('p.currency_code', $filters['currency_codes']))
            ->groupBy('p.payment_name', 'p.money_code', 'p.currency_code', 'p.base_currency_code')
            ->orderBy('p.payment_name')
            ->get();
    }

    private function cashierRows(string $companyId, array $filters)
    {
        return DB::table('sales_transactions as st')
            ->leftJoin('pos_users as u', 'u.id', '=', 'st.cashier_id')
            ->selectRaw('st.cashier_id, COALESCE(u.name, u.username, "Unknown") as cashier_name, st.currency_code, COUNT(*) as receipt_count, SUM(st.total_amount) as net_sales, SUM(st.base_total_amount) as base_net_sales')
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('st.currency_code', $filters['currency_codes']))
            ->groupBy('st.cashier_id', 'u.name', 'u.username', 'st.currency_code')
            ->orderByDesc('base_net_sales')
            ->get();
    }

    private function receiptRows(string $companyId, array $filters)
    {
        return DB::table('sales_transactions as st')
            ->leftJoin('pos_users as u', 'u.id', '=', 'st.cashier_id')
            ->leftJoin('pos_tills as t', 't.id', '=', 'st.till_id')
            ->selectRaw('st.id, st.invoice_number, st.transaction_date, st.currency_code, st.total_amount, st.base_total_amount, st.payment_method, st.customer_name, (SELECT COUNT(*) FROM receipt_lines as receipt_count_lines WHERE receipt_count_lines.sales_transaction_id = st.id) as line_count, COALESCE(u.name, u.username, "Unknown") as cashier_name, COALESCE(t.till_name, t.till_code, "Till") as till_name')
            ->where('st.company_id', $companyId)
            ->where('st.status', $filters['status'] ?: 'completed')
            ->whereDate($this->salesDateColumn('st'), '>=', $filters['date_from'])
            ->whereDate($this->salesDateColumn('st'), '<=', $filters['date_to'])
            ->tap(fn ($query) => $this->applyBranchScope($query, $filters, 'st.branch_id'))
            ->when($filters['till_id'], fn ($q) => $q->where('st.till_id', $filters['till_id']))
            ->when($filters['cashier_id'], fn ($q) => $q->where('st.cashier_id', $filters['cashier_id']))
            ->when($filters['shift_session_id'], fn ($q) => $q->where('st.shift_session_id', $filters['shift_session_id']))
            ->when(!empty($filters['currency_codes']), fn ($q) => $q->whereIn('st.currency_code', $filters['currency_codes']))
            ->orderByDesc('st.transaction_date')
            ->limit(500)
            ->get();
    }

    private function currencySummaryRows(string $companyId, array $filters, $menuProfit)
    {
        return collect($menuProfit)->groupBy('currency_code')->map(function ($rows, $currency) {
            $sales = (float) $rows->sum('net_sales');
            $cost = (float) $rows->sum('ingredient_cost');
            $profit = $sales - $cost;
            $baseSales = (float) $rows->sum('base_net_sales');
            $baseCost = (float) $rows->sum('base_ingredient_cost');
            return [
                'currency_code' => $currency,
                'base_currency_code' => $rows->pluck('base_currency_code')->filter()->first(),
                'menu_count' => $rows->count(),
                'quantity_sold' => round((float) $rows->sum('quantity_sold'), 4),
                'net_sales' => round($sales, 4),
                'ingredient_cost' => round($cost, 4),
                'cost_of_sales' => round($cost, 4),
                'gross_profit' => round($profit, 4),
                'base_net_sales' => round($baseSales, 4),
                'base_ingredient_cost' => round($baseCost, 4),
                'base_cost_of_sales' => round($baseCost, 4),
                'base_gross_profit' => round($baseSales - $baseCost, 4),
                'food_cost_percent' => $sales > 0 ? round(($cost / $sales) * 100, 2) : 0,
                'gross_margin_percent' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0,
            ];
        })->values();
    }

    private function summary($menuProfit, $receiptDetail, array $filters): array
    {
        $rows = collect($menuProfit);
        $useBaseCurrency = count($filters['currency_codes'] ?? []) !== 1;
        $sales = (float) $rows->sum($useBaseCurrency ? 'base_net_sales' : 'net_sales');
        $cost = (float) $rows->sum($useBaseCurrency ? 'base_ingredient_cost' : 'ingredient_cost');
        $profit = $sales - $cost;
        $currency = $useBaseCurrency
            ? $rows->pluck('base_currency_code')->filter()->first()
            : $rows->pluck('currency_code')->filter()->first();
        return [
            'currency_code' => $currency,
            'uses_base_currency' => $useBaseCurrency,
            'receipt_count' => collect($receiptDetail)->count(),
            'menu_count' => $rows->count(),
            'quantity_sold' => round((float) $rows->sum('quantity_sold'), 4),
            'net_sales' => round($sales, 4),
            'ingredient_cost' => round($cost, 4),
            'cost_of_sales' => round($cost, 4),
            'gross_profit' => round($profit, 4),
            'food_cost_percent' => $sales > 0 ? round(($cost / $sales) * 100, 2) : 0,
            'gross_margin_percent' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0,
            'average_receipt' => collect($receiptDetail)->count() > 0 ? round($sales / collect($receiptDetail)->count(), 4) : 0,
        ];
    }

    private function insights($menuProfit, $categoryProfit, $ingredientConsumption): array
    {
        $menuRows = collect($menuProfit);
        $categoryRows = collect($categoryProfit);
        $ingredientRows = collect($ingredientConsumption);
        return [
            'best_profit_menu' => $menuRows->sortByDesc('gross_profit')->first(),
            'highest_food_cost_menu' => $menuRows->sortByDesc('food_cost_percent')->first(),
            'best_category' => $categoryRows->sortByDesc('gross_profit')->first(),
            'highest_cost_ingredient' => $ingredientRows->sortByDesc('consumed_cost')->first(),
        ];
    }

    private function pdfRows($value, int $limit = null): array
    {
        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->values()->all();
        }

        if ($value instanceof \Illuminate\Contracts\Pagination\Paginator || $value instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $value = $value->items();
        }

        if (!is_array($value)) {
            $value = [];
        }

        return $limit === null ? array_values($value) : array_slice(array_values($value), 0, $limit);
    }

    private function pdfValue($row, string $key, $default = null)
    {
        if (is_array($row)) {
            return $row[$key] ?? $default;
        }

        if (is_object($row)) {
            return $row->{$key} ?? $default;
        }

        return $default;
    }

    private function pdfHtml(array $report): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn ($v, $c = '') => $e(($c ? $c . ' ' : '') . number_format((float) $v, 2));
        $qty = fn ($v) => $e(number_format((float) $v, 4));
        $filters = $report['filters'];

        $currencySummary = $this->pdfRows($report['currency_summary'] ?? []);
        $menuProfitability = $this->pdfRows($report['menu_profitability'] ?? [], 40);
        $categoryProfit = $this->pdfRows($report['category_profit'] ?? [], 40);
        $ingredientConsumption = $this->pdfRows($report['ingredient_consumption'] ?? [], 45);
        $paymentRows = $this->pdfRows($report['payments'] ?? [], 35);
        $receiptDetail = $this->pdfRows($report['receipt_detail'] ?? [], 60);

        $cards = '';
        foreach ($currencySummary as $c) {
            $currency = $this->pdfValue($c, 'currency_code', '');
            $cards .= '<div class="card"><b>' . $e($currency) . '</b><span>Sales</span><strong>' . $money($this->pdfValue($c, 'net_sales', 0), $currency) . '</strong><span>Cost of Sales</span><strong>' . $money($this->pdfValue($c, 'cost_of_sales', 0), $currency) . '</strong><span>Gross Profit</span><strong>' . $money($this->pdfValue($c, 'gross_profit', 0), $currency) . '</strong><em>Food Cost ' . $e($this->pdfValue($c, 'food_cost_percent', 0)) . '%</em></div>';
        }

        if ($cards === '') {
            $cards = '<div class="card"><b>No Currency Data</b><span>Sales</span><strong>0.00</strong><span>Cost of Sales</span><strong>0.00</strong><span>Gross Profit</span><strong>0.00</strong></div>';
        }

        $currencyCodes = collect($filters['currency_codes'] ?? [])
            ->merge(collect($currencySummary)->map(fn ($row) => $this->pdfValue($row, 'currency_code', '')))
            ->map(fn ($currencyCode) => strtoupper(trim((string) $currencyCode)))
            ->filter()->unique()->values()->all();
        $groupRows = function (array $rows, array $fields) {
            return collect($rows)->groupBy(fn ($row) => json_encode(collect($fields)->map(fn ($field) => $this->pdfValue($row, $field, ''))->all()))->values();
        };
        $currencyHeader = function (array $metrics) use ($currencyCodes, $e) {
            return collect($currencyCodes)->flatMap(fn ($currencyCode) => collect($metrics)->map(fn ($metric) => '<th>' . $e($currencyCode . ' ' . $metric) . '</th>'))->implode('');
        };

        $menuRows = '';
        foreach ($groupRows($menuProfitability, ['menu_item_id', 'menu_item_name'])->take(40) as $group) {
            $first = $group->first();
            $byCurrency = $group->keyBy(fn ($row) => strtoupper((string) $this->pdfValue($row, 'currency_code', '')));
            $menuRows .= '<tr><td>' . $e($this->pdfValue($first, 'menu_item_name', '')) . '<br><small>' . $e($this->pdfValue($first, 'menu_item_code', '')) . '</small></td><td>' . $e($this->pdfValue($first, 'category_name', '')) . '</td><td class="num">' . $qty($group->sum(fn ($row) => (float) $this->pdfValue($row, 'quantity_sold', 0))) . '</td>';
            foreach ($currencyCodes as $currencyCode) {
                $row = $byCurrency->get($currencyCode);
                $menuRows .= '<td class="num">' . $money($this->pdfValue($row, 'net_sales', 0), $currencyCode) . '</td><td class="num">' . $money($this->pdfValue($row, 'cost_of_sales', 0), $currencyCode) . '</td><td class="num">' . $money($this->pdfValue($row, 'gross_profit', 0), $currencyCode) . '</td>';
            }
            $menuRows .= '</tr>';
        }

        $catRows = '';
        foreach ($groupRows($categoryProfit, ['category_name'])->take(40) as $group) {
            $first = $group->first();
            $byCurrency = $group->keyBy(fn ($row) => strtoupper((string) $this->pdfValue($row, 'currency_code', '')));
            $catRows .= '<tr><td>' . $e($this->pdfValue($first, 'category_name', '')) . '</td><td class="num">' . $qty($group->sum(fn ($row) => (float) $this->pdfValue($row, 'quantity_sold', 0))) . '</td>';
            foreach ($currencyCodes as $currencyCode) {
                $row = $byCurrency->get($currencyCode);
                $catRows .= '<td class="num">' . $money($this->pdfValue($row, 'net_sales', 0), $currencyCode) . '</td><td class="num">' . $money($this->pdfValue($row, 'cost_of_sales', 0), $currencyCode) . '</td><td class="num">' . $money($this->pdfValue($row, 'gross_profit', 0), $currencyCode) . '</td>';
            }
            $catRows .= '</tr>';
        }

        $ingredientRows = '';
        foreach ($groupRows($ingredientConsumption, ['sale_date', 'ingredient_id', 'ingredient_name'])->take(45) as $group) {
            $first = $group->first();
            $byCurrency = $group->keyBy(fn ($row) => strtoupper((string) $this->pdfValue($row, 'currency_code', '')));
            $ingredientRows .= '<tr><td>' . $e($this->pdfValue($first, 'sale_date', '')) . '</td><td>' . $e($this->pdfValue($first, 'ingredient_name', '')) . '<br><small>' . $e($this->pdfValue($first, 'ingredient_code', '')) . '</small></td><td class="num">' . $qty($group->sum(fn ($row) => (float) $this->pdfValue($row, 'consumed_quantity', 0))) . '</td><td>' . $e($this->pdfValue($first, 'uom_code', '')) . '</td>';
            foreach ($currencyCodes as $currencyCode) {
                $row = $byCurrency->get($currencyCode);
                $ingredientRows .= '<td class="num">' . $money($this->pdfValue($row, 'average_unit_cost', 0), $currencyCode) . '</td><td class="num">' . $money($this->pdfValue($row, 'consumed_cost', 0), $currencyCode) . '</td>';
            }
            $ingredientRows .= '</tr>';
        }

        $payRows = '';
        foreach ($groupRows($paymentRows, ['payment_name', 'money_code'])->take(35) as $group) {
            $first = $group->first();
            $byCurrency = $group->keyBy(fn ($row) => strtoupper((string) $this->pdfValue($row, 'currency_code', '')));
            $payRows .= '<tr><td>' . $e($this->pdfValue($first, 'payment_name', '')) . '</td><td>' . $e($this->pdfValue($first, 'money_code', '')) . '</td><td class="num">' . $e($group->sum(fn ($row) => (int) $this->pdfValue($row, 'payment_count', 0))) . '</td>';
            foreach ($currencyCodes as $currencyCode) {
                $currencyPayment = $byCurrency->get($currencyCode);
                $payRows .= '<td class="num">' . $money($this->pdfValue($currencyPayment, 'amount', 0), $currencyCode) . '</td>';
                $payRows .= '<td class="num">' . $money($this->pdfValue($currencyPayment, 'amount_tendered', 0), $currencyCode) . '</td>';
                $payRows .= '<td class="num">' . $money($this->pdfValue($currencyPayment, 'change_amount', 0), $currencyCode) . '</td>';
            }
            $payRows .= '</tr>';
        }

        $receiptRows = '';
        foreach ($receiptDetail as $receipt) {
            $currencyCode = strtoupper((string) $this->pdfValue($receipt, 'currency_code', ''));
            $receiptRows .= '<tr><td>' . $e($this->pdfValue($receipt, 'invoice_number', '')) . '</td>';
            $receiptRows .= '<td>' . $e($this->pdfValue($receipt, 'transaction_date', '')) . '</td>';
            $receiptRows .= '<td class="num">' . $e((int) $this->pdfValue($receipt, 'line_count', 0)) . '</td>';
            $receiptRows .= '<td>' . $e($this->pdfValue($receipt, 'payment_method', '')) . '</td>';
            $receiptRows .= '<td>' . $e($this->pdfValue($receipt, 'cashier_name', '')) . '</td>';
            $receiptRows .= '<td>' . $e($this->pdfValue($receipt, 'till_name', '')) . '</td>';
            $receiptRows .= '<td class="num">' . $money($this->pdfValue($receipt, 'total_amount', 0), $currencyCode) . '</td></tr>';
        }

        return '<html><head><style>@page{margin:18px}body{font-family:Arial,sans-serif;font-size:8px;color:#172033}.title{background:#172033;color:#fff;padding:12px 14px}h1{font-size:18px;margin:0}.sub{color:#CBD5E1;margin-top:4px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:10px 0}.card{border:1px solid #CBD5E1;background:#F8FAFC;padding:8px}.card b{font-size:13px}.card span{display:block;color:#64748B;margin-top:5px}.card strong{display:block;font-size:13px}.card em{display:block;margin-top:6px;color:#0F766E}table{width:100%;border-collapse:collapse;margin-top:10px;page-break-inside:auto}tr{page-break-inside:avoid;page-break-after:auto}th{background:#E2E8F0;color:#172033;text-align:left;font-size:7px;text-transform:uppercase}td,th{border:1px solid #CBD5E1;padding:3px}.num{text-align:right}.section{background:#334155;color:#fff;padding:6px 8px;margin-top:12px;font-weight:bold;page-break-after:avoid}small{color:#64748B}</style></head><body><div class="title"><h1>' . $e($report['report_title'] ?? 'FOOD INDUSTRY SALES REPORT') . '</h1><div class="sub">' . $e($filters['date_from']) . ' to ' . $e($filters['date_to']) . ' · Generated ' . $e($report['generated_at']) . '</div></div><div class="cards">' . $cards . '</div><div class="section">Menu Item Profitability</div><table><thead><tr><th>Menu Item</th><th>Category</th><th>Qty</th>' . $currencyHeader(['Sales', 'Cost of Sales', 'Gross Profit']) . '</tr></thead><tbody>' . $menuRows . '</tbody></table><div class="section">Category Sales and Profit</div><table><thead><tr><th>Category</th><th>Qty</th>' . $currencyHeader(['Sales', 'Cost of Sales', 'Gross Profit']) . '</tr></thead><tbody>' . $catRows . '</tbody></table><div class="section">Ingredient Consumption Detail</div><table><thead><tr><th>Date</th><th>Ingredient</th><th>Consumed Qty</th><th>UOM</th>' . $currencyHeader(['Unit Cost', 'Total Cost']) . '</tr></thead><tbody>' . $ingredientRows . '</tbody></table><div class="section">Payment Summary</div><table><thead><tr><th>Payment</th><th>Money Code</th><th>Count</th>' . $currencyHeader(['Amount', 'Tendered', 'Change']) . '</tr></thead><tbody>' . $payRows . '</tbody></table><div class="section">Receipt Detail</div><table><thead><tr><th>Invoice</th><th>Date Time</th><th>Lines</th><th>Payment</th><th>Cashier</th><th>Till</th><th>Total</th></tr></thead><tbody>' . $receiptRows . '</tbody></table></body></html>';
    }
}
