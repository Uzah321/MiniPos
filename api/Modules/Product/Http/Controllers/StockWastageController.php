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
use Modules\Product\Exports\StockWastageSpreadsheetExport;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockWastageController extends Controller
{
    private const ALLOWED_WASTAGE_PRODUCT_TYPES = [
        'raw_material',
        'raw material',
        'semi_finished_good',
        'semi finished good',
        'semi-finished good',
        'semi finished',
        'semi_finished',
        'packaging',
        'consumable',
        'consumables',
    ];

    public function productLookup(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $stockArea = $request->input('stock_area', 'front');
        $search = trim((string) $request->input('search', ''));

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('pc') ?: 'NULL';

        $rows = DB::table('products as p')
            ->leftJoin('product_stocks as ps', function ($join) use ($company, $branchId, $stockArea) {
                $join->on('ps.product_id', '=', 'p.id')
                    ->where('ps.company_id', '=', $company->id)
                    ->where('ps.branch_id', '=', $branchId)
                    ->where('ps.stock_area', '=', $stockArea);
            })
            ->leftJoin('product_costs as pc', function ($join) use ($company, $branchId) {
                $join->on('pc.product_id', '=', 'p.id')
                    ->where('pc.company_id', '=', $company->id)
                    ->where(function ($q) use ($branchId) {
                        $q->whereNull('pc.branch_id')->orWhere('pc.branch_id', '=', $branchId);
                    });
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->where('p.company_id', $company->id)
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                $q->where('p.is_active', true)->orWhereNull('p.is_active');
            })
            ->whereIn(DB::raw('LOWER(TRIM(p.product_type))'), self::ALLOWED_WASTAGE_PRODUCT_TYPES)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('p.name', 'like', $like)
                        ->orWhere('p.code', 'like', $like)
                        ->orWhere('p.sku', 'like', $like)
                        ->orWhere('p.barcode', 'like', $like);
                });
            })
            ->selectRaw("
                p.id as product_id,
                p.name as product_name,
                p.code as product_code,
                p.sku as product_sku,
                p.product_type as product_type,
                COALESCE(u.code, u.name, 'EACH') as uom_code,
                COALESCE(SUM(ps.quantity_on_hand), 0) as quantity_on_hand,
                COALESCE(SUM(ps.available_quantity), 0) as available_quantity,
                COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost,
                COALESCE(MAX({$costCurrency}), 'USD') as currency_code
            ")
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.product_type', 'u.code', 'u.name')
            ->orderBy('p.name')
            ->limit((int) $request->input('limit', 80))
            ->get()
            ->map(fn ($row) => [
                'product_id' => (string) $row->product_id,
                'product_name' => $row->product_name,
                'product_code' => $row->product_code,
                'product_sku' => $row->product_sku,
                'product_type' => $row->product_type,
                'uom_code' => $row->uom_code ?: 'EACH',
                'stock_area' => $stockArea,
                'quantity_on_hand' => round((float) $row->quantity_on_hand, 6),
                'available_quantity' => round((float) $row->available_quantity, 6),
                'unit_cost' => round((float) $row->unit_cost, 6),
                'currency_code' => $row->currency_code ?: 'USD',
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $till = $this->resolveTill($request);
        $user = auth()->user();

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'wastage_date' => ['required', 'date'],
            'stock_area' => ['nullable', 'string', 'max:30'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.reason_code' => ['nullable', 'string', 'max:80'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $wastageDate = Carbon::parse($request->input('wastage_date'))->toDateString();
        $stockArea = $request->input('stock_area', 'front');
        $referenceNo = 'WST-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

        DB::beginTransaction();
        try {
            $wastageId = (string) Str::uuid();
            DB::table('stock_wastages')->insert($this->filterColumns('stock_wastages', [
                'id' => $wastageId,
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'till_id' => $till?->id,
                'created_by' => $user?->id,
                'wastage_date' => $wastageDate,
                'stock_area' => $stockArea,
                'reference_no' => $referenceNo,
                'status' => 'posted',
                'reason_code' => $request->input('reason_code'),
                'notes' => $request->input('notes'),
                'metadata' => json_encode(['source' => 'stock_wastage_module']),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $summary = ['total_lines' => 0, 'total_quantity' => 0, 'total_cost' => 0, 'currency_totals' => []];
            foreach ($request->input('items', []) as $item) {
                $this->postWastageLine($company->id, $branchId, $till?->id, $user?->id, $wastageId, $wastageDate, $stockArea, $item, $summary);
            }

            DB::table('stock_wastages')->where('id', $wastageId)->update($this->filterColumns('stock_wastages', [
                'total_lines' => $summary['total_lines'],
                'total_quantity' => round($summary['total_quantity'], 6),
                'total_cost' => round($summary['total_cost'], 6),
                'currency_totals' => json_encode(array_values($summary['currency_totals'])),
                'updated_at' => now(),
            ]));

            DB::commit();
            return response()->json(['message' => 'Stock wastage posted successfully.', 'data' => $this->getWastage($company->id, $wastageId)], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->buildReport($request);
        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) return response()->json(['message' => 'Company could not be resolved.'], 422);
        return response()->json(['data' => $this->getWastage($company->id, $id)]);
    }

    public function downloadExcel(Request $request)
    {
        $data = $this->buildReport($request);
        $spreadsheet = (new StockWastageSpreadsheetExport($data))->spreadsheet();
        $writer = new Xlsx($spreadsheet);
        $fileName = 'stock-wastage-report-' . ($data['date_from'] ?? 'from') . '-to-' . ($data['date_to'] ?? 'to') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function downloadPdf(Request $request)
    {
        $data = $this->buildReport($request);
        $html = $this->buildPdfHtml($data);
        $fileName = 'stock-wastage-report-' . ($data['date_from'] ?? 'from') . '-to-' . ($data['date_to'] ?? 'to') . '.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            return $pdf->download($fileName);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $fileName) . '"',
        ]);
    }

    private function postWastageLine(string $companyId, string $branchId, ?string $tillId, ?string $userId, string $wastageId, string $wastageDate, string $stockArea, array $item, array &$summary): void
    {
        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('pc') ?: 'NULL';
        $product = DB::table('products as p')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('product_costs as pc', function ($join) use ($companyId, $branchId) {
                $join->on('pc.product_id', '=', 'p.id')
                    ->where('pc.company_id', '=', $companyId)
                    ->where(function ($q) use ($branchId) {
                        $q->whereNull('pc.branch_id')->orWhere('pc.branch_id', '=', $branchId);
                    });
            })
            ->where('p.company_id', $companyId)
            ->where('p.id', $item['product_id'])
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                $q->where('p.is_active', true)->orWhereNull('p.is_active');
            })
            ->whereIn(DB::raw('LOWER(TRIM(p.product_type))'), self::ALLOWED_WASTAGE_PRODUCT_TYPES)
            ->selectRaw("p.id, p.name, p.code, p.sku, p.product_type, COALESCE(u.code,u.name,'EACH') as uom_code, COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost, COALESCE(MAX({$costCurrency}), 'USD') as currency_code")
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.product_type', 'u.code', 'u.name')
            ->first();

        if (!$product) throw new \Exception('Product not found or not allowed for wastage. Allowed: Raw Materials, Semi Finished Goods, Packaging and Consumables.');

        $stock = $this->getOrCreateStock($companyId, $branchId, (string) $product->id, $stockArea);
        $opening = round((float) ($stock->quantity_on_hand ?? 0), 6);
        $available = round((float) ($stock->available_quantity ?? $opening), 6);
        $quantity = round((float) ($item['quantity'] ?? 0), 6);
        if ($quantity > $available + 0.000001) {
            throw new \Exception('Insufficient ' . strtoupper($stockArea) . ' stock for ' . $product->name . '. Available: ' . $available);
        }

        $closing = round($opening - $quantity, 6);
        $unitCost = round((float) ($product->unit_cost ?? 0), 6);
        $currency = $product->currency_code ?: 'USD';
        $totalCost = round($quantity * $unitCost, 6);
        $reason = $item['reason_code'] ?? null;
        $movementType = $this->safeMovementType();

        DB::table('product_stocks')->where('id', $stock->id)->update($this->filterColumns('product_stocks', [
            'quantity_on_hand' => $closing,
            'available_quantity' => max(round($available - $quantity, 6), 0),
            'updated_at' => now(),
        ]));

        $movementId = (string) Str::uuid();
        DB::table('stock_movements')->insert($this->filterColumns('stock_movements', [
            'id' => $movementId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'product_id' => (string) $product->id,
            'stock_area' => $stockArea,
            'movement_type' => $movementType,
            'quantity_in' => 0,
            'quantity_out' => $quantity,
            'balance_after' => $closing,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'currency' => $currency,
            'currency_code' => $currency,
            'reference_type' => 'stock_wastage',
            'reference_id' => $wastageId,
            'notes' => trim(($reason ? '[' . $reason . '] ' : '') . (string) ($item['notes'] ?? 'Stock wastage')),
            'created_by' => $userId,
            'metadata' => json_encode([
                'source' => 'stock_wastage_module',
                'movement_reason' => 'stock_wastage',
                'wastage_id' => $wastageId,
                'wastage_date' => $wastageDate,
                'reason_code' => $reason,
                'opening_quantity' => $opening,
                'closing_quantity' => $closing,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        DB::table('stock_wastage_lines')->insert($this->filterColumns('stock_wastage_lines', [
            'id' => (string) Str::uuid(),
            'stock_wastage_id' => $wastageId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => (string) $product->id,
            'product_code' => $product->code,
            'product_sku' => $product->sku,
            'product_name' => $product->name,
            'product_type' => $product->product_type,
            'uom_code' => $product->uom_code ?: 'EACH',
            'stock_area' => $stockArea,
            'opening_quantity' => $opening,
            'wastage_quantity' => $quantity,
            'closing_quantity' => $closing,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'currency_code' => $currency,
            'reason_code' => $reason,
            'notes' => $item['notes'] ?? null,
            'stock_movement_id' => $movementId,
            'metadata' => json_encode(['movement_type_used' => $movementType]),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $summary['total_lines']++;
        $summary['total_quantity'] += $quantity;
        $summary['total_cost'] += $totalCost;
        $summary['currency_totals'][$currency] ??= ['currency_code' => $currency, 'line_count' => 0, 'total_quantity' => 0, 'total_cost' => 0];
        $summary['currency_totals'][$currency]['line_count']++;
        $summary['currency_totals'][$currency]['total_quantity'] += $quantity;
        $summary['currency_totals'][$currency]['total_cost'] += $totalCost;
    }

    private function buildReport(Request $request): array
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        if (!$company || !$branchId) return ['message' => 'Company or branch could not be resolved.', 'days' => [], 'lines' => []];

        $dateFrom = Carbon::parse($request->input('date_from', now()->toDateString()))->toDateString();
        $dateTo = Carbon::parse($request->input('date_to', $dateFrom))->toDateString();
        $stockArea = $request->input('stock_area');
        $search = trim((string) $request->input('search', ''));
        $reason = trim((string) $request->input('reason_code', ''));

        $query = DB::table('stock_wastage_lines as l')
            ->join('stock_wastages as w', 'w.id', '=', 'l.stock_wastage_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.created_by')
            ->where('w.company_id', $company->id)
            ->where('w.branch_id', $branchId)
            ->whereBetween(DB::raw('DATE(w.wastage_date)'), [$dateFrom, $dateTo])
            ->when($stockArea, fn ($q) => $q->where('l.stock_area', $stockArea))
            ->when($reason !== '', fn ($q) => $q->where('l.reason_code', $reason))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($x) use ($like) {
                    $x->where('l.product_name', 'like', $like)->orWhere('l.product_code', 'like', $like)->orWhere('l.product_sku', 'like', $like)->orWhere('w.reference_no', 'like', $like);
                });
            })
            ->selectRaw('w.id as wastage_id, w.reference_no, w.wastage_date, w.stock_area as header_stock_area, w.reason_code as header_reason, w.notes as header_notes, w.created_at, COALESCE(u.name,u.email,"-") as created_by_name, l.*')
            ->orderBy('w.wastage_date')
            ->orderBy('l.product_name');

        $lines = $query->get()->map(fn ($row) => (array) $row)->values()->all();
        $days = collect($lines)->groupBy('wastage_date')->map(function ($items, $date) {
            $currencyTotals = $items->groupBy('currency_code')->map(fn ($rows, $currency) => [
                'currency_code' => $currency ?: 'USD',
                'line_count' => $rows->count(),
                'total_quantity' => round($rows->sum('wastage_quantity'), 6),
                'total_cost' => round($rows->sum('total_cost'), 6),
            ])->values()->all();
            $reasonTotals = $items->groupBy('reason_code')->map(fn ($rows, $reason) => [
                'reason_code' => $reason ?: 'UNSPECIFIED',
                'line_count' => $rows->count(),
                'total_quantity' => round($rows->sum('wastage_quantity'), 6),
                'total_cost' => round($rows->sum('total_cost'), 6),
            ])->values()->all();
            return [
                'wastage_date' => $date,
                'line_count' => $items->count(),
                'total_quantity' => round($items->sum('wastage_quantity'), 6),
                'total_cost' => round($items->sum('total_cost'), 6),
                'currency_totals' => $currencyTotals,
                'reason_totals' => $reasonTotals,
                'lines' => $items->values()->all(),
            ];
        })->values()->all();

        $currencyTotals = collect($lines)->groupBy('currency_code')->map(fn ($rows, $currency) => [
            'currency_code' => $currency ?: 'USD',
            'line_count' => $rows->count(),
            'total_quantity' => round($rows->sum('wastage_quantity'), 6),
            'total_cost' => round($rows->sum('total_cost'), 6),
        ])->values()->all();

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stock_area' => $stockArea ?: 'ALL',
            'generated_at' => now()->toDateTimeString(),
            'summary' => [
                'line_count' => count($lines),
                'wastage_days' => count($days),
                'total_quantity' => round(collect($lines)->sum('wastage_quantity'), 6),
                'currency_totals' => $currencyTotals,
            ],
            'days' => $days,
            'lines' => $lines,
        ];
    }

    private function getWastage(string $companyId, string $id): ?array
    {
        $header = DB::table('stock_wastages')->where('company_id', $companyId)->where('id', $id)->first();
        if (!$header) return null;
        $lines = DB::table('stock_wastage_lines')->where('stock_wastage_id', $id)->orderBy('product_name')->get();
        return ['header' => $header, 'lines' => $lines];
    }

    private function buildPdfHtml(array $data): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn ($v, $c = 'USD') => $e($c) . ' ' . number_format((float) $v, 2);
        $qty = fn ($v) => number_format((float) $v, 4);
        $html = '<html><head><style>body{font-family:Arial,sans-serif;font-size:10px;color:#172033}table{width:100%;border-collapse:collapse;margin-bottom:10px}th{background:#E2E8F0;text-align:left;font-size:8px;text-transform:uppercase}td,th{border:1px solid #CBD5E1;padding:5px}.title{background:#172033;color:#fff;padding:10px;font-size:16px;font-weight:bold}.section{background:#334155;color:#fff;font-weight:bold;padding:6px;margin-top:8px}.cards{display:flex;gap:6px;margin:8px 0}.card{border:1px solid #CBD5E1;padding:7px;background:#F8FAFC;min-width:120px}.muted{color:#64748B;font-size:8px}.right{text-align:right}.neg{color:#B42318;font-weight:bold}</style></head><body>';
        $html .= '<div class="title">STOCK WASTAGE REPORT</div><div class="cards"><div class="card"><div class="muted">Date Range</div><b>'.$e($data['date_from']).' to '.$e($data['date_to']).'</b></div><div class="card"><div class="muted">Lines</div><b>'.$e($data['summary']['line_count'] ?? 0).'</b></div><div class="card"><div class="muted">Days</div><b>'.$e($data['summary']['wastage_days'] ?? 0).'</b></div></div>';
        foreach (($data['summary']['currency_totals'] ?? []) as $ct) {
            $html .= '<div class="card"><div class="muted">Total Cost '.$e($ct['currency_code']).'</div><b>'.$money($ct['total_cost'], $ct['currency_code']).'</b></div>';
        }
        foreach (($data['days'] ?? []) as $day) {
            $html .= '<div class="section">Wastage Date: '.$e($day['wastage_date']).' | Qty '.$qty($day['total_quantity']).' | Cost '.collect($day['currency_totals'] ?? [])->map(fn($ct)=>$money($ct['total_cost'],$ct['currency_code']))->join(' / ').'</div>';
            $html .= '<table><thead><tr><th>Reference</th><th>Product</th><th>Area</th><th>Reason</th><th class="right">Opening</th><th class="right">Wasted</th><th class="right">Closing</th><th class="right">Unit Cost</th><th class="right">Total Cost</th><th>By</th><th>Notes</th></tr></thead><tbody>';
            foreach (($day['lines'] ?? []) as $line) {
                $html .= '<tr><td>'.$e($line['reference_no'] ?? '-').'</td><td><b>'.$e($line['product_name'] ?? 'Product').'</b><br><span class="muted">'.$e($line['product_code'] ?? '-').'</span></td><td>'.$e($line['stock_area'] ?? '-').'</td><td>'.$e($line['reason_code'] ?? '-').'</td><td class="right">'.$qty($line['opening_quantity'] ?? 0).'</td><td class="right neg">'.$qty($line['wastage_quantity'] ?? 0).'</td><td class="right">'.$qty($line['closing_quantity'] ?? 0).'</td><td class="right">'.$money($line['unit_cost'] ?? 0, $line['currency_code'] ?? 'USD').'</td><td class="right neg">'.$money($line['total_cost'] ?? 0, $line['currency_code'] ?? 'USD').'</td><td>'.$e($line['created_by_name'] ?? '-').'</td><td>'.$e($line['notes'] ?? '-').'</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</body></html>';
    }

    private function getOrCreateStock(string $companyId, string $branchId, string $productId, string $stockArea): object
    {
        $query = DB::table('product_stocks')->where('company_id', $companyId)->where('branch_id', $branchId)->where('product_id', $productId);
        if (Schema::hasColumn('product_stocks', 'stock_area')) $query->where('stock_area', $stockArea);
        $stock = $query->lockForUpdate()->first();
        if ($stock) return $stock;
        DB::table('product_stocks')->insert($this->filterColumns('product_stocks', [
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'stock_area' => $stockArea,
            'quantity_on_hand' => 0, 'reserved_quantity' => 0, 'available_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $query = DB::table('product_stocks')->where('company_id', $companyId)->where('branch_id', $branchId)->where('product_id', $productId);
        if (Schema::hasColumn('product_stocks', 'stock_area')) $query->where('stock_area', $stockArea);
        return $query->lockForUpdate()->first();
    }

    private function safeMovementType(): string
    {
        try {
            $column = DB::selectOne('SHOW COLUMNS FROM stock_movements LIKE ?', ['movement_type']);
            $type = (string) ($column->Type ?? '');
            preg_match_all("/'([^']+)'/", $type, $matches);
            $allowed = $matches[1] ?? [];
            foreach (['wastage', 'waste', 'write_off', 'adjustment', 'stock_take'] as $candidate) {
                if (in_array($candidate, $allowed, true)) return $candidate;
            }
            return $allowed[0] ?? 'adjustment';
        } catch (\Throwable) {
            return 'adjustment';
        }
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)->filter(fn ($value, $column) => Schema::hasColumn($table, $column))->all();
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
