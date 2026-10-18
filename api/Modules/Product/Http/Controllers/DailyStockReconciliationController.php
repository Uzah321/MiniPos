<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\Sales\SalesCostingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Models\DailyStockReconciliationLine;
use Modules\Product\Models\DailyStockReconciliationReport;
use Modules\Product\Models\FrontStockDailyClosing;
use Modules\Product\Models\TradingDayEnd;

class DailyStockReconciliationController extends Controller
{
    private const ALLOWED_PRODUCT_TYPES = [
        'raw_material',
        'raw material',
        'semi_finished_good',
        'semi finished good',
        'semi-finished good',
        'semi finished',
        'semi_finished',
        'consumable',
        'consumables',
        'packaging',
    ];

    public function preview(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'trading_date' => ['required', 'date'],
            'stock_area' => ['nullable', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $tradingDate = Carbon::parse($request->input('trading_date'))->toDateString();
        $stockArea = $request->input('stock_area', 'front');

        $closing = $this->findClosing($company->id, $branchId, $tradingDate, $stockArea);

        return response()->json([
            'data' => $this->buildReportData($company->id, $branchId, $tradingDate, $stockArea),
            'can_generate_final' => (bool) $closing,
            'message' => $closing
                ? 'Preview ready.'
                : 'Manual daily front stock closing has not been saved for this trading date yet.',
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);

        if (!$company) {
            return response()->json(['message' => 'Company could not be resolved.'], 422);
        }

        $report = DailyStockReconciliationReport::query()
            ->where('company_id', $company->id)
            ->with(['lines' => fn ($query) => $query->orderBy('product_name')])
            ->findOrFail($id);

        return response()->json(['data' => $report]);
    }

    public function generate(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'trading_date' => ['required', 'date'],
            'stock_area' => ['nullable', 'string', 'max:30'],
            'trading_day_end_id' => ['nullable', 'string'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $tradingDate = Carbon::parse($request->input('trading_date'))->toDateString();
        $stockArea = $request->input('stock_area', 'front');
        $replaceExisting = filter_var($request->input('replace_existing', true), FILTER_VALIDATE_BOOLEAN);

        $closing = $this->findClosing($company->id, $branchId, $tradingDate, $stockArea);

        if (!$closing) {
            return response()->json([
                'message' => 'Manual daily front stock closing is required before the DSR report can be generated.',
            ], 422);
        }

        return DB::transaction(function () use ($company, $branchId, $tradingDate, $stockArea, $request, $replaceExisting) {
            $reportData = $this->buildReportData($company->id, $branchId, $tradingDate, $stockArea);

            $existing = DailyStockReconciliationReport::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->whereDate('trading_date', $tradingDate)
                ->where('stock_area', $stockArea)
                ->first();

            if ($existing && !$replaceExisting) {
                return response()->json([
                    'message' => 'Daily stock reconciliation report already exists for this trading date.',
                    'data' => $existing,
                ], 409);
            }

            if ($existing) {
                $existing->lines()->delete();
                $report = $existing;
                $report->update([
                    'trading_day_end_id' => $request->input('trading_day_end_id'),
                    'status' => 'generated',
                    'summary' => $reportData['summary'],
                    'metadata' => $reportData['metadata'],
                ]);
            } else {
                $report = DailyStockReconciliationReport::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'trading_date' => $tradingDate,
                    'trading_day_end_id' => $request->input('trading_day_end_id'),
                    'stock_area' => $stockArea,
                    'status' => 'generated',
                    'summary' => $reportData['summary'],
                    'metadata' => $reportData['metadata'],
                ]);
            }

            $varianceLines = 0;
            $totalVarianceQty = 0;
            $totalVarianceValue = 0;

            foreach ($reportData['lines'] as $line) {
                $varianceQty = round((float) $line['variance_quantity'], 6);
                $varianceValue = round((float) $line['variance_value'], 6);

                if (abs($varianceQty) > 0.000001) {
                    $varianceLines++;
                }

                $totalVarianceQty += $varianceQty;
                $totalVarianceValue += $varianceValue;

                DailyStockReconciliationLine::create([
                    'id' => (string) Str::uuid(),
                    'daily_stock_reconciliation_report_id' => $report->id,
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'trading_date' => $tradingDate,
                    'product_id' => $line['product_id'],
                    'product_code' => $line['product_code'],
                    'product_sku' => $line['product_sku'],
                    'product_name' => $line['product_name'],
                    'product_type' => $line['product_type'],
                    'uom_code' => $line['uom_code'],
                    'currency_code' => $line['currency_code'],
                    'opening_quantity' => $line['opening_quantity'],
                    'purchase_receipt_quantity' => $line['purchase_receipt_quantity'],
                    'boh_to_front_quantity' => $line['boh_to_front_quantity'],
                    'sales_consumption_quantity' => $line['sales_consumption_quantity'],
                    'adjustment_quantity' => $line['adjustment_quantity'],
                    'expected_closing_quantity' => $line['expected_closing_quantity'],
                    'counted_closing_quantity' => $line['counted_closing_quantity'],
                    'variance_quantity' => $line['variance_quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'variance_value' => $line['variance_value'],
                    'movement_breakdown' => $line['movement_breakdown'],
                    'metadata' => $line['metadata'],
                ]);
            }

            $report->update([
                'total_lines' => count($reportData['lines']),
                'variance_lines' => $varianceLines,
                'total_variance_quantity' => round($totalVarianceQty, 6),
                'total_variance_value' => round($totalVarianceValue, 6),
                'summary' => array_merge($reportData['summary'], [
                    'variance_lines' => $varianceLines,
                    'total_variance_quantity' => round($totalVarianceQty, 6),
                    'total_variance_value' => round($totalVarianceValue, 6),
                ]),
            ]);

            return response()->json([
                'message' => 'Daily stock reconciliation report generated successfully.',
                'data' => $report->fresh(['lines' => fn ($query) => $query->orderBy('product_name')]),
            ], 201);
        });
    }

    public function exportExcel(Request $request): mixed
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $tradingDate = Carbon::parse($request->input('trading_date', now()->toDateString()))->toDateString();
        $stockArea = $request->input('stock_area', 'front');
        $data = $this->buildReportData($company->id, $branchId, $tradingDate, $stockArea);
        $xml = $this->buildExcelXml($data);
        $filename = 'Daily_Stock_Reconciliation_' . $tradingDate . '.xls';

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function buildReportData(string $companyId, string $branchId, string $tradingDate, string $stockArea): array
    {
        $date = Carbon::parse($tradingDate);
        $previousClosing = FrontStockDailyClosing::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_area', $stockArea)
            ->whereDate('closing_date', '<', $tradingDate)
            ->latest('closing_date')
            ->with('lines')
            ->first();

        $todayClosing = $this->findClosing($companyId, $branchId, $tradingDate, $stockArea);
        $products = $this->getAllowedProducts($companyId);
        $openingByProduct = collect($previousClosing?->lines ?? [])->keyBy(fn ($line) => (string) $line->product_id);
        $closingByProduct = collect($todayClosing?->lines ?? [])->keyBy(fn ($line) => (string) $line->product_id);
        $movementByProduct = $this->getMovementBreakdown($companyId, $branchId, $date, $stockArea);

        $productIds = collect($products)->pluck('product_id')
            ->merge($openingByProduct->keys())
            ->merge($closingByProduct->keys())
            ->merge(array_keys($movementByProduct))
            ->unique()
            ->values();

        $lines = [];
        foreach ($productIds as $productId) {
            $product = collect($products)->firstWhere('product_id', $productId);
            $opening = $openingByProduct->get((string) $productId);
            $closing = $closingByProduct->get((string) $productId);
            $movements = $movementByProduct[(string) $productId] ?? $this->blankMovementBreakdown();

            $openingQty = round((float) ($opening?->counted_quantity ?? 0), 6);
            $purchaseReceiptQty = round((float) $movements['purchase_receipt_quantity'], 6);
            $bohToFrontQty = round((float) $movements['boh_to_front_quantity'], 6);
            $salesConsumptionQty = round((float) $movements['sales_consumption_quantity'], 6);
            $adjustmentQty = round((float) $movements['adjustment_quantity'], 6);
            $expectedClosingQty = round($openingQty + $purchaseReceiptQty + $bohToFrontQty - $salesConsumptionQty + $adjustmentQty, 6);
            $countedClosingQty = round((float) ($closing?->counted_quantity ?? 0), 6);
            $varianceQty = round($countedClosingQty - $expectedClosingQty, 6);
            $unitCost = round((float) ($closing?->unit_cost ?? $product['unit_cost'] ?? $opening?->unit_cost ?? 0), 6);
            $varianceValue = round($varianceQty * $unitCost, 6);

            $lines[] = [
                'product_id' => (string) $productId,
                'product_name' => $product['product_name'] ?? $closing?->product_name ?? $opening?->product_name ?? 'Product',
                'product_code' => $product['product_code'] ?? $closing?->product_code ?? $opening?->product_code,
                'product_sku' => $product['product_sku'] ?? $closing?->product_sku ?? $opening?->product_sku,
                'product_type' => $product['product_type'] ?? $closing?->product_type ?? $opening?->product_type,
                'uom_code' => $product['uom_code'] ?? $closing?->uom_code ?? $opening?->uom_code ?? 'EACH',
                'currency_code' => $product['currency_code'] ?? $closing?->currency_code ?? 'USD',
                'opening_quantity' => $openingQty,
                'purchase_receipt_quantity' => $purchaseReceiptQty,
                'boh_to_front_quantity' => $bohToFrontQty,
                'sales_consumption_quantity' => $salesConsumptionQty,
                'adjustment_quantity' => $adjustmentQty,
                'expected_closing_quantity' => $expectedClosingQty,
                'counted_closing_quantity' => $countedClosingQty,
                'variance_quantity' => $varianceQty,
                'unit_cost' => $unitCost,
                'variance_value' => $varianceValue,
                'movement_breakdown' => $movements,
                'metadata' => [
                    'previous_closing_id' => $previousClosing?->id,
                    'daily_closing_id' => $todayClosing?->id,
                    'stock_area' => $stockArea,
                ],
            ];
        }

        usort($lines, fn ($a, $b) => strcmp((string) $a['product_name'], (string) $b['product_name']));

        $summary = [
            'trading_date' => $tradingDate,
            'previous_closing_date' => $previousClosing?->closing_date?->toDateString(),
            'has_manual_closing' => (bool) $todayClosing,
            'line_count' => count($lines),
            'variance_lines' => collect($lines)->filter(fn ($line) => abs((float) $line['variance_quantity']) > 0.000001)->count(),
            'total_variance_quantity' => round(collect($lines)->sum('variance_quantity'), 6),
            'total_variance_value' => round(collect($lines)->sum('variance_value'), 6),
        ];

        return [
            'trading_date' => $tradingDate,
            'branch_id' => $branchId,
            'stock_area' => $stockArea,
            'summary' => $summary,
            'metadata' => [
                'source' => 'daily_stock_reconciliation_report',
                'opening_stock_source' => 'previous_front_stock_daily_closing_lines',
                'closing_stock_source' => 'front_stock_daily_closing_lines',
                'generated_at' => now()->toISOString(),
            ],
            'lines' => $lines,
        ];
    }

    private function getAllowedProducts(string $companyId): array
    {
        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('pc') ?: 'NULL';

        return DB::table('products as p')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('product_costs as pc', function ($join) use ($companyId) {
                $join->on('pc.product_id', '=', 'p.id')->where('pc.company_id', '=', $companyId);
            })
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->whereIn(DB::raw('LOWER(p.product_type)'), self::ALLOWED_PRODUCT_TYPES)
            ->selectRaw("
                p.id as product_id,
                p.name as product_name,
                p.code as product_code,
                p.sku as product_sku,
                p.product_type as product_type,
                COALESCE(u.code, u.name, 'EACH') as uom_code,
                COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost,
                COALESCE(MAX({$costCurrency}), 'USD') as currency_code
            ")
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.product_type', 'u.code', 'u.name')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (string) $row->product_id,
                'product_name' => $row->product_name,
                'product_code' => $row->product_code,
                'product_sku' => $row->product_sku,
                'product_type' => $row->product_type,
                'uom_code' => $row->uom_code ?: 'EACH',
                'unit_cost' => round((float) $row->unit_cost, 6),
                'currency_code' => $row->currency_code ?: 'USD',
            ])
            ->all();
    }

    private function getMovementBreakdown(string $companyId, string $branchId, Carbon $date, string $stockArea): array
    {
        if (!Schema::hasTable('stock_movements')) {
            return [];
        }

        $columns = collect(Schema::getColumnListing('stock_movements'));

        if (
            !$columns->contains('product_id') ||
            !$columns->contains('quantity_in') ||
            !$columns->contains('quantity_out')
        ) {
            return [];
        }

        $dateColumn = $columns->contains('movement_date') ? 'movement_date' : 'created_at';
        $typeColumn = $columns->contains('movement_type') ? 'movement_type' : null;
        $reasonColumn = $columns->contains('movement_reason') ? 'movement_reason' : null;
        $hasStockArea = $columns->contains('stock_area');
        $hasFromStockArea = $columns->contains('from_stock_area');
        $hasToStockArea = $columns->contains('to_stock_area');

        $rows = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereBetween($dateColumn, [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->where(function ($query) use ($stockArea, $hasStockArea, $hasFromStockArea, $hasToStockArea) {
                $applied = false;

                if ($hasStockArea) {
                    $query->where('stock_area', $stockArea);
                    $applied = true;
                }

                if ($hasFromStockArea) {
                    $applied
                        ? $query->orWhere('from_stock_area', $stockArea)
                        : $query->where('from_stock_area', $stockArea);
                    $applied = true;
                }

                if ($hasToStockArea) {
                    $applied
                        ? $query->orWhere('to_stock_area', $stockArea)
                        : $query->where('to_stock_area', $stockArea);
                }
            })
            ->select('*')
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            $productId = (string) $row->product_id;
            $breakdown[$productId] ??= $this->blankMovementBreakdown();

            $quantityIn = round((float) ($row->quantity_in ?? 0), 6);
            $quantityOut = round((float) ($row->quantity_out ?? 0), 6);
            $netQuantity = round($quantityIn - $quantityOut, 6);
            $type = strtolower((string) ($typeColumn ? ($row->{$typeColumn} ?? '') : ''));
            $reason = strtolower((string) ($reasonColumn ? ($row->{$reasonColumn} ?? '') : ''));
            $metadata = strtolower((string) ($row->metadata ?? ''));
            $fromArea = strtolower((string) ($row->from_stock_area ?? ''));
            $toArea = strtolower((string) ($row->to_stock_area ?? ''));
            $searchable = trim($type . ' ' . $reason . ' ' . $metadata . ' ' . $fromArea . ' ' . $toArea);

            if (
                str_contains($searchable, 'recipe_front_stock_consumption') ||
                str_contains($searchable, 'sale_recipe') ||
                $type === 'sale'
            ) {
                $breakdown[$productId]['sales_consumption_quantity'] += $quantityOut;
                $breakdown[$productId]['sales_movement_count']++;
                continue;
            }

            if (
                $type === 'boh_issue_to_front' ||
                str_contains($searchable, 'boh_to_front') ||
                str_contains($searchable, 'issue_to_front') ||
                str_contains($searchable, 'transfer_to_front') ||
                str_contains($searchable, 'front_transfer') ||
                $toArea === strtolower($stockArea)
            ) {
                $breakdown[$productId]['boh_to_front_quantity'] += $quantityIn;
                $breakdown[$productId]['transfer_movement_count']++;
                continue;
            }

            if (
                str_contains($searchable, 'receipt') ||
                str_contains($searchable, 'purchase') ||
                str_contains($searchable, 'grn') ||
                in_array($type, ['receipt', 'purchase', 'purchase_receipt', 'stock_receipt'], true)
            ) {
                $breakdown[$productId]['purchase_receipt_quantity'] += $quantityIn;
                $breakdown[$productId]['receipt_movement_count']++;
                continue;
            }

            if (
                str_contains($searchable, 'adjust') ||
                str_contains($searchable, 'closing') ||
                str_contains($searchable, 'variance') ||
                in_array($type, ['stock_take', 'adjustment', 'stock_adjustment', 'sale_cancel'], true)
            ) {
                $breakdown[$productId]['adjustment_quantity'] += $netQuantity;
                $breakdown[$productId]['adjustment_movement_count']++;
                continue;
            }

            if (in_array($type, ['issue', 'wastage', 'write_off'], true)) {
                $breakdown[$productId]['other_issues_quantity'] += $quantityOut;
                $breakdown[$productId]['other_issue_movement_count']++;
                continue;
            }
        }

        return $breakdown;
    }

    private function blankMovementBreakdown(): array
    {
        return [
            'purchase_receipt_quantity' => 0,
            'boh_to_front_quantity' => 0,
            'sales_consumption_quantity' => 0,
            'adjustment_quantity' => 0,
            'receipt_movement_count' => 0,
            'transfer_movement_count' => 0,
            'sales_movement_count' => 0,
            'adjustment_movement_count' => 0,
            'other_issues_quantity' => 0,
            'other_issue_movement_count' => 0,
        ];
    }

    private function findClosing(string $companyId, string $branchId, string $tradingDate, string $stockArea): ?FrontStockDailyClosing
    {
        return FrontStockDailyClosing::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $tradingDate)
            ->where('stock_area', $stockArea)
            ->with('lines')
            ->first();
    }

    private function buildExcelXml(array $data): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $rows = '';
        $headers = [
            'Product Code', 'SKU', 'Product Name', 'Type', 'UOM', 'Opening', 'Purchases/Receipts',
            'BOH To Front', 'Sales Consumption', 'Adjustments', 'Expected Closing', 'Manual Count',
            'Variance Qty', 'Currency', 'Unit Cost', 'Variance Value', 'Movement Counts',
        ];

        $rows .= '<Row ss:StyleID="Header">';
        foreach ($headers as $header) {
            $rows .= '<Cell><Data ss:Type="String">' . $e($header) . '</Data></Cell>';
        }
        $rows .= '</Row>';

        foreach ($data['lines'] as $line) {
            $movementCounts = 'R:' . ($line['movement_breakdown']['receipt_movement_count'] ?? 0)
                . ' T:' . ($line['movement_breakdown']['transfer_movement_count'] ?? 0)
                . ' S:' . ($line['movement_breakdown']['sales_movement_count'] ?? 0)
                . ' A:' . ($line['movement_breakdown']['adjustment_movement_count'] ?? 0);

            $rows .= '<Row>';
            foreach ([
                         $line['product_code'], $line['product_sku'], $line['product_name'], $line['product_type'], $line['uom_code'],
                         $line['opening_quantity'], $line['purchase_receipt_quantity'], $line['boh_to_front_quantity'],
                         $line['sales_consumption_quantity'], $line['adjustment_quantity'], $line['expected_closing_quantity'],
                         $line['counted_closing_quantity'], $line['variance_quantity'], $line['currency_code'], $line['unit_cost'],
                         $line['variance_value'], $movementCounts,
                     ] as $value) {
                $type = is_numeric($value) ? 'Number' : 'String';
                $rows .= '<Cell><Data ss:Type="' . $type . '">' . $e($value) . '</Data></Cell>';
            }
            $rows .= '</Row>';
        }

        $summary = $data['summary'];

        return '<?xml version="1.0"?>' . "\n" .
            '<?mso-application progid="Excel.Sheet"?>' . "\n" .
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
            '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/></Style></Styles>' .
            '<Worksheet ss:Name="DSR Summary"><Table>' .
            '<Row><Cell ss:StyleID="Title"><Data ss:Type="String">Daily Stock Reconciliation</Data></Cell></Row>' .
            '<Row><Cell><Data ss:Type="String">Trading Date</Data></Cell><Cell><Data ss:Type="String">' . $e($data['trading_date']) . '</Data></Cell></Row>' .
            '<Row><Cell><Data ss:Type="String">Previous Closing Date</Data></Cell><Cell><Data ss:Type="String">' . $e($summary['previous_closing_date'] ?? '') . '</Data></Cell></Row>' .
            '<Row><Cell><Data ss:Type="String">Variance Lines</Data></Cell><Cell><Data ss:Type="Number">' . $e($summary['variance_lines'] ?? 0) . '</Data></Cell></Row>' .
            '<Row><Cell><Data ss:Type="String">Total Variance Value</Data></Cell><Cell><Data ss:Type="Number">' . $e($summary['total_variance_value'] ?? 0) . '</Data></Cell></Row>' .
            '</Table></Worksheet>' .
            '<Worksheet ss:Name="DSR Detail"><Table>' . $rows . '</Table></Worksheet>' .
            '</Workbook>';
    }

    private function resolveCompany(Request $request): mixed
    {
        return app()->bound('company') ? app('company') : ($request->attributes->get('company') ?? $request->company ?? null);
    }

    private function resolveTill(Request $request): mixed
    {
        return app()->bound('till') ? app('till') : ($request->attributes->get('till') ?? $request->till ?? null);
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
