<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Exports\ClosingStockMovementSpreadsheetExport;

class ClosingStockMovementReportController extends Controller
{
    public function closings(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company) {
            return response()->json(['message' => 'Company could not be resolved.'], 422);
        }

        $rows = DB::table('front_stock_daily_closings')
            ->where('company_id', $company->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->input('date_from'), fn ($q, $date) => $q->whereDate('closing_date', '>=', $date))
            ->when($request->input('date_to'), fn ($q, $date) => $q->whereDate('closing_date', '<=', $date))
            ->where('stock_area', $request->input('stock_area', 'front'))
            ->select([
                'id',
                'closing_date',
                'status',
                'stock_area',
                'total_lines',
                'variance_lines',
                'expected_total_quantity',
                'counted_total_quantity',
                'variance_total_quantity',
                'variance_total_value',
                'created_at',
            ])
            ->orderByDesc('closing_date')
            ->limit((int) $request->input('limit', 180))
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $this->buildReportPayload($request);

        return response()->json(['data' => $payload]);
    }

    public function downloadExcel(Request $request)
    {
        $payload = $this->buildReportPayload($request);
        $filename = $this->fileName($payload, 'xlsx');

        return (new ClosingStockMovementSpreadsheetExport($payload))->download($filename);
    }

    public function downloadPdf(Request $request)
    {
        $payload = $this->buildReportPayload($request);
        $filename = $this->fileName($payload, 'pdf');
        $html = $this->buildPdfHtml($payload);

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        if (app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper');
            $pdf->loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    private function buildReportPayload(Request $request): array
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $stockArea = $request->input('stock_area', 'front');

        if (!$company) {
            abort(response()->json(['message' => 'Company could not be resolved.'], 422));
        }

        if (!$branchId) {
            abort(response()->json(['message' => 'Branch could not be resolved.'], 422));
        }

        $closingIds = collect($request->input('closing_ids', []))
            ->merge($request->input('closing_ids.*', []))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $closingDates = collect($request->input('closing_dates', []))
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        $closingQuery = DB::table('front_stock_daily_closings')
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('stock_area', $stockArea)
            ->where('status', 'closed');

        if ($closingIds->isNotEmpty()) {
            $closingQuery->whereIn('id', $closingIds->all());
        } elseif ($closingDates->isNotEmpty()) {
            $closingQuery->whereIn(DB::raw('DATE(closing_date)'), $closingDates->all());
        } else {
            $latest = DB::table('front_stock_daily_closings')
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('stock_area', $stockArea)
                ->where('status', 'closed')
                ->orderByDesc('closing_date')
                ->first();

            if (!$latest) {
                return [
                    'company' => $this->companyArray($company),
                    'branch_id' => $branchId,
                    'stock_area' => $stockArea,
                    'generated_at' => now()->toDateTimeString(),
                    'days' => [],
                    'currency_totals' => [],
                    'movement_detail' => [],
                ];
            }

            $closingQuery->where('id', $latest->id);
        }

        $closings = $closingQuery
            ->orderBy('closing_date')
            ->get();

        $days = [];
        $movementDetail = [];
        $currencyTotals = [];

        foreach ($closings as $closing) {
            $day = Carbon::parse($closing->closing_date)->toDateString();
            $closingLines = DB::table('front_stock_daily_closing_lines')
                ->where('front_stock_daily_closing_id', $closing->id)
                ->orderBy('product_name')
                ->get();

            $movementProductIds = DB::table('stock_movements')
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', $day)
                ->where(function ($q) use ($stockArea) {
                    $q->where('stock_area', $stockArea)
                        ->orWhere('from_stock_area', $stockArea)
                        ->orWhere('to_stock_area', $stockArea);
                })
                ->pluck('product_id');

            $productIds = $closingLines->pluck('product_id')
                ->merge($movementProductIds)
                ->filter()
                ->unique()
                ->values();

            $productInfo = DB::table('products as p')
                ->leftJoin('uoms as u', function ($join) {
                    $join->on('u.id', '=', 'p.base_uom_id');
                })
                ->whereIn('p.id', $productIds->all())
                ->selectRaw('p.id, p.name, p.code, p.sku, p.product_type, COALESCE(u.code, u.name, "EACH") as uom_code')
                ->get()
                ->keyBy('id');

            $movementTotals = $this->movementTotals($company->id, $branchId, $stockArea, $day);
            $movementRows = $this->movementRows($company->id, $branchId, $stockArea, $day, $productInfo);

            foreach ($movementRows as $row) {
                $movementDetail[] = $row;
            }

            $closingLineByProduct = $closingLines->keyBy('product_id');
            $previousClosingMap = $this->previousClosingMap($company->id, $branchId, $stockArea, $day, $productIds->all());

            $lines = [];
            $dayTotals = [
                'opening_qty' => 0,
                'receipts_qty' => 0,
                'boh_to_front_qty' => 0,
                'sales_consumption_qty' => 0,
                'other_issues_qty' => 0,
                'adjustments_qty' => 0,
                'expected_closing_qty' => 0,
                'counted_closing_qty' => 0,
                'variance_qty' => 0,
                'variance_value_by_currency' => [],
            ];

            foreach ($productIds as $productId) {
                $closingLine = $closingLineByProduct->get($productId);
                $product = $productInfo->get($productId);
                $movement = $movementTotals->get($productId);
                $previous = $previousClosingMap->get($productId);

                $currency = $this->currencyFromClosingLine($closingLine) ?: ($movement->currency_code ?? 'USD');
                $unitCost = (float) ($closingLine->unit_cost ?? $movement->unit_cost ?? 0);
                $openingQty = (float) ($previous->counted_quantity ?? 0);
                $receiptsQty = (float) ($movement->receipts_qty ?? 0);
                $bohToFrontQty = (float) ($movement->boh_to_front_qty ?? 0);
                $salesQty = (float) ($movement->sales_consumption_qty ?? 0);
                $otherIssuesQty = (float) ($movement->other_issues_qty ?? 0);
                $adjustmentsQty = (float) ($movement->adjustments_qty ?? 0);
                $closingAdjustmentQty = (float) ($movement->closing_adjustment_qty ?? 0);
                $expectedClosingQty = round($openingQty + $receiptsQty + $bohToFrontQty + $adjustmentsQty - $salesQty - $otherIssuesQty, 6);
                $countedClosingQty = $closingLine ? (float) $closingLine->counted_quantity : null;
                $varianceQty = $countedClosingQty === null ? null : round($countedClosingQty - $expectedClosingQty, 6);
                $varianceValue = $varianceQty === null ? null : round($varianceQty * $unitCost, 6);

                $line = [
                    'closing_id' => $closing->id,
                    'closing_date' => $day,
                    'product_id' => (string) $productId,
                    'product_name' => $closingLine->product_name ?? $product->name ?? 'Product',
                    'product_code' => $closingLine->product_code ?? $product->code ?? null,
                    'product_sku' => $closingLine->product_sku ?? $product->sku ?? null,
                    'product_type' => $closingLine->product_type ?? $product->product_type ?? null,
                    'uom_code' => $closingLine->uom_code ?? $product->uom_code ?? 'EACH',
                    'currency_code' => $currency,
                    'unit_cost' => round($unitCost, 6),
                    'opening_qty' => round($openingQty, 6),
                    'receipts_qty' => round($receiptsQty, 6),
                    'boh_to_front_qty' => round($bohToFrontQty, 6),
                    'sales_consumption_qty' => round($salesQty, 6),
                    'other_issues_qty' => round($otherIssuesQty, 6),
                    'adjustments_qty' => round($adjustmentsQty, 6),
                    'expected_closing_qty' => round($expectedClosingQty, 6),
                    'counted_closing_qty' => $countedClosingQty === null ? null : round($countedClosingQty, 6),
                    'variance_qty' => $varianceQty,
                    'variance_value' => $varianceValue,
                    'closing_adjustment_qty' => round($closingAdjustmentQty, 6),
                    'has_variance' => $varianceQty !== null && abs($varianceQty) > 0.000001,
                    'movement_count' => (int) ($movement->movement_count ?? 0),
                ];

                $lines[] = $line;

                foreach (['opening_qty', 'receipts_qty', 'boh_to_front_qty', 'sales_consumption_qty', 'other_issues_qty', 'adjustments_qty', 'expected_closing_qty'] as $key) {
                    $dayTotals[$key] += (float) $line[$key];
                }

                if ($countedClosingQty !== null) {
                    $dayTotals['counted_closing_qty'] += $countedClosingQty;
                }

                if ($varianceQty !== null) {
                    $dayTotals['variance_qty'] += $varianceQty;
                    $dayTotals['variance_value_by_currency'][$currency] = ($dayTotals['variance_value_by_currency'][$currency] ?? 0) + (float) $varianceValue;
                    $currencyTotals[$currency]['variance_value'] = ($currencyTotals[$currency]['variance_value'] ?? 0) + (float) $varianceValue;
                }
            }

            $days[] = [
                'closing_id' => $closing->id,
                'closing_date' => $day,
                'formatted_date' => Carbon::parse($day)->format('d M Y'),
                'status' => $closing->status,
                'stock_area' => $closing->stock_area,
                'total_lines' => count($lines),
                'variance_lines' => collect($lines)->where('has_variance', true)->count(),
                'totals' => $this->roundTotals($dayTotals),
                'lines' => $lines,
            ];
        }

        ksort($currencyTotals);

        return [
            'company' => $this->companyArray($company),
            'branch_id' => $branchId,
            'stock_area' => $stockArea,
            'generated_at' => now()->toDateTimeString(),
            'days' => $days,
            'currency_totals' => $currencyTotals,
            'movement_detail' => $movementDetail,
        ];
    }

    private function movementTotals(string $companyId, string $branchId, string $stockArea, string $day)
    {
        return DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $day)
            ->where(function ($q) use ($stockArea) {
                $q->where('stock_area', $stockArea)
                    ->orWhere('from_stock_area', $stockArea)
                    ->orWhere('to_stock_area', $stockArea);
            })
            ->selectRaw("\n                product_id,\n                MAX(COALESCE(currency_code, currency, 'USD')) as currency_code,\n                MAX(unit_cost) as unit_cost,\n                COUNT(*) as movement_count,\n                SUM(CASE WHEN movement_type IN ('purchase','receipt','stock_receipt') THEN quantity_in ELSE 0 END) as receipts_qty,\n                SUM(CASE WHEN movement_type IN ('boh_issue_to_front','boh_to_front','transfer_in','front_transfer_in') THEN quantity_in ELSE 0 END) as boh_to_front_qty,\n                SUM(CASE WHEN movement_type IN ('sale') THEN quantity_out ELSE 0 END) as sales_consumption_qty,\n                SUM(CASE WHEN movement_type IN ('issue','wastage','write_off') THEN quantity_out ELSE 0 END) as other_issues_qty,\n                SUM(CASE\n                    WHEN reference_type = 'front_stock_daily_closing' THEN 0\n                    WHEN movement_type IN ('adjustment','stock_adjustment','stock_take','sale_cancel') THEN quantity_in - quantity_out\n                    ELSE 0\n                END) as adjustments_qty,\n                SUM(CASE\n                    WHEN reference_type = 'front_stock_daily_closing' THEN quantity_in - quantity_out\n                    ELSE 0\n                END) as closing_adjustment_qty\n            ")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');
    }

    private function movementRows(string $companyId, string $branchId, string $stockArea, string $day, $productInfo): array
    {
        return DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $day)
            ->where(function ($q) use ($stockArea) {
                $q->where('stock_area', $stockArea)
                    ->orWhere('from_stock_area', $stockArea)
                    ->orWhere('to_stock_area', $stockArea);
            })
            ->orderBy('created_at')
            ->get()
            ->map(function ($row) use ($day, $productInfo) {
                $product = $productInfo->get($row->product_id);

                return [
                    'movement_date' => $day,
                    'created_at' => $row->created_at,
                    'product_id' => (string) $row->product_id,
                    'product_name' => $product->name ?? 'Product',
                    'product_code' => $product->code ?? null,
                    'movement_type' => $row->movement_type,
                    'stock_area' => $row->stock_area,
                    'from_stock_area' => $row->from_stock_area,
                    'to_stock_area' => $row->to_stock_area,
                    'quantity_in' => round((float) $row->quantity_in, 6),
                    'quantity_out' => round((float) $row->quantity_out, 6),
                    'net_quantity' => round((float) $row->quantity_in - (float) $row->quantity_out, 6),
                    'balance_after' => $row->balance_after !== null ? round((float) $row->balance_after, 6) : null,
                    'unit_cost' => round((float) $row->unit_cost, 6),
                    'total_cost' => round((float) $row->total_cost, 6),
                    'currency_code' => $row->currency_code ?: ($row->currency ?: 'USD'),
                    'reference_type' => $row->reference_type,
                    'reference_id' => $row->reference_id,
                    'notes' => $row->notes,
                ];
            })
            ->values()
            ->all();
    }

    private function previousClosingMap(string $companyId, string $branchId, string $stockArea, string $day, array $productIds)
    {
        if (empty($productIds)) {
            return collect();
        }

        /*
         |--------------------------------------------------------------------------
         | Previous closing = opening stock
         |--------------------------------------------------------------------------
         | Avoid raw SQL subqueries here because MariaDB can fail when escaped \n
         | characters are passed through DB::raw(). We fetch all previous closing
         | lines ordered newest first, then keep the first row for each product.
         */
        $rows = DB::table('front_stock_daily_closing_lines as l')
            ->join('front_stock_daily_closings as c', 'c.id', '=', 'l.front_stock_daily_closing_id')
            ->where('c.company_id', $companyId)
            ->where('c.branch_id', $branchId)
            ->where('c.stock_area', $stockArea)
            ->where('c.status', 'closed')
            ->whereDate('c.closing_date', '<', $day)
            ->whereIn('l.product_id', $productIds)
            ->select('l.product_id', 'l.counted_quantity', 'c.closing_date')
            ->orderByDesc('c.closing_date')
            ->orderByDesc('c.created_at')
            ->get();

        $map = collect();

        foreach ($rows as $row) {
            $productId = (string) $row->product_id;

            if (!$map->has($productId)) {
                $map->put($productId, $row);
            }
        }

        return $map;
    }

    private function currencyFromClosingLine($line): ?string
    {
        if (!$line) {
            return null;
        }

        $metadata = $line->metadata ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        return $metadata['currency_code'] ?? $metadata['currency'] ?? null;
    }

    private function roundTotals(array $totals): array
    {
        foreach ($totals as $key => $value) {
            if ($key === 'variance_value_by_currency') {
                foreach ($value as $currency => $amount) {
                    $totals[$key][$currency] = round((float) $amount, 6);
                }
                continue;
            }
            if (is_numeric($value)) {
                $totals[$key] = round((float) $value, 6);
            }
        }
        return $totals;
    }

    private function fileName(array $payload, string $extension): string
    {
        $dates = collect($payload['days'] ?? [])->pluck('closing_date')->filter()->values();
        $datePart = $dates->count() === 1 ? $dates->first() : ($dates->first() . '_to_' . $dates->last());
        $datePart = $datePart ?: now()->toDateString();
        return 'closing-stock-movement-report-' . $datePart . '.' . $extension;
    }

    private function buildPdfHtml(array $payload): string
    {
        $styles = '<style>body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:10px;margin:16px}.title{font-size:20px;font-weight:600}.sub{color:#64748b;margin-top:2px}.rule{border-top:3px solid #172033;margin:10px 0 14px}.day{margin-top:14px;border:1px solid #cbd5e1}.dayh{background:#172033;color:white;padding:10px 12px}.dayh h2{margin:0;font-size:15px;font-weight:600}.cards{display:table;width:100%;border-collapse:collapse}.card{display:table-cell;border:1px solid #cbd5e1;padding:8px;background:#f8fafc}.label{color:#64748b;font-size:8px;text-transform:uppercase}.value{font-size:13px;font-weight:600;margin-top:3px}table{width:100%;border-collapse:collapse}th{background:#edf2f7;text-align:left;font-size:8px;text-transform:uppercase;color:#334155;padding:6px;border:1px solid #d9e2ec}td{padding:6px;border:1px solid #e2e8f0}td.num{text-align:right;font-variant-numeric:tabular-nums}.neg{color:#b42318;font-weight:600}.pos{color:#027a48;font-weight:600}.mov{margin-top:10px}.small{font-size:8px;color:#64748b}</style>';
        $html = '<html><head><meta charset="UTF-8">' . $styles . '</head><body>';
        $html .= '<div class="title">Closing Stock Movement Report</div>';
        $html .= '<div class="sub">Generated: ' . e($payload['generated_at'] ?? now()->toDateTimeString()) . ' | Stock Area: ' . e(strtoupper($payload['stock_area'] ?? 'front')) . '</div><div class="rule"></div>';

        foreach ($payload['days'] ?? [] as $day) {
            $html .= '<section class="day"><div class="dayh"><h2>' . e($day['formatted_date']) . '</h2><div class="small">Lines: ' . e($day['total_lines']) . ' | Variance lines: ' . e($day['variance_lines']) . '</div></div>';
            $t = $day['totals'];
            $html .= '<div class="cards"><div class="card"><div class="label">Opening</div><div class="value">' . number_format($t['opening_qty'], 4) . '</div></div><div class="card"><div class="label">Receipts</div><div class="value">' . number_format($t['receipts_qty'], 4) . '</div></div><div class="card"><div class="label">BOH to Front</div><div class="value">' . number_format($t['boh_to_front_qty'], 4) . '</div></div><div class="card"><div class="label">Expected</div><div class="value">' . number_format($t['expected_closing_qty'], 4) . '</div></div><div class="card"><div class="label">Counted</div><div class="value">' . number_format($t['counted_closing_qty'], 4) . '</div></div><div class="card"><div class="label">Variance</div><div class="value">' . number_format($t['variance_qty'], 4) . '</div></div></div>';
            $html .= '<table><thead><tr><th>Product</th><th>UOM</th><th>Opening</th><th>Receipts</th><th>BOH Front</th><th>Sales</th><th>Issues</th><th>Adjust.</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Value</th></tr></thead><tbody>';
            foreach ($day['lines'] as $line) {
                $varianceClass = ($line['variance_qty'] ?? 0) < 0 ? 'neg' : (($line['variance_qty'] ?? 0) > 0 ? 'pos' : '');
                $html .= '<tr><td><b>' . e($line['product_name']) . '</b><br><span class="small">' . e($line['product_code'] ?? $line['product_sku'] ?? '') . '</span></td><td>' . e($line['uom_code']) . '</td><td class="num">' . number_format($line['opening_qty'], 4) . '</td><td class="num">' . number_format($line['receipts_qty'], 4) . '</td><td class="num">' . number_format($line['boh_to_front_qty'], 4) . '</td><td class="num">' . number_format($line['sales_consumption_qty'], 4) . '</td><td class="num">' . number_format($line['other_issues_qty'], 4) . '</td><td class="num">' . number_format($line['adjustments_qty'], 4) . '</td><td class="num">' . number_format($line['expected_closing_qty'], 4) . '</td><td class="num">' . ($line['counted_closing_qty'] === null ? '-' : number_format($line['counted_closing_qty'], 4)) . '</td><td class="num ' . $varianceClass . '">' . ($line['variance_qty'] === null ? '-' : number_format($line['variance_qty'], 4)) . '</td><td class="num ' . $varianceClass . '">' . e($line['currency_code']) . ' ' . ($line['variance_value'] === null ? '-' : number_format($line['variance_value'], 2)) . '</td></tr>';
            }
            $html .= '</tbody></table></section>';
        }

        $html .= '<div class="mov"><h3>Movement Detail</h3><table><thead><tr><th>Date/Time</th><th>Product</th><th>Type</th><th>Area</th><th>In</th><th>Out</th><th>Balance</th><th>Cost</th><th>Reference</th></tr></thead><tbody>';
        foreach ($payload['movement_detail'] ?? [] as $row) {
            $html .= '<tr><td>' . e($row['created_at']) . '</td><td>' . e($row['product_name']) . '</td><td>' . e($row['movement_type']) . '</td><td>' . e($row['stock_area'] ?: ($row['from_stock_area'] . '->' . $row['to_stock_area'])) . '</td><td class="num">' . number_format($row['quantity_in'], 4) . '</td><td class="num">' . number_format($row['quantity_out'], 4) . '</td><td class="num">' . ($row['balance_after'] === null ? '-' : number_format($row['balance_after'], 4)) . '</td><td class="num">' . e($row['currency_code']) . ' ' . number_format($row['total_cost'], 2) . '</td><td>' . e($row['reference_type']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></body></html>';

        return $html;
    }

    private function companyArray($company): array
    {
        return [
            'id' => $company->id ?? null,
            'name' => $company->company_name ?? $company->name ?? null,
        ];
    }

    private function resolveCompany(Request $request): mixed
    {
        if (app()->bound('company')) {
            return app('company');
        }
        return $request->attributes->get('company') ?? $request->company ?? null;
    }

    private function resolveTill(Request $request): mixed
    {
        if (app()->bound('till')) {
            return app('till');
        }
        return $request->attributes->get('till') ?? $request->till ?? null;
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
