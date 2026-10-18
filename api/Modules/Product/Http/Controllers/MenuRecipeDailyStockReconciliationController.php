<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Product\Exports\MenuRecipeDsrSpreadsheetExport;
use function App\Helpers\currencyConverter;
use Modules\Product\Models\DailyStockReconciliationLine;
use Modules\Product\Models\DailyStockReconciliationReport;
use Modules\Product\Models\TradingDay;
use Modules\Product\Support\HospitalityTradingDatePolicy;

class MenuRecipeDailyStockReconciliationController extends Controller
{
    private const ALLOWED_INGREDIENT_TYPES = [
        'raw_material','raw material','semi_finished_good','semi finished good',
        'semi-finished good','consumable','consumables','packaging','semi_finished','semi finished',
    ];

    public function live(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $selectedMenuProductIds = $this->parseMenuProductIds($request);
        $tradingDay = $this->currentOrCreateTradingDay($company->id, $branchId, $request->input('trading_date'));
        $report = $this->buildReportPayload($company->id, $branchId, $tradingDay, false, $selectedMenuProductIds);

        return response()->json(['data' => $report]);
    }

    public function downloadLiveExcel(Request $request)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $selectedMenuProductIds = $this->parseMenuProductIds($request);
        $tradingDay = $this->currentOrCreateTradingDay($company->id, $branchId, $request->input('trading_date'));
        $payload = $this->buildReportPayload($company->id, $branchId, $tradingDay, false, $selectedMenuProductIds);
        $report = $this->payloadToReportObject($payload, 'live');
        $filename = 'live-dsr-' . Carbon::parse($tradingDay->trading_date)->format('Y-m-d') . '.xlsx';

        return MenuRecipeDsrSpreadsheetExport::download($report, $filename);
    }

    public function downloadLivePdf(Request $request)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $selectedMenuProductIds = $this->parseMenuProductIds($request);
        $tradingDay = $this->currentOrCreateTradingDay($company->id, $branchId, $request->input('trading_date'));
        $payload = $this->buildReportPayload($company->id, $branchId, $tradingDay, false, $selectedMenuProductIds);
        $report = $this->payloadToReportObject($payload, 'live');
        $html = $this->buildPdfHtml($report);
        $filename = 'live-dsr-' . Carbon::parse($tradingDay->trading_date)->format('Y-m-d') . '.pdf';

        if (class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    public function showSaved(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $report = $this->savedReportQuery($company->id, $branchId)
            ->findOrFail($id);

        return response()->json(['data' => $report]);
    }

    public function listSaved(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $rows = DailyStockReconciliationReport::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->when($request->input('date_from'), fn ($query, $date) => $query->whereDate('trading_date', '>=', $date))
            ->when($request->input('date_to'), fn ($query, $date) => $query->whereDate('trading_date', '<=', $date))
            ->latest('trading_date')
            ->paginate((int) $request->input('per_page', 30));

        return response()->json($rows);
    }

    public function finalizeForTradingDay(string $companyId, string $branchId, TradingDay $tradingDay, ?string $userId = null): DailyStockReconciliationReport
    {
        $payload = $this->buildReportPayload($companyId, $branchId, $tradingDay, true);

        return DB::transaction(function () use ($payload, $companyId, $branchId, $tradingDay, $userId) {
            DailyStockReconciliationReport::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereDate('trading_date', $tradingDay->trading_date)
                ->delete();

            $summary = $payload['summary'] ?? [];
            $report = DailyStockReconciliationReport::create([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'trading_day_id' => $tradingDay->id,
                'trading_date' => $tradingDay->trading_date,
                'status' => 'final',
                'generated_by' => $userId,
                'generated_at' => now(),
                'finalized_at' => now(),
                'currency_code' => $payload['currency_code'] ?? 'USD',
                // Legacy numeric columns are non-nullable. For a mixed-currency
                // report, zero is only a storage placeholder; metadata.summary
                // explicitly marks headline totals unavailable and the
                // per-currency totals remain the accounting source of truth.
                'total_revenue' => is_numeric($summary['total_revenue'] ?? null) ? $summary['total_revenue'] : 0,
                'total_recipe_cost' => is_numeric($summary['total_recipe_cost'] ?? null) ? $summary['total_recipe_cost'] : 0,
                'total_variance_value' => is_numeric($summary['total_variance_value'] ?? null) ? $summary['total_variance_value'] : 0,
                'gross_profit' => is_numeric($summary['gross_profit'] ?? null) ? $summary['gross_profit'] : 0,
                'metadata' => [
                    'source' => 'day_end_menu_recipe_reconciliation',
                    'summary' => $summary,
                    'trading_window' => [
                        'opened_at' => optional($tradingDay->opened_at)->toDateTimeString(),
                        'closed_at' => optional($tradingDay->closed_at)->toDateTimeString(),
                    ],
                ],
            ]);

            foreach ($payload['lines'] as $line) {
                DailyStockReconciliationLine::create(array_merge($line, [
                    'id' => (string) Str::uuid(),
                    'report_id' => $report->id,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'trading_day_id' => $tradingDay->id,
                    'trading_date' => $tradingDay->trading_date,
                ]));
            }

            return $report->fresh(['lines' => fn ($query) => $query->orderBy('section')->orderBy('menu_product_name')->orderBy('ingredient_name')]);
        });
    }

    public function downloadExcel(Request $request, string $id)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $report = $this->savedReportQuery($company->id, $branchId)
            ->findOrFail($id);

        $report = $this->filterReportObjectByMenuIds($report, $this->parseMenuProductIds($request));
        $filename = 'daily-stock-reconciliation-' . Carbon::parse($report->trading_date)->format('Y-m-d') . '.xlsx';

        return MenuRecipeDsrSpreadsheetExport::download($report, $filename);
    }

    public function downloadPdf(Request $request, string $id)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $report = $this->savedReportQuery($company->id, $branchId)
            ->findOrFail($id);

        $report = $this->filterReportObjectByMenuIds($report, $this->parseMenuProductIds($request));
        $html = $this->buildPdfHtml($report);
        $filename = 'daily-stock-reconciliation-' . Carbon::parse($report->trading_date)->format('Y-m-d') . '.pdf';

        if (class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="daily-stock-reconciliation-' . Carbon::parse($report->trading_date)->format('Y-m-d') . '.html"',
        ]);
    }

    private function buildReportPayload(string $companyId, string $branchId, TradingDay $tradingDay, bool $final, ?array $selectedMenuProductIds = null): array
    {
        $date = $this->tradingDayDateString($tradingDay);
        $previousClosing = $this->previousClosingMap($companyId, $branchId, $date);
        $manualClosing = $this->manualClosingMap($companyId, $branchId, $date);
        $movements = $this->movementMap($companyId, $branchId, $tradingDay);
        $sales = $this->menuSales($companyId, $branchId, $tradingDay, $selectedMenuProductIds);
        $recipes = $this->recipeIngredientRows(
            $companyId,
            $branchId,
            $sales->pluck('menu_product_id')->all()
        );
        $modifierRecipeMovements = $this->modifierRecipeMovementRows(
            companyId: $companyId,
            branchId: $branchId,
            tradingDay: $tradingDay,
            selectedMenuProductIds: $selectedMenuProductIds
        );

        $lines = [];
        $ingredientTotals = [];
        $currencyTotals = [];
        $inventoryCurrencyTotals = [];
        $currency = 'USD';
        $totalRevenue = 0;
        $totalRecipeCost = 0;

        foreach ($sales as $sale) {
            $menuRecipes = $recipes->get((string) $sale->menu_product_id, collect());
            $menuCost = 0;

            foreach ($menuRecipes as $recipe) {
                $ingredientId = (string) $recipe->ingredient_product_id;
                $qtyPerMenu = (float) $recipe->recipe_quantity;
                $consumedQty = round($qtyPerMenu * (float) $sale->quantity_sold, 6);
                $unitCost = (float) ($recipe->unit_cost ?? 0);
                $costCurrency = strtoupper((string) ($recipe->currency_code ?: 'USD'));
                $saleCurrency = strtoupper((string) ($sale->sale_currency_code ?: 'USD'));
                $unitCostInSaleCurrency = $this->convertMoney($costCurrency, $saleCurrency, $unitCost, $companyId);
                $lineCost = round($consumedQty * $unitCostInSaleCurrency, 6);
                $inventoryLineCost = round($consumedQty * $unitCost, 6);
                $currency = $saleCurrency;
                $menuCost += $lineCost;

                if (!isset($ingredientTotals[$ingredientId])) {
                    $movement = $movements[$ingredientId] ?? [];
                    $openingQty = (float) ($previousClosing[$ingredientId]['counted_closing_qty'] ?? 0);
                    $countedClosing = (float) ($manualClosing[$ingredientId]['counted_closing_qty'] ?? 0);
                    $ingredientTotals[$ingredientId] = [
                        'ingredient_product_id' => $ingredientId,
                        'ingredient_name' => $recipe->ingredient_name,
                        'ingredient_code' => $recipe->ingredient_code,
                        'ingredient_sku' => $recipe->ingredient_sku,
                        'ingredient_type' => $recipe->ingredient_type,
                        'uom_code' => $recipe->uom_code ?: 'EACH',
                        'opening_qty' => $openingQty,
                        'receipts_qty' => (float) ($movement['receipts_qty'] ?? 0),
                        'boh_to_front_qty' => (float) ($movement['boh_to_front_qty'] ?? 0),
                        'other_issues_qty' => (float) ($movement['other_issues_qty'] ?? 0),
                        'adjustments_qty' => (float) ($movement['adjustments_qty'] ?? 0),
                        'sales_consumption_qty' => 0,
                        'unit_cost' => $unitCost,
                        'currency_code' => $costCurrency,
                    ];
                }

                $ingredientTotals[$ingredientId]['sales_consumption_qty'] += $consumedQty;

                $lines[] = [
                    'section' => 'menu_recipe',
                    'menu_product_id' => (string) $sale->menu_product_id,
                    'menu_product_name' => $sale->menu_product_name,
                    'menu_product_code' => $sale->menu_product_code,
                    'menu_product_sku' => $sale->menu_product_sku,
                    'menu_quantity_sold' => round((float) $sale->quantity_sold, 6),
                    'menu_avg_selling_price' => round((float) $sale->avg_selling_price, 6),
                    'menu_revenue' => round((float) $sale->total_revenue, 6),
                    'ingredient_product_id' => $ingredientId,
                    'ingredient_name' => $recipe->ingredient_name,
                    'ingredient_code' => $recipe->ingredient_code,
                    'ingredient_sku' => $recipe->ingredient_sku,
                    'ingredient_type' => $recipe->ingredient_type,
                    'uom_code' => $recipe->uom_code ?: 'EACH',
                    'recipe_qty_per_menu' => round($qtyPerMenu, 6),
                    'ingredient_consumed_qty' => $consumedQty,
                    'unit_cost' => round($unitCostInSaleCurrency, 6),
                    'ingredient_total_cost' => $lineCost,
                    'currency_code' => $saleCurrency,
                    'metadata' => [
                        'final' => $final,
                        'inventory_unit_cost' => round($unitCost, 6),
                        'inventory_total_cost' => $inventoryLineCost,
                        'inventory_currency_code' => $costCurrency,
                    ],
                ];
            }

            /*
             |--------------------------------------------------------------------------
             | Modifier recipe consumption
             |--------------------------------------------------------------------------
             | Modifier stock is deducted through stock_movements.reference_type
             | sale_modifier_recipe. Add those movements into this same DSR menu cost
             | so modifiers affect recipe cost, food-cost %, gross profit, and
             | ingredient reconciliation.
             */
            $modifierRowsForMenu = $modifierRecipeMovements
                ->get((string) $sale->menu_product_id, collect())
                ->filter(function ($row) use ($sale) {
                    return strtoupper((string) ($row->sale_currency_code ?: 'USD')) ===
                        strtoupper((string) ($sale->sale_currency_code ?: 'USD'));
                })
                ->values();

            foreach ($modifierRowsForMenu as $modifierRow) {
                $ingredientId = (string) $modifierRow->ingredient_product_id;
                $consumedQty = round((float) $modifierRow->ingredient_consumed_qty, 6);
                $unitCost = (float) ($modifierRow->unit_cost ?? 0);
                $costCurrency = strtoupper((string) ($modifierRow->currency_code ?: 'USD'));
                $saleCurrency = strtoupper((string) ($sale->sale_currency_code ?: 'USD'));
                $unitCostInSaleCurrency = $this->convertMoney($costCurrency, $saleCurrency, $unitCost, $companyId);
                $lineCost = round($consumedQty * $unitCostInSaleCurrency, 6);
                $inventoryLineCost = round($consumedQty * $unitCost, 6);

                $menuCost += $lineCost;

                if (!isset($ingredientTotals[$ingredientId])) {
                    $movement = $movements[$ingredientId] ?? [];
                    $openingQty = (float) ($previousClosing[$ingredientId]['counted_closing_qty'] ?? 0);
                    $countedClosing = (float) ($manualClosing[$ingredientId]['counted_closing_qty'] ?? 0);

                    $ingredientTotals[$ingredientId] = [
                        'ingredient_product_id' => $ingredientId,
                        'ingredient_name' => $modifierRow->ingredient_name,
                        'ingredient_code' => $modifierRow->ingredient_code,
                        'ingredient_sku' => $modifierRow->ingredient_sku,
                        'ingredient_type' => $modifierRow->ingredient_type,
                        'uom_code' => $modifierRow->uom_code ?: 'EACH',
                        'opening_qty' => $openingQty,
                        'receipts_qty' => (float) ($movement['receipts_qty'] ?? 0),
                        'boh_to_front_qty' => (float) ($movement['boh_to_front_qty'] ?? 0),
                        'other_issues_qty' => (float) ($movement['other_issues_qty'] ?? 0),
                        'adjustments_qty' => (float) ($movement['adjustments_qty'] ?? 0),
                        'sales_consumption_qty' => 0,
                        'unit_cost' => $unitCost,
                        'currency_code' => $costCurrency,
                    ];
                }

                $ingredientTotals[$ingredientId]['sales_consumption_qty'] += $consumedQty;

                $lines[] = [
                    'section' => 'menu_recipe',
                    'menu_product_id' => (string) $sale->menu_product_id,
                    'menu_product_name' => $sale->menu_product_name . ' + Modifier',
                    'menu_product_code' => $sale->menu_product_code,
                    'menu_product_sku' => $sale->menu_product_sku,
                    'menu_quantity_sold' => round((float) $sale->quantity_sold, 6),
                    'menu_avg_selling_price' => round((float) $sale->avg_selling_price, 6),
                    'menu_revenue' => round((float) $sale->total_revenue, 6),
                    'ingredient_product_id' => $ingredientId,
                    'ingredient_name' => $modifierRow->ingredient_name,
                    'ingredient_code' => $modifierRow->ingredient_code,
                    'ingredient_sku' => $modifierRow->ingredient_sku,
                    'ingredient_type' => $modifierRow->ingredient_type,
                    'uom_code' => $modifierRow->uom_code ?: 'EACH',
                    'recipe_qty_per_menu' => 0,
                    'ingredient_consumed_qty' => $consumedQty,
                    'unit_cost' => round($unitCostInSaleCurrency, 6),
                    'ingredient_total_cost' => $lineCost,
                    'currency_code' => $saleCurrency,
                    'metadata' => [
                        'final' => $final,
                        'source' => 'modifier_recipe_front_stock_consumption',
                        'modifier_name' => $modifierRow->modifier_name,
                        'inventory_unit_cost' => round($unitCost, 6),
                        'inventory_total_cost' => $inventoryLineCost,
                        'inventory_currency_code' => $costCurrency,
                    ],
                ];
            }

            $revenue = (float) $sale->total_revenue;
            $grossProfit = $revenue - $menuCost;
            $saleCurrencyForTotals = strtoupper((string) ($sale->sale_currency_code ?: 'USD'));
            $totalRevenue += $revenue;
            $totalRecipeCost += $menuCost;
            $this->addCurrencyTotal($currencyTotals, $saleCurrencyForTotals, [
                'total_revenue' => $revenue,
                'total_recipe_cost' => $menuCost,
            ]);

            $lines[] = [
                'section' => 'menu_summary',
                'menu_product_id' => (string) $sale->menu_product_id,
                'menu_product_name' => $sale->menu_product_name,
                'menu_product_code' => $sale->menu_product_code,
                'menu_product_sku' => $sale->menu_product_sku,
                'menu_quantity_sold' => round((float) $sale->quantity_sold, 6),
                'menu_avg_selling_price' => round((float) $sale->avg_selling_price, 6),
                'menu_revenue' => round($revenue, 6),
                'menu_recipe_cost' => round($menuCost, 6),
                'menu_gross_profit' => round($grossProfit, 6),
                'menu_cost_percent' => $revenue > 0 ? round(($menuCost / $revenue) * 100, 6) : 0,
                'currency_code' => strtoupper((string) ($sale->sale_currency_code ?: $currency)),
                'metadata' => ['final' => $final],
            ];
        }

        $totalVarianceValue = 0;
        foreach ($ingredientTotals as $ingredientId => $row) {
            $expectedClosing = round(
                $row['opening_qty'] + $row['receipts_qty'] + $row['boh_to_front_qty'] + $row['adjustments_qty'] - $row['other_issues_qty'] - $row['sales_consumption_qty'],
                6
            );
            $countedClosing = (float) ($manualClosing[$ingredientId]['counted_closing_qty'] ?? 0);
            $varianceQty = round($countedClosing - $expectedClosing, 6);
            $varianceValue = round($varianceQty * $row['unit_cost'], 6);
            $totalVarianceValue += $varianceValue;
            $this->addCurrencyTotal($inventoryCurrencyTotals, (string) ($row['currency_code'] ?: 'USD'), [
                'total_variance_value' => $varianceValue,
            ]);

            $lines[] = [
                'section' => 'ingredient_reconciliation',
                'ingredient_product_id' => $ingredientId,
                'ingredient_name' => $row['ingredient_name'],
                'ingredient_code' => $row['ingredient_code'],
                'ingredient_sku' => $row['ingredient_sku'],
                'ingredient_type' => $row['ingredient_type'],
                'uom_code' => $row['uom_code'],
                'opening_qty' => round($row['opening_qty'], 6),
                'receipts_qty' => round($row['receipts_qty'], 6),
                'boh_to_front_qty' => round($row['boh_to_front_qty'], 6),
                'other_issues_qty' => round($row['other_issues_qty'], 6),
                'sales_consumption_qty' => round($row['sales_consumption_qty'], 6),
                'adjustments_qty' => round($row['adjustments_qty'], 6),
                'expected_closing_qty' => $expectedClosing,
                'counted_closing_qty' => round($countedClosing, 6),
                'variance_qty' => $varianceQty,
                'unit_cost' => round($row['unit_cost'], 6),
                'ingredient_total_cost' => round($row['sales_consumption_qty'] * $row['unit_cost'], 6),
                'variance_value' => $varianceValue,
                'currency_code' => $row['currency_code'] ?: $currency,
                'metadata' => ['final' => $final],
            ];
        }

        $currencyTotals = array_values($currencyTotals);
        $inventoryCurrencyTotals = array_values($inventoryCurrencyTotals);
        $salesCurrencyCount = count($currencyTotals);
        $inventoryCurrencyCount = count($inventoryCurrencyTotals);
        $reportCurrencies = collect($currencyTotals)
            ->pluck('currency_code')
            ->merge(collect($inventoryCurrencyTotals)->pluck('currency_code'))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();
        // A MULTI report cannot attach one honest currency unit to any scalar
        // headline, even when sales happen in one currency and inventory
        // variance happens in another. Keep every scalar unavailable and make
        // the per-currency collections authoritative.
        $headlineSalesAvailable = $reportCurrencies->count() <= 1 && $salesCurrencyCount <= 1;
        $headlineVarianceAvailable = $reportCurrencies->count() <= 1 && $inventoryCurrencyCount <= 1;
        $reportCurrencyCode = $reportCurrencies->count() === 1
            ? (string) $reportCurrencies->first()
            : ($reportCurrencies->isEmpty() ? 'USD' : 'MULTI');

        return [
            'trading_day' => $tradingDay,
            'currency_code' => $reportCurrencyCode,
            'selected_menu_product_ids' => $selectedMenuProductIds ?: [],
            'summary' => [
                // Never add unlike currencies. Null is deliberate: consumers
                // must use currency_totals/inventory_currency_totals whenever
                // the corresponding availability flag is false.
                'total_revenue' => $headlineSalesAvailable ? round($totalRevenue, 6) : null,
                'total_recipe_cost' => $headlineSalesAvailable ? round($totalRecipeCost, 6) : null,
                'gross_profit' => $headlineSalesAvailable ? round($totalRevenue - $totalRecipeCost, 6) : null,
                'total_variance_value' => $headlineVarianceAvailable ? round($totalVarianceValue, 6) : null,
                'headline_sales_totals_available' => $headlineSalesAvailable,
                'headline_variance_total_available' => $headlineVarianceAvailable,
                'headline_totals_note' => (!$headlineSalesAvailable || !$headlineVarianceAvailable)
                    ? 'Cross-currency headline totals are unavailable. Use the authoritative per-currency totals.'
                    : null,
                'currency_totals' => $currencyTotals,
                'inventory_currency_totals' => $inventoryCurrencyTotals,
            ],
            'lines' => $lines,
        ];
    }

    private function menuSales(string $companyId, string $branchId, TradingDay $tradingDay, ?array $selectedMenuProductIds = null)
    {
        [$start, $end] = $this->tradingDayWindow($tradingDay);

        /*
         |--------------------------------------------------------------------------
         | Multi-currency menu sales
         |--------------------------------------------------------------------------
         | Sale lines come from receipt_lines. We group by menu item AND sale
         | currency so USD/ZiG/other currencies do not mix in one revenue line.
         */
        $query = DB::table('receipt_lines as rl')
            ->join('sales_transactions as s', 's.id', '=', 'rl.sales_transaction_id')
            ->join('products as p', 'p.id', '=', 'rl.product_id')
            ->where('s.company_id', $companyId)
            ->where('s.branch_id', $branchId)
            ->where('s.status', 'completed')
            ->where(function ($query) {
                $query->whereNull('s.sale_type')
                    ->orWhere('s.sale_type', 'sale');
            })
            ->where(function ($query) {
                $query->whereNull('s.document_type')
                    ->orWhere('s.document_type', 'receipt');
            })
            ->where(function ($query) {
                $query->whereNull('p.product_type')
                    ->orWhere('p.product_type', 'finished_good');
            })
            ->when(!empty($selectedMenuProductIds), fn ($query) => $query->whereIn('p.id', $selectedMenuProductIds))
            ->selectRaw('
                p.id as menu_product_id,
                p.name as menu_product_name,
                p.code as menu_product_code,
                p.sku as menu_product_sku,
                COALESCE(s.currency_code, rl.currency_code, "USD") as sale_currency_code,
                SUM(rl.line_quantity) as quantity_sold,
                COALESCE(SUM(rl.line_total), SUM(rl.line_quantity * rl.line_price), 0) as total_revenue,
                COALESCE(SUM(rl.line_total), SUM(rl.line_quantity * rl.line_price), 0) / NULLIF(SUM(rl.line_quantity), 0) as avg_selling_price
            ')
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 's.currency_code', 'rl.currency_code')
            ->orderBy('p.name')
            ->orderBy('sale_currency_code');

        $this->scopeMenuSalesToTradingDay($query, $tradingDay, $start, $end);

        return $query->get();
    }

    private function scopeMenuSalesToTradingDay($query, TradingDay $tradingDay, Carbon $start, Carbon $end): void
    {
        $tradingDate = $this->tradingDayDateString($tradingDay);
        $hasTradingDayId = Schema::hasColumn('sales_transactions', 'trading_day_id');
        $hasBusinessDate = Schema::hasColumn('sales_transactions', 'business_date');

        $query->where(function ($scope) use (
            $tradingDay,
            $tradingDate,
            $start,
            $end,
            $hasTradingDayId,
            $hasBusinessDate
        ) {
            if ($hasTradingDayId) {
                $scope->where('s.trading_day_id', $tradingDay->id)
                    ->orWhere(function ($legacy) use ($tradingDate, $start, $end, $hasBusinessDate) {
                        $legacy->whereNull('s.trading_day_id');

                        if ($hasBusinessDate) {
                            $legacy->where(function ($businessDateScope) use ($tradingDate, $start, $end) {
                                $businessDateScope->whereDate('s.business_date', $tradingDate)
                                    ->orWhere(function ($untagged) use ($start, $end) {
                                        $untagged->whereNull('s.business_date')
                                            ->whereBetween('s.transaction_date', [$start, $end]);
                                    });
                            });
                        } else {
                            $legacy->whereBetween('s.transaction_date', [$start, $end]);
                        }
                    });

                return;
            }

            if ($hasBusinessDate) {
                $scope->whereDate('s.business_date', $tradingDate)
                    ->orWhere(function ($untagged) use ($start, $end) {
                        $untagged->whereNull('s.business_date')
                            ->whereBetween('s.transaction_date', [$start, $end]);
                    });

                return;
            }

            $scope->whereBetween('s.transaction_date', [$start, $end]);
        });
    }

    private function recipeIngredientRows(string $companyId, string $branchId, array $menuProductIds)
    {
        if (empty($menuProductIds)) {
            return collect();
        }

        $hasCostBranch = Schema::hasColumn('product_costs', 'branch_id');
        $hasCostCurrencyCode = Schema::hasColumn('product_costs', 'currency_code');
        $hasLegacyCostCurrency = Schema::hasColumn('product_costs', 'currency');
        $branchCostAlias = $hasCostBranch ? 'pc_branch' : 'pc_global';
        $branchAverage = "MAX(NULLIF({$branchCostAlias}.average_cost, 0))";
        $branchLast = "MAX(NULLIF({$branchCostAlias}.last_cost, 0))";
        $branchCurrencyParts = [];
        if ($hasCostCurrencyCode) {
            $branchCurrencyParts[] = "MAX(NULLIF({$branchCostAlias}.currency_code, ''))";
        }
        if ($hasLegacyCostCurrency) {
            $branchCurrencyParts[] = "MAX(NULLIF({$branchCostAlias}.currency, ''))";
        }

        $currencyForAlias = static function (array $parts): string {
            return $parts === []
                ? "'USD'"
                : 'COALESCE(' . implode(', ', [...$parts, "'USD'"]) . ')';
        };
        $branchCurrencyExpression = $currencyForAlias($branchCurrencyParts);

        if ($hasCostBranch) {
            $globalAverage = 'MAX(NULLIF(pc_global.average_cost, 0))';
            $globalLast = 'MAX(NULLIF(pc_global.last_cost, 0))';
            $globalCurrencyParts = [];
            if ($hasCostCurrencyCode) {
                $globalCurrencyParts[] = "MAX(NULLIF(pc_global.currency_code, ''))";
            }
            if ($hasLegacyCostCurrency) {
                $globalCurrencyParts[] = "MAX(NULLIF(pc_global.currency, ''))";
            }

            $branchCostExpression = "COALESCE({$branchAverage}, {$branchLast})";
            $unitCostExpression = "COALESCE({$branchAverage}, {$branchLast}, {$globalAverage}, {$globalLast}, 0)";
            $currencyExpression = 'CASE WHEN ' . $branchCostExpression . ' IS NOT NULL THEN '
                . $branchCurrencyExpression . ' ELSE ' . $currencyForAlias($globalCurrencyParts) . ' END';
        } else {
            $unitCostExpression = "COALESCE({$branchAverage}, {$branchLast}, 0)";
            $currencyExpression = $branchCurrencyExpression;
        }

        $query = DB::table('food_recipes as r')
            ->join('food_recipe_ingredients as ri', 'ri.food_recipe_id', '=', 'r.id')
            ->join('products as ip', 'ip.id', '=', 'ri.ingredient_product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'ip.base_uom_id');

        if ($hasCostBranch) {
            $query
                ->leftJoin('product_costs as pc_branch', function ($join) use ($companyId, $branchId) {
                    $join->on('pc_branch.product_id', '=', 'ip.id')
                        ->where('pc_branch.company_id', '=', $companyId)
                        ->where('pc_branch.branch_id', '=', $branchId);
                })
                ->leftJoin('product_costs as pc_global', function ($join) use ($companyId) {
                    $join->on('pc_global.product_id', '=', 'ip.id')
                        ->where('pc_global.company_id', '=', $companyId)
                        ->whereNull('pc_global.branch_id');
                });
        } else {
            // A legacy table without branch_id is company-global by definition.
            $query->leftJoin('product_costs as pc_global', function ($join) use ($companyId) {
                $join->on('pc_global.product_id', '=', 'ip.id')
                    ->where('pc_global.company_id', '=', $companyId);
            });
        }

        return $query
            ->where('r.company_id', $companyId)
            ->whereIn('r.menu_product_id', $menuProductIds)
            ->where('r.recipe_type', 'menu')
            ->where('r.is_active', true)
            ->whereIn(DB::raw('LOWER(ip.product_type)'), self::ALLOWED_INGREDIENT_TYPES)
            ->selectRaw("
                r.menu_product_id,
                ip.id as ingredient_product_id,
                ip.name as ingredient_name,
                ip.code as ingredient_code,
                ip.sku as ingredient_sku,
                ip.product_type as ingredient_type,
                COALESCE(u.code, u.name, 'EACH') as uom_code,
                ri.quantity as recipe_quantity,
                {$unitCostExpression} as unit_cost,
                {$currencyExpression} as currency_code
            ")
            ->groupBy('r.menu_product_id','ip.id','ip.name','ip.code','ip.sku','ip.product_type','u.code','u.name','ri.quantity')
            ->get()
            ->groupBy(fn ($row) => (string) $row->menu_product_id);
    }

    private function modifierRecipeMovementRows(
        string $companyId,
        string $branchId,
        TradingDay $tradingDay,
        ?array $selectedMenuProductIds = null
    ) {
        [$start, $end] = $this->tradingDayWindow($tradingDay);

        $base = DB::table('stock_movements as sm')
            ->join('sales_transactions as s', 's.id', '=', 'sm.reference_id')
            ->join('products as ip', 'ip.id', '=', 'sm.product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'ip.base_uom_id')
            ->where('sm.company_id', $companyId)
            ->where('sm.branch_id', $branchId)
            ->whereBetween('sm.created_at', [$start, $end])
            ->where('sm.movement_type', 'sale')
            ->where('sm.stock_area', 'front')
            ->where('sm.reference_type', 'sale_modifier_recipe')
            ->where('s.status', 'completed')
            ->where(function ($query) {
                $query->whereNull('s.sale_type')
                    ->orWhere('s.sale_type', 'sale');
            })
            ->where(function ($query) {
                $query->whereNull('s.document_type')
                    ->orWhere('s.document_type', 'receipt');
            })
            ->selectRaw("
            JSON_UNQUOTE(JSON_EXTRACT(sm.metadata, '$.menu_product_id')) as menu_product_id,
            JSON_UNQUOTE(JSON_EXTRACT(sm.metadata, '$.menu_product_name')) as menu_product_name,
            JSON_UNQUOTE(JSON_EXTRACT(sm.metadata, '$.modifier.name')) as modifier_name_a,
            JSON_UNQUOTE(JSON_EXTRACT(sm.metadata, '$.modifier.modifier_name')) as modifier_name_b,
            ip.id as ingredient_product_id,
            ip.name as ingredient_name,
            ip.code as ingredient_code,
            ip.sku as ingredient_sku,
            ip.product_type as ingredient_type,
            COALESCE(u.code, u.name, 'EACH') as uom_code,
            COALESCE(s.currency_code, 'USD') as sale_currency_code,
            COALESCE(sm.currency_code, sm.currency, 'USD') as currency_code,
            COALESCE(sm.quantity_out, 0) as quantity_out,
            COALESCE(sm.unit_cost, 0) as unit_cost,
            COALESCE(sm.total_cost, 0) as total_cost
        ");

        return DB::query()
            ->fromSub($base, 'x')
            ->whereNotNull('x.menu_product_id')
            ->when(!empty($selectedMenuProductIds), function ($query) use ($selectedMenuProductIds) {
                $query->whereIn('x.menu_product_id', $selectedMenuProductIds);
            })
            ->selectRaw("
            x.menu_product_id,
            COALESCE(MAX(x.menu_product_name), 'Menu Product') as menu_product_name,
            COALESCE(MAX(x.modifier_name_a), MAX(x.modifier_name_b), 'Modifier') as modifier_name,
            x.ingredient_product_id,
            MAX(x.ingredient_name) as ingredient_name,
            MAX(x.ingredient_code) as ingredient_code,
            MAX(x.ingredient_sku) as ingredient_sku,
            MAX(x.ingredient_type) as ingredient_type,
            MAX(x.uom_code) as uom_code,
            x.sale_currency_code,
            x.currency_code,
            SUM(x.quantity_out) as ingredient_consumed_qty,
            MAX(x.unit_cost) as unit_cost,
            SUM(x.total_cost) as ingredient_total_cost
        ")
            ->groupBy(
                'x.menu_product_id',
                'x.ingredient_product_id',
                'x.sale_currency_code',
                'x.currency_code'
            )
            ->get()
            ->groupBy(fn ($row) => (string) $row->menu_product_id);
    }

    private function previousClosingMap(string $companyId, string $branchId, string $date): array
    {
        $previousClosingId = DB::table('front_stock_daily_closings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', '<', $date)
            ->where('stock_area', 'front')
            ->orderByDesc('closing_date')
            ->value('id');

        if (!$previousClosingId) {
            return [];
        }

        return DB::table('front_stock_daily_closing_lines')
            ->where('front_stock_daily_closing_id', $previousClosingId)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->product_id => ['counted_closing_qty' => (float) $row->counted_quantity]])
            ->all();
    }

    private function manualClosingMap(string $companyId, string $branchId, string $date): array
    {
        $closingId = DB::table('front_stock_daily_closings')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $date)
            ->where('stock_area', 'front')
            ->orderByDesc('updated_at')
            ->value('id');

        if (!$closingId) {
            return [];
        }

        return DB::table('front_stock_daily_closing_lines')
            ->where('front_stock_daily_closing_id', $closingId)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->product_id => ['counted_closing_qty' => (float) $row->counted_quantity]])
            ->all();
    }

    private function movementMap(string $companyId, string $branchId, TradingDay $tradingDay): array
    {
        [$start, $end] = $this->tradingDayWindow($tradingDay);

        return DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($query) {
                $query->where('stock_area', 'front')
                    ->orWhere('to_stock_area', 'front')
                    ->orWhere('from_stock_area', 'front');
            })
            ->selectRaw('
                product_id,

                SUM(
                    CASE
                        WHEN movement_type IN ("purchase", "receipt", "stock_receipt")
                        THEN quantity_in
                        ELSE 0
                    END
                ) as receipts_qty,

                SUM(
                    CASE
                        WHEN movement_type IN ("boh_issue_to_front", "boh_to_front", "transfer_in", "front_transfer_in")
                             OR to_stock_area = "front"
                        THEN quantity_in
                        ELSE 0
                    END
                ) as boh_to_front_qty,

                SUM(
                    CASE
                        WHEN movement_type IN ("sale")
                        THEN quantity_out
                        ELSE 0
                    END
                ) as sales_consumption_qty,

                SUM(
                    CASE
                        WHEN movement_type IN ("stock_take", "adjustment", "stock_adjustment", "sale_cancel")
                        THEN quantity_in - quantity_out
                        ELSE 0
                    END
                ) as adjustments_qty,

                SUM(
                    CASE
                        WHEN movement_type IN ("issue", "wastage", "write_off")
                        THEN quantity_out
                        ELSE 0
                    END
                ) as other_issues_qty
            ')
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->product_id => (array) $row])
            ->all();
    }

    private function currentOrCreateTradingDay(string $companyId, string $branchId, ?string $date = null): TradingDay
    {
        /*
         |--------------------------------------------------------------------------
         | Trading date rule
         |--------------------------------------------------------------------------
         | Do not use the computer calendar date as the active trading date.
         | If 2026-06-28 is still open and the manager runs Day End on 2026-06-29,
         | the system must close 2026-06-28. Only after successful Day End do we
         | open 2026-06-29 as the next trading date.
         */
        $activeDays = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->get();

        if ($activeDays->count() > 1) {
            throw ValidationException::withMessages([
                'trading_date' => [
                    'Daily Stock Reconciliation cannot run because this branch has duplicate open trading days. '
                    . 'Resolve Day End state first.',
                ],
            ]);
        }

        $openDay = $activeDays->first();

        if (!$openDay) {
            $lastClosed = TradingDay::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('status', 'closed')
                ->orderByDesc('trading_date')
                ->first();

            $nextTradingDate = $lastClosed
                ? HospitalityTradingDatePolicy::nextOpenDate($this->tradingDayDateString($lastClosed))
                : HospitalityTradingDatePolicy::systemDate();

            if (!$nextTradingDate) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'No open Hospitality trading date is available yet. Wait until the next system date.',
                    ],
                ]);
            }

            $openDay = TradingDay::firstOrCreate([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'trading_date' => $nextTradingDate,
            ], [
                'id' => (string) Str::uuid(),
                'status' => 'open',
                'opened_at' => now(),
                'metadata' => [
                    'source' => 'auto_open_current_trading_day',
                    'created_from_last_closed_day' => $lastClosed?->id,
                    'system_date_at_open' => HospitalityTradingDatePolicy::systemDate(),
                ],
            ]);
        }

        $activeTradingDate = $this->tradingDayDateString($openDay);
        $systemDate = HospitalityTradingDatePolicy::systemDate();

        if (!in_array((string) $openDay->status, ['open', 'day_end_running'], true)) {
            throw ValidationException::withMessages([
                'trading_date' => [
                    'No authoritative open Hospitality trading date is available for this branch.',
                ],
            ]);
        }

        if (!$activeTradingDate || $activeTradingDate > $systemDate) {
            throw ValidationException::withMessages([
                'trading_date' => [
                    $activeTradingDate
                        ? 'Trading date ' . $activeTradingDate . ' is ahead of system date ' . $systemDate . '.'
                        : 'The active Hospitality trading date is missing or invalid.',
                ],
            ]);
        }

        if ($date !== null && trim($date) !== '') {
            $requestedTradingDate = HospitalityTradingDatePolicy::normalize($date);

            if (!$requestedTradingDate || $requestedTradingDate !== $activeTradingDate) {
                throw ValidationException::withMessages([
                    'trading_date' => [
                        'Daily Stock Reconciliation trading date must match the authoritative branch trading date '
                        . $activeTradingDate . '.',
                    ],
                ]);
            }
        }

        return $openDay;
    }

    private function tradingDayDateString(TradingDay $tradingDay): ?string
    {
        $raw = $tradingDay->getRawOriginal('trading_date')
            ?: $tradingDay->getAttributeFromArray('trading_date')
            ?: $tradingDay->trading_date;

        return HospitalityTradingDatePolicy::normalize($raw);
    }

    private function tradingDayWindow(TradingDay $tradingDay): array
    {
        $start = $tradingDay->opened_at
            ? Carbon::parse($tradingDay->opened_at)
            : Carbon::parse($tradingDay->trading_date)->startOfDay();

        $end = $tradingDay->closed_at
            ? Carbon::parse($tradingDay->closed_at)
            : now();

        if ($end->lessThan($start)) {
            $end = Carbon::parse($tradingDay->trading_date)->endOfDay();
        }

        return [$start, $end];
    }

    private function convertMoney(?string $fromCurrency, ?string $toCurrency, float $amount, string $companyId): float
    {
        $from = strtoupper((string) ($fromCurrency ?: $toCurrency ?: 'USD'));
        $to = strtoupper((string) ($toCurrency ?: $from ?: 'USD'));

        if ($from === $to || abs($amount) <= 0.000001) {
            return $amount;
        }

        try {
            return (float) currencyConverter($from, $to, $amount, $companyId);
        } catch (\Throwable $e) {
            return $amount;
        }
    }

    private function addCurrencyTotal(array &$totals, string $currencyCode, array $values): void
    {
        $currency = strtoupper($currencyCode ?: 'USD');

        if (!isset($totals[$currency])) {
            $totals[$currency] = [
                'currency_code' => $currency,
                'total_revenue' => 0,
                'total_recipe_cost' => 0,
                'gross_profit' => 0,
                'total_variance_value' => 0,
            ];
        }

        foreach ($values as $key => $value) {
            $totals[$currency][$key] = round(((float) ($totals[$currency][$key] ?? 0)) + ((float) $value), 6);
        }

        $totals[$currency]['gross_profit'] = round(
            ((float) ($totals[$currency]['total_revenue'] ?? 0)) - ((float) ($totals[$currency]['total_recipe_cost'] ?? 0)),
            6
        );
    }

    private function buildExcelXml(mixed $report): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $lines = $report->lines ?? collect();
        $menuLines = collect($lines)->where('section', 'menu_summary')->values();
        $recipeLines = collect($lines)->where('section', 'menu_recipe')->values();
        $ingredientLines = collect($lines)->where('section', 'ingredient_reconciliation')->values();
        $ingredientById = $ingredientLines->keyBy(fn ($line) => (string) $line->ingredient_product_id);
        $meta = is_array($report->metadata ?? null) ? ($report->metadata ?? []) : (json_decode($report->metadata ?? '[]', true) ?: []);
        $storedSummary = $meta['summary'] ?? [];
        $date = Carbon::parse($report->trading_date)->format('Y-m-d');

        $cell = function ($value, ?string $style = null, ?string $type = null, int $mergeAcross = 0) use ($e): string {
            $type = $type ?: (is_numeric($value) ? 'Number' : 'String');
            $styleAttr = $style ? ' ss:StyleID="' . $style . '"' : '';
            $mergeAttr = $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
            return '<Cell' . $styleAttr . $mergeAttr . '><Data ss:Type="' . $type . '">' . $e($value) . '</Data></Cell>';
        };

        $row = fn (array $cells): string => '<Row>' . implode('', $cells) . '</Row>';
        $money = fn ($v) => round((float) $v, 4);
        $num = fn ($v) => round((float) $v, 4);

        $sheet = function (string $name, string $rowsXml, ?string $columns = null) use ($e): string {
            return '<Worksheet ss:Name="' . $e($name) . '"><Table>' . ($columns ?: '') . $rowsXml . '</Table></Worksheet>';
        };

        $summaryRows = '';
        $summaryRows .= $row([$cell('Daily Stock Reconciliation', 'Title', 'String', 7)]);
        $summaryRows .= $row([$cell('Trading Date', 'Label'), $cell($date, 'Value'), $cell('Status', 'Label'), $cell(strtoupper((string) ($report->status ?? 'live')), 'Value'), $cell('Generated', 'Label'), $cell(optional($report->generated_at)->format('Y-m-d H:i:s') ?: now()->format('Y-m-d H:i:s'), 'Value')]);
        $summaryRows .= $row([$cell('Report Type', 'Label'), $cell(($report->status ?? 'live') === 'live' ? 'LIVE DSR' : 'CLOSED DAY END DSR', 'Value'), $cell('Currency Mode', 'Label'), $cell($report->currency_code ?? 'MULTI', 'Value'), $cell('Menu Items', 'Label'), $cell($menuLines->count(), 'Value')]);
        $summaryRows .= $row([$cell('')]);
        $summaryRows .= $row([$cell('Currency', 'Header'), $cell('Revenue', 'Header'), $cell('Recipe Cost', 'Header'), $cell('Expected Profit', 'Header'), $cell('Food Cost %', 'Header'), $cell('Variance Value', 'Header')]);

        $currencyTotals = $storedSummary['currency_totals'] ?? [];
        if (empty($currencyTotals)) {
            $currencyTotals = [[
                'currency_code' => $report->currency_code ?: 'USD',
                'total_revenue' => $report->total_revenue ?? 0,
                'total_recipe_cost' => $report->total_recipe_cost ?? 0,
                'gross_profit' => $report->gross_profit ?? 0,
            ]];
        }

        foreach ($currencyTotals as $currencyTotal) {
            $revenue = (float) ($currencyTotal['total_revenue'] ?? 0);
            $cost = (float) ($currencyTotal['total_recipe_cost'] ?? 0);
            $summaryRows .= $row([
                $cell($currencyTotal['currency_code'] ?? 'USD'),
                $cell($money($revenue), 'Money'),
                $cell($money($cost), 'Money'),
                $cell($money($revenue - $cost), 'Money'),
                $cell($revenue > 0 ? round(($cost / $revenue) * 100, 2) : 0, 'Money'),
                $cell($money($currencyTotal['total_variance_value'] ?? 0), 'Money'),
            ]);
        }

        $grouped = '';
        $grouped .= $row([$cell('Menu Item Recipe Profit and Ingredient Movement', 'Title', 'String', 17)]);
        $grouped .= $row([
            $cell('Type', 'Header'), $cell('Code', 'Header'), $cell('Name', 'Header'), $cell('Currency', 'Header'),
            $cell('Qty Sold', 'Header'), $cell('Selling Price', 'Header'), $cell('Revenue', 'Header'), $cell('Recipe Cost', 'Header'),
            $cell('Expected Profit', 'Header'), $cell('Food Cost %', 'Header'), $cell('UOM', 'Header'), $cell('Recipe Qty/Menu', 'Header'),
            $cell('Consumed', 'Header'), $cell('Unit Cost', 'Header'), $cell('Ingredient Cost', 'Header'), $cell('Opening', 'Header'),
            $cell('Receipts', 'Header'), $cell('BOH To Front', 'Header'), $cell('Other Issues', 'Header'), $cell('Sales Consumption', 'Header'),
            $cell('Adjustments', 'Header'), $cell('Expected Closing', 'Header'), $cell('Counted', 'Header'), $cell('Variance Qty', 'Header'), $cell('Variance Value', 'Header')
        ]);

        foreach ($menuLines as $menuLine) {
            $grouped .= $row([
                $cell('MENU ITEM', 'Menu'), $cell($menuLine->menu_product_code, 'Menu'), $cell($menuLine->menu_product_name, 'Menu'), $cell($menuLine->currency_code, 'Menu'),
                $cell($num($menuLine->menu_quantity_sold), 'MenuNum'), $cell($money($menuLine->menu_avg_selling_price), 'MenuNum'), $cell($money($menuLine->menu_revenue), 'MenuNum'),
                $cell($money($menuLine->menu_recipe_cost), 'MenuNum'), $cell($money($menuLine->menu_gross_profit), 'MenuNum'), $cell(round((float) $menuLine->menu_cost_percent, 2), 'MenuNum'),
                $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''), $cell('')
            ]);

            $children = $recipeLines
                ->where('menu_product_id', $menuLine->menu_product_id)
                ->where('currency_code', $menuLine->currency_code)
                ->values();

            if ($children->isEmpty()) {
                $children = $recipeLines->where('menu_product_id', $menuLine->menu_product_id)->values();
            }

            foreach ($children as $recipeLine) {
                $recon = $ingredientById->get((string) $recipeLine->ingredient_product_id);
                $grouped .= $row([
                    $cell('INGREDIENT', 'Ingredient'), $cell($recipeLine->ingredient_code), $cell($recipeLine->ingredient_name), $cell($recipeLine->currency_code),
                    $cell($num($recipeLine->menu_quantity_sold)), $cell(''), $cell(''), $cell(''), $cell(''), $cell(''),
                    $cell($recipeLine->uom_code), $cell($num($recipeLine->recipe_qty_per_menu), 'Qty'), $cell($num($recipeLine->ingredient_consumed_qty), 'Qty'),
                    $cell($money($recipeLine->unit_cost), 'Money'), $cell($money($recipeLine->ingredient_total_cost), 'Money'),
                    $cell($recon ? $num($recon->opening_qty) : 0, 'Qty'), $cell($recon ? $num($recon->receipts_qty) : 0, 'Qty'),
                    $cell($recon ? $num($recon->boh_to_front_qty) : 0, 'Qty'), $cell($recon ? $num($recon->other_issues_qty) : 0, 'Qty'),
                    $cell($recon ? $num($recon->sales_consumption_qty) : 0, 'Qty'), $cell($recon ? $num($recon->adjustments_qty) : 0, 'Qty'),
                    $cell($recon ? $num($recon->expected_closing_qty) : 0, 'Qty'), $cell($recon ? $num($recon->counted_closing_qty) : 0, 'Qty'),
                    $cell($recon ? $num($recon->variance_qty) : 0, 'Variance'), $cell($recon ? $money($recon->variance_value) : 0, 'Variance')
                ]);
            }

            $grouped .= $row([$cell('', null, 'String', 24)]);
        }

        $flow = '';
        $flow .= $row([$cell('Shared Ingredient Flow Across Menu Items', 'Title', 'String', 9)]);
        $flow .= $row([$cell('Ingredient Code', 'Header'), $cell('Ingredient', 'Header'), $cell('UOM', 'Header'), $cell('Total Consumed', 'Header'), $cell('Menu Item', 'Header'), $cell('Menu Qty Sold', 'Header'), $cell('Consumed By Menu', 'Header'), $cell('Unit Cost', 'Header'), $cell('Ingredient Cost', 'Header'), $cell('Currency', 'Header')]);

        $recipeByIngredient = $recipeLines->groupBy(fn ($line) => (string) $line->ingredient_product_id);
        foreach ($recipeByIngredient as $ingredientId => $rowsForIngredient) {
            $first = $rowsForIngredient->first();
            $totalConsumed = $rowsForIngredient->sum(fn ($line) => (float) $line->ingredient_consumed_qty);
            foreach ($rowsForIngredient as $idx => $recipeLine) {
                $flow .= $row([
                    $cell($idx === 0 ? $first->ingredient_code : ''),
                    $cell($idx === 0 ? $first->ingredient_name : ''),
                    $cell($idx === 0 ? $first->uom_code : ''),
                    $cell($idx === 0 ? $num($totalConsumed) : '', 'Qty'),
                    $cell($recipeLine->menu_product_name),
                    $cell($num($recipeLine->menu_quantity_sold), 'Qty'),
                    $cell($num($recipeLine->ingredient_consumed_qty), 'Qty'),
                    $cell($money($recipeLine->unit_cost), 'Money'),
                    $cell($money($recipeLine->ingredient_total_cost), 'Money'),
                    $cell($recipeLine->currency_code),
                ]);
            }
        }

        $columns = str_repeat('<Column ss:Width="95"/>', 25);
        $wideColumns = '<Column ss:Width="120"/><Column ss:Width="180"/>' . str_repeat('<Column ss:Width="90"/>', 23);

        return '<?xml version="1.0"?>' . "\n" .
            '<?mso-application progid="Excel.Sheet"?>' . "\n" .
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
            '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>Daily Stock Reconciliation</Title></DocumentProperties>' .
            '<Styles>' .
            '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="16" ss:Color="#111827"/><Interior ss:Color="#EAF1F7" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Header"><Font ss:Bold="1" ss:Size="10" ss:Color="#FFFFFF"/><Interior ss:Color="#1F2937" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' .
            '<Style ss:ID="Label"><Font ss:Bold="1" ss:Color="#475569"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Value"><Font ss:Bold="1" ss:Color="#111827"/></Style>' .
            '<Style ss:ID="Menu"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#111827" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="MenuNum"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#111827" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.0000"/></Style>' .
            '<Style ss:ID="Ingredient"><Font ss:Bold="1" ss:Color="#111827"/><Interior ss:Color="#EEF6F5" ss:Pattern="Solid"/></Style>' .
            '<Style ss:ID="Qty"><NumberFormat ss:Format="#,##0.0000"/></Style>' .
            '<Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00"/></Style>' .
            '<Style ss:ID="Variance"><Font ss:Bold="1"/><NumberFormat ss:Format="#,##0.0000"/></Style>' .
            '</Styles>' .
            $sheet('Executive Summary', $summaryRows, '<Column ss:Width="150"/><Column ss:Width="170"/><Column ss:Width="120"/><Column ss:Width="170"/><Column ss:Width="120"/><Column ss:Width="170"/>') .
            $sheet('Menu Item Flow', $grouped, $wideColumns) .
            $sheet('Shared Ingredient Flow', $flow, '<Column ss:Width="110"/><Column ss:Width="180"/>' . str_repeat('<Column ss:Width="110"/>', 8)) .
            '</Workbook>';
    }


    private function buildPdfHtml(mixed $report): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $get = function ($row, array|string $keys, mixed $default = null) {
            foreach ((array) $keys as $key) {
                if (is_array($row) && array_key_exists($key, $row)) {
                    return $row[$key];
                }
                if (is_object($row) && isset($row->{$key})) {
                    return $row->{$key};
                }
            }
            return $default;
        };

        $lines = collect($report->lines ?? []);
        $menuLines = $lines->where('section', 'menu_summary')->values();
        $recipeLines = $lines->where('section', 'menu_recipe')->values();
        $ingredientLines = $lines->where('section', 'ingredient_reconciliation')->values();
        $ingredientById = $ingredientLines->keyBy(fn ($line) => (string) $get($line, 'ingredient_product_id'));
        $meta = is_array($report->metadata ?? null) ? ($report->metadata ?? []) : (json_decode($report->metadata ?? '[]', true) ?: []);
        $storedSummary = $meta['summary'] ?? [];
        $date = Carbon::parse($report->trading_date)->format('d M Y');
        $money = fn ($v, $c = 'USD') => $e(($c ?: 'USD') . ' ' . number_format((float) $v, 2));
        $num = fn ($v) => $e(number_format((float) $v, 4));
        $pct = fn ($v) => $e(number_format((float) $v, 2) . '%');

        $totalsByCurrency = $menuLines->groupBy(fn ($line) => (string) ($get($line, 'currency_code', 'USD') ?: 'USD'));

        $currencyCards = '';
        foreach ($totalsByCurrency as $currencyCode => $rows) {
            $revenue = $rows->sum(fn ($line) => (float) $get($line, 'menu_revenue', 0));
            $cost = $rows->sum(fn ($line) => (float) $get($line, 'menu_recipe_cost', 0));
            $profit = $rows->sum(fn ($line) => (float) $get($line, 'menu_gross_profit', 0));
            $foodCost = $revenue > 0 ? ($cost / $revenue) * 100 : 0;
            $currencyCards .= '<div class="card"><div class="label">' . $e($currencyCode) . '</div><div class="value">' . $money($revenue, $currencyCode) . '</div><table class="mini"><tr><td>Recipe cost</td><td>' . $money($cost, $currencyCode) . '</td></tr><tr><td>Expected profit</td><td>' . $money($profit, $currencyCode) . '</td></tr><tr><td>Food cost</td><td>' . $pct($foodCost) . '</td></tr></table></div>';
        }
        if ($currencyCards === '') {
            $currencyCards = '<div class="card"><div class="label">Revenue</div><div class="value">' . $money($report->total_revenue ?? 0, $report->currency_code ?: 'USD') . '</div></div>';
        }

        $profitRows = '';
        foreach ($menuLines as $menuLine) {
            $c = $get($menuLine, 'currency_code', 'USD');
            $profitRows .= '<tr>' .
                '<td><b>' . $e($get($menuLine, 'menu_product_name')) . '</b><br><span>' . $e($get($menuLine, 'menu_product_code')) . '</span></td>' .
                '<td>' . $e($c) . '</td>' .
                '<td class="num">' . $num($get($menuLine, 'menu_quantity_sold')) . '</td>' .
                '<td class="num">' . $money($get($menuLine, 'menu_avg_selling_price'), $c) . '</td>' .
                '<td class="num">' . $money($get($menuLine, 'menu_revenue'), $c) . '</td>' .
                '<td class="num">' . $money($get($menuLine, 'menu_recipe_cost'), $c) . '</td>' .
                '<td class="num strong">' . $money($get($menuLine, 'menu_gross_profit'), $c) . '</td>' .
                '<td class="num">' . $pct($get($menuLine, 'menu_cost_percent')) . '</td>' .
                '</tr>';
        }
        foreach ($totalsByCurrency as $currencyCode => $rows) {
            $revenue = $rows->sum(fn ($line) => (float) $get($line, 'menu_revenue', 0));
            $cost = $rows->sum(fn ($line) => (float) $get($line, 'menu_recipe_cost', 0));
            $profit = $rows->sum(fn ($line) => (float) $get($line, 'menu_gross_profit', 0));
            $foodCost = $revenue > 0 ? ($cost / $revenue) * 100 : 0;
            $profitRows .= '<tr class="total"><td colspan="4">TOTAL PROFIT FOR TRADING DATE - ' . $e($currencyCode) . '</td><td class="num">' . $money($revenue, $currencyCode) . '</td><td class="num">' . $money($cost, $currencyCode) . '</td><td class="num">' . $money($profit, $currencyCode) . '</td><td class="num">' . $pct($foodCost) . '</td></tr>';
        }

        $blocks = '';
        foreach ($menuLines as $menuLine) {
            $children = $recipeLines
                ->where('menu_product_id', $get($menuLine, 'menu_product_id'))
                ->where('currency_code', $get($menuLine, 'currency_code'))
                ->values();
            if ($children->isEmpty()) {
                $children = $recipeLines->where('menu_product_id', $get($menuLine, 'menu_product_id'))->values();
            }

            $c = $get($menuLine, 'currency_code', 'USD');
            $ingredientRows = '';
            foreach ($children as $recipeLine) {
                $recon = $ingredientById->get((string) $get($recipeLine, 'ingredient_product_id'));
                $receiptQty = $get($recon, ['receipts_qty', 'receipt_qty'], 0);
                $bohToFrontQty = $get($recon, ['boh_to_front_qty', 'boh_to_front_quantity'], 0);
                $salesQty = $get($recon, ['sales_consumption_qty', 'sales_consumption_quantity'], 0);
                $expectedQty = $get($recon, ['expected_closing_qty', 'expected_closing_quantity'], 0);
                $countedQty = $get($recon, ['counted_closing_qty', 'counted_closing_quantity'], 0);
                $varianceQty = $get($recon, ['variance_qty', 'variance_quantity'], 0);
                $varianceValue = $get($recon, ['variance_value'], 0);
                $reconCurrency = $get($recon, ['currency_code'], $get($recipeLine, 'currency_code', 'USD'));

                $ingredientRows .= '<tr>' .
                    '<td><b>' . $e($get($recipeLine, 'ingredient_name')) . '</b><br><span>' . $e($get($recipeLine, 'ingredient_code')) . '</span></td>' .
                    '<td>' . $e($get($recipeLine, 'uom_code')) . '</td>' .
                    '<td class="num">' . $num($get($recipeLine, 'recipe_qty_per_menu')) . '</td>' .
                    '<td class="num">' . $num($get($recipeLine, 'ingredient_consumed_qty')) . '</td>' .
                    '<td class="num">' . $money($get($recipeLine, 'unit_cost'), $get($recipeLine, 'currency_code', 'USD')) . '</td>' .
                    '<td class="num strong">' . $money($get($recipeLine, 'ingredient_total_cost'), $get($recipeLine, 'currency_code', 'USD')) . '</td>' .
                    '<td class="num">' . ($recon ? $num($get($recon, ['opening_qty', 'opening_quantity'], 0)) : '-') . '</td>' .
                    '<td class="num">' . ($recon ? $num($receiptQty) : '-') . '</td>' .
                    '<td class="num">' . ($recon ? $num($bohToFrontQty) : '-') . '</td>' .
                    '<td class="num">' . ($recon ? $num($salesQty) : '-') . '</td>' .
                    '<td class="num">' . ($recon ? $num($expectedQty) : '-') . '</td>' .
                    '<td class="num">' . ($recon ? $num($countedQty) : '-') . '</td>' .
                    '<td class="num strong ' . ((float) $varianceQty < 0 ? 'neg' : '') . '">' . ($recon ? $num($varianceQty) : '-') . '</td>' .
                    '<td class="num strong ' . ((float) $varianceValue < 0 ? 'neg' : '') . '">' . ($recon ? $money($varianceValue, $reconCurrency) : '-') . '</td>' .
                    '</tr>';
            }

            $blocks .= '<section class="menu"><div class="menu-head"><div class="menu-title"><h2>' . $e($get($menuLine, 'menu_product_name')) . '</h2><p>' . $e($get($menuLine, 'menu_product_code')) . ' | ' . $e($c) . '</p></div>' .
                '<table class="menu-kpi"><tr><td>Qty sold<br><b>' . $num($get($menuLine, 'menu_quantity_sold')) . '</b></td><td>Avg selling<br><b>' . $money($get($menuLine, 'menu_avg_selling_price'), $c) . '</b></td><td>Revenue<br><b>' . $money($get($menuLine, 'menu_revenue'), $c) . '</b></td><td>Recipe cost<br><b>' . $money($get($menuLine, 'menu_recipe_cost'), $c) . '</b></td><td>Expected profit<br><b>' . $money($get($menuLine, 'menu_gross_profit'), $c) . '</b></td><td>Food cost<br><b>' . $pct($get($menuLine, 'menu_cost_percent')) . '</b></td></tr></table></div>' .
                '<table><thead><tr><th>Ingredient</th><th>UOM</th><th>Recipe</th><th>Consumed</th><th>Unit Cost</th><th>Total Cost</th><th>Opening</th><th>Receipts</th><th>BOH Front</th><th>Sales Cons.</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Value</th></tr></thead><tbody>' . $ingredientRows . '</tbody></table></section>';
        }

        $flowRows = '';
        foreach ($recipeLines->groupBy(fn ($line) => (string) $get($line, 'ingredient_product_id')) as $ingredientId => $rowsForIngredient) {
            $first = $rowsForIngredient->first();
            $menus = $rowsForIngredient->map(fn ($line) => $get($line, 'menu_product_name') . ': ' . number_format((float) $get($line, 'ingredient_consumed_qty'), 4))->implode(' | ');
            $flowRows .= '<tr><td><b>' . $e($get($first, 'ingredient_name')) . '</b><br><span>' . $e($get($first, 'ingredient_code')) . '</span></td><td>' . $e($get($first, 'uom_code')) . '</td><td class="num">' . $num($rowsForIngredient->sum(fn ($line) => (float) $get($line, 'ingredient_consumed_qty'))) . '</td><td>' . $e($menus) . '</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>DSR ' . $e($date) . '</title><style>
            @page { margin: 12px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f2937; font-size: 8px; margin: 0; }
            .top { border-bottom: 2px solid #111827; padding: 6px 0 8px; margin-bottom: 7px; }
            h1 { margin: 0; font-size: 15px; letter-spacing: .2px; }
            .meta { color: #64748b; margin-top: 2px; }
            .cards { display: table; width: 100%; border-spacing: 5px; margin: 5px 0 8px; }
            .card { display: table-cell; border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px; }
            .label { text-transform: uppercase; color: #64748b; font-size: 7px; }
            .value { font-size: 12px; font-weight: 700; margin: 2px 0 3px; }
            .mini { width: 100%; border-collapse: collapse; }
            .mini td { border: 0; padding: 1px 0; font-size: 7px; }
            .mini td:last-child { text-align: right; font-weight: 700; }
            .profit-summary { margin: 7px 0 9px; }
            .menu { page-break-inside: avoid; border: 1px solid #cbd5e1; margin-bottom: 7px; }
            .menu-head { background: #111827; color: white; padding: 6px; }
            .menu-title { margin-bottom: 5px; }
            h2 { margin: 0; font-size: 10.5px; }
            p { margin: 2px 0 0; color: #cbd5e1; }
            .menu-kpi { width: 100%; border-collapse: collapse; color: white; }
            .menu-kpi td { border: 1px solid #334155; padding: 4px 5px; color: #dbeafe; font-size: 7px; text-align: right; }
            .menu-kpi b { color: white; font-size: 8px; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #f1f5f9; color: #334155; font-size: 6.7px; text-align: left; text-transform: uppercase; }
            th, td { border: 1px solid #e2e8f0; padding: 2.5px 3px; vertical-align: top; }
            td span { color: #64748b; font-size: 6.8px; }
            .num { text-align: right; white-space: nowrap; }
            .strong { font-weight: 700; }
            .neg { color: #991b1b; }
            .total td { background: #dcfce7; font-weight: 700; border-top: 2px solid #16a34a; border-bottom: 2px solid #16a34a; }
            .flow-title { font-size: 10px; font-weight: 700; margin: 10px 0 4px; }
        </style></head><body>
            <div class="top"><h1>Daily Stock Reconciliation</h1><div class="meta">Trading Date: ' . $e($date) . ' | Status: ' . $e(strtoupper((string) $report->status)) . ' | Generated: ' . $e(optional($report->generated_at)->format('Y-m-d H:i:s') ?: now()->format('Y-m-d H:i:s')) . '</div></div>
            <div class="cards">' . $currencyCards . '</div>
            <table class="profit-summary"><thead><tr><th>Menu Item</th><th>Currency</th><th>Qty</th><th>Avg Selling</th><th>Revenue</th><th>Recipe Cost</th><th>Expected Profit</th><th>Food Cost</th></tr></thead><tbody>' . $profitRows . '</tbody></table>
            ' . $blocks . '
            <div class="flow-title">Shared Ingredient Flow</div><table><thead><tr><th>Ingredient</th><th>UOM</th><th>Total Consumed</th><th>Consumed By Menu Items</th></tr></thead><tbody>' . $flowRows . '</tbody></table>
        </body></html>';
    }



    private function parseMenuProductIds(Request $request): ?array
    {
        $value = $request->input('menu_product_ids', $request->input('menu_product_id'));

        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return null;
        }

        $ids = collect($value)
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        return count($ids) ? $ids : null;
    }

    private function payloadToReportObject(array $payload, string $status = 'live'): object
    {
        $tradingDay = $payload['trading_day'];
        $summary = $payload['summary'] ?? [];

        return (object) [
            'id' => 'live-' . str_replace('-', '', (string) $this->tradingDayDateString($tradingDay)),
            'trading_date' => $this->tradingDayDateString($tradingDay),
            'status' => $status,
            'generated_at' => now(),
            'finalized_at' => null,
            'currency_code' => $payload['currency_code'] ?? 'MULTI',
            'total_revenue' => array_key_exists('total_revenue', $summary) ? $summary['total_revenue'] : 0,
            'total_recipe_cost' => array_key_exists('total_recipe_cost', $summary) ? $summary['total_recipe_cost'] : 0,
            'gross_profit' => array_key_exists('gross_profit', $summary) ? $summary['gross_profit'] : 0,
            'total_variance_value' => array_key_exists('total_variance_value', $summary) ? $summary['total_variance_value'] : 0,
            'metadata' => [
                'source' => 'live_menu_recipe_daily_stock_reconciliation',
                'summary' => $summary,
                'trading_window' => [
                    'opened_at' => optional($tradingDay->opened_at)->toDateTimeString(),
                    'closed_at' => optional($tradingDay->closed_at)->toDateTimeString(),
                ],
            ],
            'lines' => collect($payload['lines'] ?? [])->map(fn ($line) => (object) $line),
        ];
    }

    private function filterReportObjectByMenuIds(mixed $report, ?array $selectedMenuProductIds): mixed
    {
        if (empty($selectedMenuProductIds) || !isset($report->lines)) {
            return $report;
        }

        $ids = collect($selectedMenuProductIds)->map(fn ($id) => (string) $id)->all();
        $ingredientIds = collect($report->lines)
            ->filter(fn ($line) => ($line->section ?? null) === 'menu_recipe' && in_array((string) ($line->menu_product_id ?? ''), $ids, true))
            ->pluck('ingredient_product_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->all();

        $report->lines = collect($report->lines)->filter(function ($line) use ($ids, $ingredientIds) {
            $section = $line->section ?? null;

            if (in_array($section, ['menu_summary', 'menu_recipe'], true)) {
                return in_array((string) ($line->menu_product_id ?? ''), $ids, true);
            }

            if ($section === 'ingredient_reconciliation') {
                return in_array((string) ($line->ingredient_product_id ?? ''), $ingredientIds, true);
            }

            return true;
        })->values();

        return $report;
    }

    private function savedReportQuery(string $companyId, string $branchId)
    {
        return DailyStockReconciliationReport::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->with(['lines' => fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->orderBy('section')
                ->orderBy('menu_product_name')
                ->orderBy('ingredient_name')]);
    }

    private function resolveCompany(Request $request): mixed
    {
        return app()->bound('company') ? app('company') : ($request->attributes->get('company') ?? $request->company ?? null);
    }

    private function resolveBranchId(Request $request): ?string
    {
        $till = app()->bound('till') ? app('till') : ($request->attributes->get('till') ?? null);
        $company = $this->resolveCompany($request);

        if (!$company?->id) {
            return null;
        }

        $normalize = static function (mixed $value): ?string {
            if (!is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        };
        $bodyBranchId = $normalize($request->input('branch_id'));
        $headerBranchId = $normalize($request->header('X-Branch-ID'));
        $workspaceBranchId = $normalize($request->attributes->get('business_mode_branch_id'));
        $tillBranchId = $normalize($till?->branch_id);

        if ($bodyBranchId && $headerBranchId && $bodyBranchId !== $headerBranchId) {
            $this->rejectBranchContext(
                'The requested report branch does not match the active workspace branch.',
                'BRANCH_CONTEXT_CONFLICT'
            );
        }

        $requestedBranchId = $headerBranchId ?: $bodyBranchId;
        $authoritativeBranchId = $tillBranchId ?: $workspaceBranchId;
        if (
            $authoritativeBranchId
            && $requestedBranchId
            && $authoritativeBranchId !== $requestedBranchId
        ) {
            $this->rejectBranchContext(
                'The requested report is outside the active Hospitality branch.',
                $tillBranchId ? 'TILL_BRANCH_MISMATCH' : 'BRANCH_CONTEXT_CONFLICT',
                $tillBranchId ? 403 : 409
            );
        }

        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchId ? [$requestedBranchId] : []
        );
        $resolvedBranchId = $normalize($scope['context_branch_id'] ?? null);

        if ($authoritativeBranchId && $resolvedBranchId !== $authoritativeBranchId) {
            $this->rejectBranchContext(
                'The active Hospitality branch is not authorised for this report.',
                'BRANCH_ACCESS_DENIED',
                403
            );
        }

        return $resolvedBranchId;
    }

    private function rejectBranchContext(string $message, string $code, int $status = 409): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
