<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Models\TradingDay;

class HospitalityMonthEndController extends Controller
{
    private const FRONT_STOCK_AREA = 'front';
    private const BACK_STOCK_AREA = 'back';

    public function preflight(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $today = now()->toDateString();
        $activeTradingDay = $this->activeOpenTradingDay($company->id, $branchId);
        $activeTradingDate = $this->tradingDateString($activeTradingDay);
        $range = $this->monthRange($monthEndDate);
        $frontCalendar = $this->frontStockClosingCalendar($company->id, $branchId, $range['date_from'], $range['date_to']);
        $backCount = $this->latestBackStockCount($company->id, $branchId, $monthEndDate);
        $existingReport = $this->existingReport($company->id, $branchId, $monthEndDate);

        $blocking = [];

        if ($activeTradingDate !== $today) {
            $blocking[] = [
                'code' => 'TRADING_DATE_NOT_TODAY',
                'message' => 'Month End is blocked because the active trading date is not today. Run Day End first until the trading date becomes today.',
                'active_trading_date' => $activeTradingDate,
                'today' => $today,
            ];
        }

        if ($activeTradingDate && Carbon::parse($activeTradingDate)->lte(Carbon::parse($monthEndDate))) {
            $blocking[] = [
                'code' => 'MONTH_NOT_FULLY_DAY_ENDED',
                'message' => 'Month End is blocked because the month-end trading days have not all been closed. Continue Day End until the active trading day is after the month end date.',
                'active_trading_date' => $activeTradingDate,
                'month_end_date' => $monthEndDate,
            ];
        }

        $missingFrontClosings = collect($frontCalendar)->where('is_closed', false)->values()->all();
        if (count($missingFrontClosings) > 0) {
            $blocking[] = [
                'code' => 'MISSING_FRONT_STOCK_CLOSINGS',
                'message' => 'Month End is blocked because some daily front stock closings are missing.',
                'missing_dates' => collect($missingFrontClosings)->pluck('date')->values()->all(),
            ];
        }

        if (!$backCount) {
            $blocking[] = [
                'code' => 'MISSING_BACK_STOCK_MONTH_END_COUNT',
                'message' => 'Month End is blocked because the final BOH/back stock month-end count has not been posted.',
                'month_end_date' => $monthEndDate,
            ];
        }

        return response()->json([
            'data' => [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'date_from' => $range['date_from'],
                'date_to' => $range['date_to'],
                'today' => $today,
                'active_trading_day' => $activeTradingDay,
                'active_trading_date' => $activeTradingDate,
                'can_run_month_end' => count($blocking) === 0,
                'trading_date_is_today' => $activeTradingDate === $today,
                'trading_days_closed' => !($activeTradingDate && Carbon::parse($activeTradingDate)->lte(Carbon::parse($monthEndDate))),
                'front_stock_closings_complete' => count($missingFrontClosings) === 0,
                'back_stock_count_complete' => (bool) $backCount,
                'has_back_stock_month_end_count' => (bool) $backCount,
                'blocking_items' => $blocking,
                'front_stock_closing_calendar' => $frontCalendar,
                'back_stock_month_end_count' => $backCount,
                'existing_report' => $existingReport,
                'progress_stages' => $this->monthEndProgressStages(),
            ],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $userId = auth()->id();

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'month_end_date' => ['required', 'date'],
            'replace_existing' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'run_key' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date'));
        $replaceExisting = $request->boolean('replace_existing', false);
        $runKey = (string) ($request->input('run_key') ?: Str::uuid());

        $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'queued', 'Preparing Month End close', 3);

        return DB::transaction(function () use ($company, $branchId, $userId, $monthEndDate, $replaceExisting, $request, $runKey) {
            $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'preflight', 'Checking controls', 10);
            $preflight = $this->preflightArray($company->id, $branchId, $monthEndDate);

            if (!$preflight['can_run_month_end']) {
                $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'hold', 'Month End is on hold', 100, 'error', $preflight['blocking_items'] ?? []);
                return response()->json([
                    'message' => 'Month End cannot run yet. Resolve the blocking items first.',
                    'data' => $preflight,
                ], 422);
            }

            $existing = $this->existingReport($company->id, $branchId, $monthEndDate, true);

            if ($existing && !$replaceExisting) {
                $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'hold', 'Existing Month End report found', 100, 'error');
                return response()->json([
                    'message' => 'Month End report already exists for this month. Use replace_existing=true to regenerate it.',
                    'data' => $existing,
                ], 409);
            }

            if ($existing && $replaceExisting) {
                DB::table('hospitality_month_end_reports')->where('id', $existing->id)->delete();
            }

            $range = $this->monthRange($monthEndDate);
            $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'range', 'Preparing month range', 18);
            $report = $this->buildReportPayload($company->id, $branchId, $range['date_from'], $range['date_to'], $monthEndDate, $runKey);
            $summary = $report['summary'];
            $reportId = (string) Str::uuid();

            $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'save', 'Saving month end report', 92);

            DB::table('hospitality_month_end_reports')->insert($this->filterColumns('hospitality_month_end_reports', [
                'id' => $reportId,
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'date_from' => $range['date_from'],
                'date_to' => $range['date_to'],
                'status' => 'closed',
                'sales_total' => $summary['sales_total'],
                'sales_currency_summary' => json_encode($summary['sales_by_currency']),
                'front_stock_variance_total_value' => $summary['front_stock_variance_total_value'],
                'back_stock_variance_total_value' => $summary['back_stock_variance_total_value'],
                'report_payload' => json_encode($report),
                'closed_by' => $userId,
                'closed_at' => now(),
                'notes' => $request->input('notes'),
                'metadata' => json_encode([
                    'source' => 'hospitality_month_end',
                    'active_trading_date_at_run' => $preflight['active_trading_date'],
                    'generated_at' => now()->toISOString(),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $this->writeMonthEndProgress($company->id, $branchId, $runKey, 'complete', 'Completed', 100, 'complete');

            return response()->json([
                'message' => 'Hospitality Month End completed and report generated.',
                'data' => [
                    'id' => $reportId,
                    'run_key' => $runKey,
                    'month_end_date' => $monthEndDate,
                    'report' => $report,
                    'progress_stages' => collect($this->monthEndProgressStages())->map(fn ($stage) => array_merge($stage, ['status' => 'complete']))->values()->all(),
                ],
            ], 201);
        });
    }


    public function progress(Request $request, string $runKey): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $payload = Cache::get($this->progressCacheKey($company->id, $branchId, $runKey));

        if (!$payload) {
            return response()->json([
                'data' => [
                    'run_key' => $runKey,
                    'status' => 'pending',
                    'stage_key' => 'queued',
                    'stage_label' => 'Waiting for Month End to start',
                    'percent' => 0,
                    'stages' => $this->monthEndProgressStages(),
                ],
            ]);
        }

        return response()->json(['data' => $payload]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);

        if (!$company) {
            return response()->json(['message' => 'Company could not be resolved.'], 422);
        }

        $row = DB::table('hospitality_month_end_reports')
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Month End report not found.'], 404);
        }

        return response()->json(['data' => $this->decodeReportRow($row)]);
    }

    public function latest(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $rows = DB::table('hospitality_month_end_reports')
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->orderByDesc('month_end_date')
            ->paginate((int) $request->input('per_page', 20));

        $rows->getCollection()->transform(fn ($row) => $this->decodeReportRow($row, false));

        return response()->json($rows);
    }

    public function livePreview(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $range = $this->monthRange($monthEndDate);

        return response()->json([
            'data' => $this->buildReportPayload($company->id, $branchId, $range['date_from'], $range['date_to'], $monthEndDate),
        ]);
    }

    public function downloadCsv(Request $request, string $id)
    {
        $company = $this->resolveCompany($request);

        if (!$company) {
            return response()->json(['message' => 'Company could not be resolved.'], 422);
        }

        $row = DB::table('hospitality_month_end_reports')
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Month End report not found.'], 404);
        }

        $report = $this->decodeJson($row->report_payload, []);
        $csv = $this->reportCsv($report);
        $fileName = 'hospitality-month-end-' . Carbon::parse($row->month_end_date)->toDateString() . '.csv';

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    private function preflightArray(string $companyId, string $branchId, string $monthEndDate): array
    {
        $today = now()->toDateString();
        $activeTradingDay = $this->activeOpenTradingDay($companyId, $branchId);
        $activeTradingDate = $this->tradingDateString($activeTradingDay);
        $range = $this->monthRange($monthEndDate);
        $frontCalendar = $this->frontStockClosingCalendar($companyId, $branchId, $range['date_from'], $range['date_to']);
        $backCount = $this->latestBackStockCount($companyId, $branchId, $monthEndDate);
        $blocking = [];

        if ($activeTradingDate !== $today) {
            $blocking[] = [
                'code' => 'TRADING_DATE_NOT_TODAY',
                'message' => 'Month End is blocked because the active trading date is not today. Run Day End first until the trading date becomes today.',
                'active_trading_date' => $activeTradingDate,
                'today' => $today,
            ];
        }

        if ($activeTradingDate && Carbon::parse($activeTradingDate)->lte(Carbon::parse($monthEndDate))) {
            $blocking[] = [
                'code' => 'MONTH_NOT_FULLY_DAY_ENDED',
                'message' => 'Month End is blocked because the month-end trading days have not all been closed. Continue Day End until the active trading day is after the month end date.',
                'active_trading_date' => $activeTradingDate,
                'month_end_date' => $monthEndDate,
            ];
        }

        $missingFrontClosings = collect($frontCalendar)->where('is_closed', false)->values()->all();
        if (count($missingFrontClosings) > 0) {
            $blocking[] = [
                'code' => 'MISSING_FRONT_STOCK_CLOSINGS',
                'message' => 'Month End is blocked because daily front stock closings are missing.',
                'missing_dates' => collect($missingFrontClosings)->pluck('date')->values()->all(),
            ];
        }

        if (!$backCount) {
            $blocking[] = [
                'code' => 'MISSING_BACK_STOCK_MONTH_END_COUNT',
                'message' => 'Month End is blocked because the final BOH/back stock month-end count has not been posted.',
                'month_end_date' => $monthEndDate,
            ];
        }

        return [
            'month_end_date' => $monthEndDate,
            'date_from' => $range['date_from'],
            'date_to' => $range['date_to'],
            'today' => $today,
            'active_trading_day' => $activeTradingDay,
            'active_trading_date' => $activeTradingDate,
            'can_run_month_end' => count($blocking) === 0,
            'trading_date_is_today' => $activeTradingDate === $today,
            'trading_days_closed' => !($activeTradingDate && Carbon::parse($activeTradingDate)->lte(Carbon::parse($monthEndDate))),
            'front_stock_closings_complete' => count($missingFrontClosings) === 0,
            'back_stock_count_complete' => (bool) $backCount,
            'has_back_stock_month_end_count' => (bool) $backCount,
            'blocking_items' => $blocking,
            'front_stock_closing_calendar' => $frontCalendar,
            'back_stock_month_end_count' => $backCount,
            'progress_stages' => $this->monthEndProgressStages(),
        ];
    }

    private function buildReportPayload(string $companyId, string $branchId, string $dateFrom, string $dateTo, string $monthEndDate, ?string $runKey = null): array
    {
        $this->writeMonthEndProgress($companyId, $branchId, $runKey, 'sales', 'Calculating sales', 28);
        $sales = $this->salesRows($companyId, $branchId, $dateFrom, $dateTo);
        $this->writeMonthEndProgress($companyId, $branchId, $runKey, 'sales_currency', 'Summarising sales by currency', 40);
        $salesByCurrency = $this->salesByCurrency($sales);
        $this->writeMonthEndProgress($companyId, $branchId, $runKey, 'front_stock', 'Reading front stock variances', 52);
        $frontClosings = $this->frontStockClosings($companyId, $branchId, $dateFrom, $dateTo);
        $frontLines = $this->frontStockClosingLines($companyId, $branchId, $dateFrom, $dateTo);
        $this->writeMonthEndProgress($companyId, $branchId, $runKey, 'back_stock', 'Reading back stock count', 66);
        $backCount = $this->latestBackStockCount($companyId, $branchId, $monthEndDate);
        $backLines = $backCount ? $this->backStockCountLines($backCount->id) : [];
        $this->writeMonthEndProgress($companyId, $branchId, $runKey, 'summary', 'Building summary', 80);

        return [
            'header' => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'generated_at' => now()->toISOString(),
            ],
            'summary' => [
                'sales_total' => round(collect($sales)->sum('total_amount'), 6),
                'sales_count' => count($sales),
                'sales_by_currency' => $salesByCurrency,
                'front_stock_closing_days' => count($frontClosings),
                'front_stock_variance_total_value' => round(collect($frontClosings)->sum('variance_total_value'), 6),
                'front_stock_variance_lines' => (int) collect($frontClosings)->sum('variance_lines'),
                'back_stock_count_id' => $backCount?->id,
                'back_stock_variance_total_value' => round((float) ($backCount?->variance_total_value ?? 0), 6),
                'back_stock_variance_lines' => (int) ($backCount?->variance_lines ?? 0),
            ],
            'sales' => [
                'by_currency' => $salesByCurrency,
                'rows' => $sales,
            ],
            'front_stock_daily_closings' => [
                'days' => $frontClosings,
                'lines' => $frontLines,
            ],
            'back_stock_month_end_count' => [
                'count' => $backCount,
                'lines' => $backLines,
            ],
        ];
    }

    private function salesRows(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        if (!Schema::hasTable('sales_transactions')) {
            return [];
        }

        $amountColumn = Schema::hasColumn('sales_transactions', 'total_amount') ? 'total_amount' : 'grand_total';
        $currencyExpression = $this->salesCurrencyExpression();

        return DB::table('sales_transactions')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('sales_transactions', 'branch_id'), fn ($query) => $query->where('branch_id', $branchId))
            ->when(Schema::hasColumn('sales_transactions', 'transaction_date'), function ($query) use ($dateFrom, $dateTo) {
                $query->whereDate('transaction_date', '>=', $dateFrom)->whereDate('transaction_date', '<=', $dateTo);
            }, function ($query) use ($dateFrom, $dateTo) {
                $query->whereDate('created_at', '>=', $dateFrom)->whereDate('created_at', '<=', $dateTo);
            })
            ->where(function ($query) {
                if (Schema::hasColumn('sales_transactions', 'status')) {
                    $query->whereNotIn('status', ['voided', 'cancelled', 'reversed']);
                }
            })
            ->selectRaw('
                id,
                invoice_number,
                transaction_date,
                ' . $amountColumn . ' as total_amount,
                ' . $currencyExpression . ' as currency_code,
                payment_method,
                status
            ')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'invoice_number' => $row->invoice_number ?? null,
                'transaction_date' => $row->transaction_date ?? null,
                'total_amount' => round((float) ($row->total_amount ?? 0), 6),
                'currency_code' => $row->currency_code ?: 'USD',
                'payment_method' => $row->payment_method ?? null,
                'status' => $row->status ?? null,
            ])
            ->values()
            ->all();
    }

    private function salesByCurrency(array $sales): array
    {
        return collect($sales)
            ->groupBy(fn ($row) => $row['currency_code'] ?: 'USD')
            ->map(fn ($rows, $currency) => [
                'currency_code' => $currency,
                'sales_count' => $rows->count(),
                'sales_total' => round($rows->sum('total_amount'), 6),
            ])
            ->values()
            ->all();
    }

    private function salesCurrencyExpression(): string
    {
        if (Schema::hasColumn('sales_transactions', 'currency_code') && Schema::hasColumn('sales_transactions', 'currency')) {
            return 'COALESCE(currency_code, currency, "USD")';
        }

        if (Schema::hasColumn('sales_transactions', 'currency_code')) {
            return 'COALESCE(currency_code, "USD")';
        }

        if (Schema::hasColumn('sales_transactions', 'currency')) {
            return 'COALESCE(currency, "USD")';
        }

        return '"USD"';
    }

    private function frontStockClosings(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        if (!Schema::hasTable('front_stock_daily_closings')) {
            return [];
        }

        return DB::table('front_stock_daily_closings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::FRONT_STOCK_AREA)
            ->whereDate('closing_date', '>=', $dateFrom)
            ->whereDate('closing_date', '<=', $dateTo)
            ->orderBy('closing_date')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    private function frontStockClosingLines(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        if (!Schema::hasTable('front_stock_daily_closing_lines') || !Schema::hasTable('front_stock_daily_closings')) {
            return [];
        }

        return DB::table('front_stock_daily_closing_lines as l')
            ->join('front_stock_daily_closings as c', 'c.id', '=', 'l.front_stock_daily_closing_id')
            ->where('l.company_id', $companyId)
            ->where('l.branch_id', $branchId)
            ->where('l.stock_area', self::FRONT_STOCK_AREA)
            ->whereDate('c.closing_date', '>=', $dateFrom)
            ->whereDate('c.closing_date', '<=', $dateTo)
            ->selectRaw('l.*, c.closing_date')
            ->orderBy('c.closing_date')
            ->orderBy('l.product_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    private function latestBackStockCount(string $companyId, string $branchId, string $monthEndDate): mixed
    {
        if (!Schema::hasTable('back_stock_month_end_counts')) {
            return null;
        }

        return DB::table('back_stock_month_end_counts')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('month_end_date', $monthEndDate)
            ->where('stock_area', self::BACK_STOCK_AREA)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->first();
    }

    private function backStockCountLines(string $countId): array
    {
        if (!Schema::hasTable('back_stock_month_end_count_lines')) {
            return [];
        }

        return DB::table('back_stock_month_end_count_lines')
            ->where('back_stock_month_end_count_id', $countId)
            ->orderBy('product_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    private function frontStockClosingCalendar(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        if (!Schema::hasTable('front_stock_daily_closings')) {
            return [];
        }

        $existing = DB::table('front_stock_daily_closings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::FRONT_STOCK_AREA)
            ->whereDate('closing_date', '>=', $dateFrom)
            ->whereDate('closing_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->closing_date)->toDateString());

        $rows = [];
        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $dateString = $date->toDateString();
            $closing = $existing->get($dateString);
            $rows[] = [
                'date' => $dateString,
                'stock_area' => self::FRONT_STOCK_AREA,
                'is_closed' => (bool) $closing,
                'status' => $closing ? ($closing->status ?: 'closed') : 'missing',
                'closing_id' => $closing?->id,
                'total_lines' => (int) ($closing?->total_lines ?? 0),
                'variance_lines' => (int) ($closing?->variance_lines ?? 0),
                'variance_total_value' => round((float) ($closing?->variance_total_value ?? 0), 6),
            ];
        }

        return $rows;
    }

    private function existingReport(string $companyId, string $branchId, string $monthEndDate, bool $lock = false): mixed
    {
        if (!Schema::hasTable('hospitality_month_end_reports')) {
            return null;
        }

        $query = DB::table('hospitality_month_end_reports')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('month_end_date', $monthEndDate)
            ->orderByDesc('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activeOpenTradingDay(string $companyId, string $branchId): ?TradingDay
    {
        $running = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'day_end_running')
            ->orderByDesc('day_end_started_at')
            ->orderByDesc('trading_date')
            ->first();

        if ($running) {
            return $running;
        }

        return TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function tradingDateString(?TradingDay $day): ?string
    {
        if (!$day) {
            return null;
        }

        $raw = $day->getRawOriginal('trading_date') ?: $day->getAttributeFromArray('trading_date');

        if (!$raw) {
            return null;
        }

        $raw = trim((string) $raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $match)) {
            return $match[0];
        }

        return Carbon::parse($raw, config('app.timezone'))->toDateString();
    }



    private function progressCacheKey(string $companyId, string $branchId, string $runKey): string
    {
        return 'hospitality_month_end_progress:' . $companyId . ':' . $branchId . ':' . $runKey;
    }

    private function writeMonthEndProgress(
        string $companyId,
        string $branchId,
        ?string $runKey,
        string $stageKey,
        string $stageLabel,
        int $percent,
        string $status = 'running',
        array $items = []
    ): void {
        if (!$runKey) {
            return;
        }

        Cache::put($this->progressCacheKey($companyId, $branchId, $runKey), [
            'run_key' => $runKey,
            'status' => $status,
            'stage_key' => $stageKey,
            'stage_label' => $stageLabel,
            'percent' => max(0, min(100, $percent)),
            'items' => $items,
            'stages' => $this->monthEndProgressStages(),
            'updated_at' => now()->toISOString(),
        ], now()->addMinutes(20));
    }

    private function normalizeMonthEndDate(?string $value = null): string
    {
        $date = Carbon::parse($value ?: now()->toDateString());

        // Hospitality rule: when Month End is started on the 1st day of a new month,
        // it closes the previous month, not the new month.
        if ((int) $date->format('d') === 1) {
            return $date->copy()->subDay()->endOfMonth()->toDateString();
        }

        return $date->endOfMonth()->toDateString();
    }

    private function monthEndProgressStages(): array
    {
        return [
            ['key' => 'preflight', 'label' => 'Checking controls', 'percent' => 10],
            ['key' => 'sales', 'label' => 'Calculating sales', 'percent' => 28],
            ['key' => 'sales_currency', 'label' => 'Summarising sales by currency', 'percent' => 40],
            ['key' => 'front_stock', 'label' => 'Reading front stock variances', 'percent' => 52],
            ['key' => 'back_stock', 'label' => 'Reading back stock count', 'percent' => 66],
            ['key' => 'summary', 'label' => 'Building summary', 'percent' => 80],
            ['key' => 'save', 'label' => 'Saving month end report', 'percent' => 92],
            ['key' => 'complete', 'label' => 'Completed', 'percent' => 100],
        ];
    }

    private function monthRange(string $monthEndDate): array
    {
        $end = Carbon::parse($monthEndDate)->endOfMonth();

        return [
            'date_from' => $end->copy()->startOfMonth()->toDateString(),
            'date_to' => $end->toDateString(),
        ];
    }

    private function decodeReportRow(mixed $row, bool $includePayload = true): array
    {
        $payload = (array) $row;
        $payload['sales_currency_summary'] = $this->decodeJson($row->sales_currency_summary ?? null, []);
        $payload['metadata'] = $this->decodeJson($row->metadata ?? null, []);

        if ($includePayload) {
            $payload['report_payload'] = $this->decodeJson($row->report_payload ?? null, []);
        } else {
            unset($payload['report_payload']);
        }

        return $payload;
    }

    private function reportCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Hospitality Month End Report']);
        fputcsv($handle, ['Month End Date', $report['header']['month_end_date'] ?? '']);
        fputcsv($handle, ['Date From', $report['header']['date_from'] ?? '']);
        fputcsv($handle, ['Date To', $report['header']['date_to'] ?? '']);
        fputcsv($handle, []);

        fputcsv($handle, ['Sales by Currency']);
        fputcsv($handle, ['Currency', 'Sales Count', 'Sales Total']);
        foreach (($report['sales']['by_currency'] ?? []) as $row) {
            fputcsv($handle, [$row['currency_code'] ?? '', $row['sales_count'] ?? 0, $row['sales_total'] ?? 0]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['Front Stock Daily Variances']);
        fputcsv($handle, ['Closing Date', 'Product', 'Expected', 'Counted', 'Variance Qty', 'Unit Cost', 'Variance Value']);
        foreach (($report['front_stock_daily_closings']['lines'] ?? []) as $row) {
            fputcsv($handle, [
                $row['closing_date'] ?? '',
                $row['product_name'] ?? '',
                $row['expected_quantity'] ?? 0,
                $row['counted_quantity'] ?? 0,
                $row['variance_quantity'] ?? 0,
                $row['unit_cost'] ?? 0,
                $row['variance_value'] ?? 0,
            ]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['Final Back Stock Count Variances']);
        fputcsv($handle, ['Product', 'Expected', 'Counted', 'Variance Qty', 'Unit Cost', 'Variance Value', 'Currency']);
        foreach (($report['back_stock_month_end_count']['lines'] ?? []) as $row) {
            fputcsv($handle, [
                $row['product_name'] ?? '',
                $row['expected_quantity'] ?? 0,
                $row['counted_quantity'] ?? 0,
                $row['variance_quantity'] ?? 0,
                $row['unit_cost'] ?? 0,
                $row['variance_value'] ?? 0,
                $row['currency_code'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    private function decodeJson(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function resolveCompany(Request $request): mixed
    {
        return app()->bound('company')
            ? app('company')
            : ($request->attributes->get('company') ?? $request->company ?? null);
    }

    private function resolveTill(Request $request): mixed
    {
        return app()->bound('till')
            ? app('till')
            : ($request->attributes->get('till') ?? $request->till ?? null);
    }

    private function resolveBranchId(Request $request): ?string
    {
        $company = $this->resolveCompany($request);

        if (!$company) {
            return null;
        }

        return app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
    }
}
