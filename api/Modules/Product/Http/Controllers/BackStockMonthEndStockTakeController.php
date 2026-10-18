<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanyBranchContextService;
use App\Services\Inventory\InventoryVarianceAgentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BackStockMonthEndStockTakeController extends Controller
{
    private const STOCK_AREA = 'back';

    private const ALLOWED_PRODUCT_TYPES = [
        'raw_material',
        'raw material',
        'semi_finished_good',
        'semi finished good',
        'semi-finished good',
        'semi_finished',
        'semi finished',
        'packaging',
        'consumable',
        'consumables',
    ];

    public function snapshot(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $existing = $this->existingCount($company->id, $branchId, $monthEndDate);

        return response()->json([
            'data' => [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'stock_area' => self::STOCK_AREA,
                'existing_count' => $existing,
                'lines' => $this->buildBackStockSnapshotLines(
                    companyId: $company->id,
                    branchId: $branchId,
                    search: $request->input('search'),
                    productType: $request->input('product_type')
                ),
            ],
        ]);
    }

    public function downloadBalanceSnapshot(Request $request)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $rows = $this->buildBackStockSnapshotLines($company->id, $branchId);

        $fileName = 'back-stock-balance-snapshot-' . $monthEndDate . '.csv';
        $csv = $this->csvFromRows($rows, [
            'product_id', 'product_code', 'product_sku', 'product_name', 'product_type',
            'uom_code', 'expected_quantity', 'unit_cost', 'currency_code', 'expected_value',
        ]);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function downloadTradingCountSheet(Request $request)
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $rows = collect($this->buildBackStockSnapshotLines($company->id, $branchId))
            ->map(function ($row) use ($monthEndDate) {
                return [
                    'month_end_date' => $monthEndDate,
                    'stock_area' => self::STOCK_AREA,
                    'product_id' => $row['product_id'],
                    'product_code' => $row['product_code'],
                    'product_sku' => $row['product_sku'],
                    'product_name' => $row['product_name'],
                    'product_type' => $row['product_type'],
                    'uom_code' => $row['uom_code'],
                    'counted_quantity' => '',
                    'notes' => '',
                ];
            })
            ->values()
            ->all();

        $fileName = 'back-stock-month-end-count-sheet-' . $monthEndDate . '.csv';
        $csv = $this->csvFromRows($rows, [
            'month_end_date', 'stock_area', 'product_id', 'product_code', 'product_sku',
            'product_name', 'product_type', 'uom_code', 'counted_quantity', 'notes',
        ]);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function beginTradingStockTake(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $userId = auth()->id();

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date', now()->toDateString()));
        $replaceExisting = $request->boolean('replace_existing', false);

        return DB::transaction(function () use ($company, $branchId, $userId, $monthEndDate, $replaceExisting, $request) {
            $existing = $this->existingCount($company->id, $branchId, $monthEndDate, true);

            if ($existing && !$replaceExisting) {
                return response()->json([
                    'message' => 'Back stock month-end stock count already exists for this month.',
                    'data' => $existing,
                ], 409);
            }

            if ($existing && $replaceExisting) {
                DB::table('back_stock_month_end_count_lines')->where('back_stock_month_end_count_id', $existing->id)->delete();
                DB::table('back_stock_month_end_counts')->where('id', $existing->id)->delete();
            }

            $countId = (string) Str::uuid();
            DB::table('back_stock_month_end_counts')->insert($this->filterColumns('back_stock_month_end_counts', [
                'id' => $countId,
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'stock_area' => self::STOCK_AREA,
                'status' => 'open',
                'started_by' => $userId,
                'started_at' => now(),
                'notes' => $request->input('notes'),
                'metadata' => json_encode([
                    'source' => 'month_end_back_stock_take',
                    'created_from' => 'begin_trading_stock_take',
                    'warning' => 'This stock take is intentionally limited to BOH/back stock only.',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return response()->json([
                'message' => 'Back stock month-end stock take opened.',
                'data' => [
                    'id' => $countId,
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'month_end_date' => $monthEndDate,
                    'stock_area' => self::STOCK_AREA,
                    'status' => 'open',
                    'lines' => $this->buildBackStockSnapshotLines($company->id, $branchId),
                ],
            ], 201);
        });
    }

    public function uploadTradingCountSheets(Request $request): JsonResponse
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
            'file' => ['nullable', 'file'],
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required_with:lines', 'string'],
            'lines.*.counted_quantity' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $rawLines = $request->hasFile('file')
            ? $this->parseUploadedCountSheet($request)
            : $request->input('lines', []);

        $lineErrors = $this->validateCountLines($rawLines);

        if (!empty($lineErrors)) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $lineErrors,
            ], 422);
        }

        $monthEndDate = $this->normalizeMonthEndDate($request->input('month_end_date'));
        $replaceExisting = $request->boolean('replace_existing', false);
        $inputLines = collect($rawLines)
            ->filter(fn ($line) => !empty($line['product_id']))
            ->keyBy(fn ($line) => (string) $line['product_id']);

        return DB::transaction(function () use ($company, $branchId, $userId, $monthEndDate, $replaceExisting, $inputLines, $request) {
            $existing = $this->existingCount($company->id, $branchId, $monthEndDate, true);

            if ($existing && !$replaceExisting) {
                return response()->json([
                    'message' => 'Back stock month-end count already exists. Use replace_existing=true to replace it.',
                    'data' => $existing,
                ], 409);
            }

            if ($existing && $replaceExisting) {
                DB::table('back_stock_month_end_count_lines')->where('back_stock_month_end_count_id', $existing->id)->delete();
                DB::table('back_stock_month_end_counts')->where('id', $existing->id)->delete();
            }

            $snapshotLines = collect($this->buildBackStockSnapshotLines(
                companyId: $company->id,
                branchId: $branchId,
                productIds: $inputLines->keys()->values()->all()
            ))->keyBy('product_id');

            $countId = (string) Str::uuid();
            $summary = [
                'total_lines' => 0,
                'variance_lines' => 0,
                'expected_total_quantity' => 0,
                'counted_total_quantity' => 0,
                'variance_total_quantity' => 0,
                'variance_total_value' => 0,
            ];

            DB::table('back_stock_month_end_counts')->insert($this->filterColumns('back_stock_month_end_counts', [
                'id' => $countId,
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'month_end_date' => $monthEndDate,
                'stock_area' => self::STOCK_AREA,
                'status' => 'closed',
                'started_by' => $userId,
                'started_at' => now(),
                'closed_by' => $userId,
                'closed_at' => now(),
                'notes' => $request->input('notes'),
                'metadata' => json_encode([
                    'source' => 'month_end_back_stock_take',
                    'created_from' => 'upload_trading_count_sheets',
                    'warning' => 'This stock take is intentionally limited to BOH/back stock only.',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            foreach ($inputLines as $productId => $inputLine) {
                $snapshot = $snapshotLines->get((string) $productId);

                if (!$snapshot) {
                    continue;
                }

                $expectedQty = round((float) ($snapshot['expected_quantity'] ?? 0), 6);
                $countedQty = round((float) ($inputLine['counted_quantity'] ?? 0), 6);
                $varianceQty = round($countedQty - $expectedQty, 6);
                $unitCost = round((float) ($snapshot['unit_cost'] ?? 0), 6);
                $varianceValue = round($varianceQty * $unitCost, 6);
                $currencyCode = $snapshot['currency_code'] ?? 'USD';

                DB::table('back_stock_month_end_count_lines')->insert($this->filterColumns('back_stock_month_end_count_lines', [
                    'id' => (string) Str::uuid(),
                    'back_stock_month_end_count_id' => $countId,
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'product_code' => $snapshot['product_code'] ?? null,
                    'product_sku' => $snapshot['product_sku'] ?? null,
                    'product_name' => $snapshot['product_name'] ?? 'Product',
                    'product_type' => $snapshot['product_type'] ?? null,
                    'uom_code' => $snapshot['uom_code'] ?? 'EACH',
                    'stock_area' => self::STOCK_AREA,
                    'expected_quantity' => $expectedQty,
                    'counted_quantity' => $countedQty,
                    'variance_quantity' => $varianceQty,
                    'unit_cost' => $unitCost,
                    'variance_value' => $varianceValue,
                    'currency_code' => $currencyCode,
                    'notes' => $inputLine['notes'] ?? null,
                    'metadata' => json_encode([
                        'snapshot_quantity_on_hand' => $snapshot['quantity_on_hand'] ?? null,
                        'counted_at' => now()->toISOString(),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                $this->adjustBackStockToCountedQuantity(
                    companyId: $company->id,
                    branchId: $branchId,
                    productId: (string) $productId,
                    countedQty: $countedQty,
                    unitCost: $unitCost,
                    currencyCode: $currencyCode,
                    countId: $countId,
                    productName: $snapshot['product_name'] ?? 'Product'
                );

                app(InventoryVarianceAgentService::class)->resetProduct(
                    (string) $company->id,
                    (string) $branchId,
                    (string) $productId,
                    'back_stock_month_end_count',
                    'back_stock_month_end_count',
                    (string) $countId,
                    auth()->id() ? (string) auth()->id() : null,
                    self::STOCK_AREA
                );

                $summary['total_lines']++;
                $summary['expected_total_quantity'] += $expectedQty;
                $summary['counted_total_quantity'] += $countedQty;
                $summary['variance_total_quantity'] += $varianceQty;
                $summary['variance_total_value'] += $varianceValue;

                if (abs($varianceQty) > 0.000001) {
                    $summary['variance_lines']++;
                }
            }

            DB::table('back_stock_month_end_counts')
                ->where('id', $countId)
                ->update($this->filterColumns('back_stock_month_end_counts', array_merge(
                    array_map(fn ($value) => round((float) $value, 6), $summary),
                    ['updated_at' => now()]
                )));

            return response()->json([
                'message' => 'Back stock month-end count posted successfully.',
                'data' => $this->showPayload($countId),
            ], 201);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $count = DB::table('back_stock_month_end_counts')
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::STOCK_AREA)
            ->where('id', $id)
            ->first();

        if (!$count) {
            return response()->json(['message' => 'Back stock month-end count not found.'], 404);
        }

        return response()->json(['data' => $this->showPayload($id)]);
    }


    private function normalizeMonthEndDate(?string $value = null): string
    {
        $date = Carbon::parse($value ?: now()->toDateString());

        if ((int) $date->format('d') === 1) {
            return $date->copy()->subDay()->endOfMonth()->toDateString();
        }

        return $date->endOfMonth()->toDateString();
    }

    private function buildBackStockSnapshotLines(
        string $companyId,
        string $branchId,
        ?string $search = null,
        ?string $productType = null,
        ?array $productIds = null
    ): array {
        $reservedQuantityExpression = Schema::hasColumn('product_stocks', 'reserved_quantity')
            ? 'COALESCE(SUM(ps.reserved_quantity), 0)'
            : '0';

        if (Schema::hasColumn('product_costs', 'currency_code') && Schema::hasColumn('product_costs', 'currency')) {
            $costCurrencyExpression = "COALESCE(MAX(pc.currency_code), MAX(pc.currency), 'USD')";
        } elseif (Schema::hasColumn('product_costs', 'currency_code')) {
            $costCurrencyExpression = "COALESCE(MAX(pc.currency_code), 'USD')";
        } elseif (Schema::hasColumn('product_costs', 'currency')) {
            $costCurrencyExpression = "COALESCE(MAX(pc.currency), 'USD')";
        } else {
            $costCurrencyExpression = "'USD'";
        }

        $query = DB::table('products as p')
            ->leftJoin('product_stocks as ps', function ($join) use ($companyId, $branchId) {
                $join->on('ps.product_id', '=', 'p.id')
                    ->where('ps.company_id', '=', $companyId)
                    ->where('ps.branch_id', '=', $branchId);

                if (Schema::hasColumn('product_stocks', 'stock_area')) {
                    $join->where('ps.stock_area', '=', self::STOCK_AREA);
                }
            })
            ->leftJoin('product_costs as pc', function ($join) use ($companyId, $branchId) {
                $join->on('pc.product_id', '=', 'p.id')
                    ->where('pc.company_id', '=', $companyId);

                if (Schema::hasColumn('product_costs', 'branch_id')) {
                    $join->where(function ($query) use ($branchId) {
                        $query->whereNull('pc.branch_id')->orWhere('pc.branch_id', '=', $branchId);
                    });
                }
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where(function ($query) {
                $query->where('p.is_active', true)->orWhereNull('p.is_active');
            })
            ->whereIn(DB::raw('LOWER(p.product_type)'), self::ALLOWED_PRODUCT_TYPES)
            ->when($productType, function ($query, $type) {
                $normalizedType = strtolower(trim((string) $type));

                if (!in_array($normalizedType, self::ALLOWED_PRODUCT_TYPES, true)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereRaw('LOWER(p.product_type) = ?', [$normalizedType]);
            })
            ->when($productIds, fn ($query, $ids) => $query->whereIn('p.id', $ids))
            ->when($search, function ($query, $search) {
                $like = '%' . trim($search) . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('p.name', 'like', $like)
                        ->orWhere('p.sku', 'like', $like)
                        ->orWhere('p.code', 'like', $like);

                    if (Schema::hasColumn('products', 'barcode')) {
                        $inner->orWhere('p.barcode', 'like', $like);
                    }
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
                {$reservedQuantityExpression} as reserved_quantity,
                COALESCE(SUM(ps.quantity_on_hand), 0) as expected_quantity,
                COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost,
                {$costCurrencyExpression} as currency_code
            ")
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.product_type', 'u.code', 'u.name')
            ->orderBy('p.name');

        return $query->get()->map(function ($row) {
            $expectedQty = round((float) $row->expected_quantity, 6);
            $unitCost = round((float) $row->unit_cost, 6);

            return [
                'product_id' => (string) $row->product_id,
                'product_name' => $row->product_name,
                'product_code' => $row->product_code,
                'product_sku' => $row->product_sku,
                'product_type' => $row->product_type,
                'uom_code' => $row->uom_code ?: 'EACH',
                'stock_area' => self::STOCK_AREA,
                'quantity_on_hand' => round((float) $row->quantity_on_hand, 6),
                'reserved_quantity' => round((float) $row->reserved_quantity, 6),
                'expected_quantity' => $expectedQty,
                'counted_quantity' => '',
                'variance_quantity' => 0,
                'unit_cost' => $unitCost,
                'currency_code' => $row->currency_code ?: 'USD',
                'expected_value' => round($expectedQty * $unitCost, 6),
                'variance_value' => 0,
                'notes' => '',
            ];
        })->values()->all();
    }

    private function adjustBackStockToCountedQuantity(
        string $companyId,
        string $branchId,
        string $productId,
        float $countedQty,
        float $unitCost,
        ?string $currencyCode,
        string $countId,
        string $productName
    ): void {
        $stockQuery = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId);

        if (Schema::hasColumn('product_stocks', 'stock_area')) {
            $stockQuery->where('stock_area', self::STOCK_AREA);
        }

        $stock = $stockQuery->lockForUpdate()->first();

        if (!$stock) {
            DB::table('product_stocks')->insert($this->filterColumns('product_stocks', [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'stock_area' => self::STOCK_AREA,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $stockQuery = DB::table('product_stocks')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId);

            if (Schema::hasColumn('product_stocks', 'stock_area')) {
                $stockQuery->where('stock_area', self::STOCK_AREA);
            }

            $stock = $stockQuery->lockForUpdate()->first();
        }

        $previousQty = round((float) ($stock->quantity_on_hand ?? 0), 6);
        $reservedQty = round((float) ($stock->reserved_quantity ?? 0), 6);
        $newQty = round($countedQty, 6);
        $adjustmentQty = round($newQty - $previousQty, 6);
        $availableQty = max(round($newQty - $reservedQty, 6), 0);

        DB::table('product_stocks')
            ->where('id', $stock->id)
            ->update($this->filterColumns('product_stocks', [
                'quantity_on_hand' => $newQty,
                'available_quantity' => $availableQty,
                'updated_at' => now(),
            ]));

        if (abs($adjustmentQty) <= 0.000001) {
            return;
        }

        $quantityIn = $adjustmentQty > 0 ? $adjustmentQty : 0;
        $quantityOut = $adjustmentQty < 0 ? abs($adjustmentQty) : 0;
        $currency = $currencyCode ?: 'USD';

        DB::table('stock_movements')->insert($this->filterColumns('stock_movements', [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'stock_area' => self::STOCK_AREA,
            'movement_type' => 'stock_take',
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost,
            'total_cost' => round(abs($adjustmentQty) * $unitCost, 6),
            'currency' => $currency,
            'currency_code' => $currency,
            'reference_type' => 'back_stock_month_end_count',
            'reference_id' => $countId,
            'notes' => 'Month End back stock count adjusted BOH/back stock to physical count for ' . $productName,
            'metadata' => json_encode([
                'source' => 'month_end_back_stock_take',
                'movement_reason' => 'month_end_back_stock_adjustment',
                'count_id' => $countId,
                'stock_area' => self::STOCK_AREA,
                'previous_quantity_on_hand' => $previousQty,
                'counted_quantity' => $newQty,
                'adjustment_quantity' => $adjustmentQty,
            ]),
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function existingCount(string $companyId, string $branchId, string $monthEndDate, bool $lock = false): mixed
    {
        $query = DB::table('back_stock_month_end_counts')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('month_end_date', $monthEndDate)
            ->where('stock_area', self::STOCK_AREA)
            ->orderByDesc('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function showPayload(string $countId): array
    {
        $count = DB::table('back_stock_month_end_counts')->where('id', $countId)->first();
        $lines = DB::table('back_stock_month_end_count_lines')
            ->where('back_stock_month_end_count_id', $countId)
            ->orderBy('product_name')
            ->get();

        return [
            'count' => $count,
            'lines' => $lines,
        ];
    }

    private function parseUploadedCountSheet(Request $request): array
    {
        $file = $request->file('file');

        if (!$file) {
            return [];
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                abort(response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'file' => ['Excel upload needs phpoffice/phpspreadsheet. Upload the CSV count sheet instead.'],
                    ],
                ], 422));
            }

            return $this->parseSpreadsheetCountSheet($file->getRealPath());
        }

        return $this->parseCsvCountSheet($file->getRealPath());
    }

    private function parseCsvCountSheet(string $path): array
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return [];
        }

        $headers = fgetcsv($handle);

        if (!is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headerMap = $this->headerMap($headers);
        $rows = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = $this->countLineFromRow($data, $headerMap);
            $row['_row_number'] = $rowNumber;

            if (!empty($row['product_id']) || $row['counted_quantity'] !== '') {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function parseSpreadsheetCountSheet(string $path): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);

        if (empty($matrix)) {
            return [];
        }

        $headers = array_shift($matrix);
        $headerMap = $this->headerMap($headers);
        $rows = [];
        $rowNumber = 1;

        foreach ($matrix as $data) {
            $rowNumber++;
            $row = $this->countLineFromRow($data, $headerMap);
            $row['_row_number'] = $rowNumber;

            if (!empty($row['product_id']) || $row['counted_quantity'] !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $key = $this->normalizeHeader($header);

            if ($key !== '') {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    private function normalizeHeader(mixed $header): string
    {
        $value = strtolower(trim((string) $header));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim($value, '_');
    }

    private function rowValue(array $row, array $headerMap, array $keys, mixed $fallback = ''): mixed
    {
        foreach ($keys as $key) {
            $normalized = $this->normalizeHeader($key);

            if (array_key_exists($normalized, $headerMap)) {
                $index = $headerMap[$normalized];
                return $row[$index] ?? $fallback;
            }
        }

        return $fallback;
    }

    private function countLineFromRow(array $row, array $headerMap): array
    {
        return [
            'product_id' => trim((string) $this->rowValue($row, $headerMap, ['product_id', 'id'])),
            'counted_quantity' => trim((string) $this->rowValue($row, $headerMap, [
                'counted_quantity',
                'counted_qty',
                'count_quantity',
                'physical_count',
                'physical_quantity',
                'qty_counted',
                'counted',
            ])),
            'notes' => trim((string) $this->rowValue($row, $headerMap, ['notes', 'note', 'remarks', 'comment'])),
        ];
    }

    private function validateCountLines(array $lines): array
    {
        if (empty($lines)) {
            return [
                'lines' => ['Upload the completed count sheet CSV, or submit lines with product_id and counted_quantity.'],
            ];
        }

        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            $rowNumber = (int) ($line['_row_number'] ?? ($index + 1));
            $productId = trim((string) ($line['product_id'] ?? ''));
            $counted = trim((string) ($line['counted_quantity'] ?? ''));

            if ($productId === '') {
                $errors['lines'][] = "Row {$rowNumber}: product_id is required.";
            }

            if ($counted === '') {
                $errors['lines'][] = "Row {$rowNumber}: counted_quantity is required.";
            } elseif (!is_numeric($counted) || (float) $counted < 0) {
                $errors['lines'][] = "Row {$rowNumber}: counted_quantity must be a number greater than or equal to zero.";
            }
        }

        return $errors;
    }

    private function csvFromRows(array $rows, array $headers): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $row[$header] ?? '', $headers));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
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

        $requestedBranchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchId ? [(string) $requestedBranchId] : []
        );

        return $requestedBranchId ? (string) $requestedBranchId : $scope['context_branch_id'];
    }
}
