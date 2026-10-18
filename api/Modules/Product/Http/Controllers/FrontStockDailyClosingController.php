<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanyBranchContextService;
use App\Services\Inventory\HospitalityFrontStockReadinessService;
use App\Services\Inventory\InventoryVarianceAgentService;
use App\Models\SyncLocalState;
use App\Services\Sync\SyncFrontStockClosingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Models\FrontStockDailyClosing;
use Modules\Product\Models\FrontStockDailyClosingLine;

class FrontStockDailyClosingController extends Controller
{
    private const STOCK_AREA = 'front';

    private const ALLOWED_FRONT_STOCK_CLOSING_PRODUCT_TYPES = [
        'raw_material',
        'raw material',
        'semi_finished_good',
        'semi finished good',
        'semi-finished good',
        'semi_finished',
        'semi finished',
        'consumable',
        'consumables',
        'packaging',
    ];

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company) {
            return response()->json([
                'message' => 'Company could not be resolved.',
            ], 422);
        }

        $rows = FrontStockDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::STOCK_AREA)
            ->when($request->input('date_from'), fn ($query, $date) => $query->whereDate('closing_date', '>=', $date))
            ->when($request->input('date_to'), fn ($query, $date) => $query->whereDate('closing_date', '<=', $date))
            ->withCount('lines')
            ->latest('closing_date')
            ->paginate((int) $request->input('per_page', 30));

        return response()->json($rows);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company) {
            return response()->json([
                'message' => 'Company could not be resolved.',
            ], 422);
        }

        $closing = FrontStockDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::STOCK_AREA)
            ->with(['lines' => fn ($query) => $query->orderBy('product_name')])
            ->findOrFail($id);

        return response()->json([
            'data' => $closing,
        ]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $closingDate = Carbon::parse($request->input('closing_date', now()->toDateString()))->toDateString();
        $stockArea = strtolower(trim((string) $request->input('stock_area', self::STOCK_AREA)));

        if (!$company) {
            return response()->json([
                'message' => 'Company could not be resolved.',
            ], 422);
        }

        if (!$branchId) {
            return response()->json([
                'message' => 'Branch could not be resolved.',
            ], 422);
        }

        if ($stockArea !== self::STOCK_AREA) {
            return response()->json([
                'message' => 'Daily Front Stock Closing can only count the Front Stock area.',
                'code' => 'FRONT_STOCK_AREA_REQUIRED',
            ], 422);
        }

        $existingClosing = FrontStockDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $closingDate)
            ->where('stock_area', $stockArea)
            ->with(['lines' => fn ($query) => $query->orderBy('product_name')])
            ->first();
        $frontStockIssueReadiness = app(HospitalityFrontStockReadinessService::class)->assess(
            (string) $company->id,
            $branchId,
            $closingDate
        );

        return response()->json([
            'data' => [
                'closing_date' => $closingDate,
                'stock_area' => $stockArea,
                'branch_id' => $branchId,
                'existing_closing' => $existingClosing,
                'front_stock_issue_readiness' => $frontStockIssueReadiness,
                'blocking_front_stock_issues' => $frontStockIssueReadiness['blocking_front_stock_issues'] ?? [],
                'lines' => $this->buildFrontStockSnapshotLines(
                    companyId: $company->id,
                    branchId: $branchId,
                    stockArea: $stockArea,
                    search: $request->input('search'),
                    productType: $request->input('product_type')
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $till = $this->resolveTill($request);
        $user = auth()->user();

        if (!$company) {
            return response()->json([
                'message' => 'Company could not be resolved.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'closing_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'string'],
            'stock_area' => ['nullable', 'in:front'],
            'replace_existing' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $this->resolveBranchId($request);
        $closingDate = Carbon::parse($request->input('closing_date'))->toDateString();
        $stockArea = self::STOCK_AREA;
        $replaceExisting = filter_var($request->input('replace_existing', false), FILTER_VALIDATE_BOOLEAN);

        if (!$branchId) {
            return response()->json([
                'message' => 'Branch could not be resolved.',
            ], 422);
        }

        $frontStockIssueReadiness = app(HospitalityFrontStockReadinessService::class)->assess(
            (string) $company->id,
            $branchId,
            $closingDate
        );

        if (!($frontStockIssueReadiness['can_reconcile_front_stock'] ?? false)) {
            return response()->json([
                'message' => implode(' ', $frontStockIssueReadiness['blocking_reasons'] ?? [])
                    ?: 'Issue negative stock from BOH to FRONT before saving Front Stock Reconciliation.',
                'code' => 'FRONT_STOCK_ISSUE_REQUIRED',
                'data' => [
                    'closing_date' => $closingDate,
                    'branch_id' => $branchId,
                    'front_stock_issue_readiness' => $frontStockIssueReadiness,
                    'blocking_front_stock_issues' => $frontStockIssueReadiness['blocking_front_stock_issues'] ?? [],
                    'suggested_action' => 'Issue the listed ingredients from BOH to FRONT, reload the count, then save Front Stock Reconciliation.',
                ],
            ], 409);
        }

        $existingClosing = FrontStockDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $closingDate)
            ->where('stock_area', $stockArea)
            ->first();

        if ($existingClosing && !$replaceExisting) {
            return response()->json([
                'message' => 'Front stock has already been closed for this date.',
                'data' => $existingClosing,
            ], 409);
        }

        $inputLines = collect($request->input('lines', []))
            ->filter(fn ($line) => !empty($line['product_id']))
            ->keyBy(fn ($line) => (string) $line['product_id']);

        $snapshotLines = collect($this->buildFrontStockSnapshotLines(
            companyId: $company->id,
            branchId: $branchId,
            stockArea: $stockArea,
            search: null,
            productType: null,
            productIds: $inputLines->keys()->values()->all()
        ))->keyBy('product_id');

        DB::beginTransaction();

        try {
            if ($existingClosing && $replaceExisting) {
                $existingClosing->lines()->delete();

                $closing = $existingClosing;
                $closing->update([
                    'till_id' => $till?->id,
                    'closed_by' => $user?->id,
                    'status' => 'closed',
                    'notes' => $request->input('notes'),
                    'metadata' => [
                        'source' => 'manual_front_stock_count',
                        'created_from' => 'daily_stock_closing_module',
                        'replaced_at' => now()->toISOString(),
                        'replaced_by' => $user?->id,
                    ],
                ]);
            } else {
                $closing = FrontStockDailyClosing::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'till_id' => $till?->id,
                    'closed_by' => $user?->id,
                    'closing_date' => $closingDate,
                    'stock_area' => $stockArea,
                    'status' => 'closed',
                    'notes' => $request->input('notes'),
                    'metadata' => [
                        'source' => 'manual_front_stock_count',
                        'created_from' => 'daily_stock_closing_module',
                    ],
                ]);
            }

            $summary = [
                'total_lines' => 0,
                'variance_lines' => 0,
                'expected_total_quantity' => 0,
                'counted_total_quantity' => 0,
                'variance_total_quantity' => 0,
                'variance_total_value' => 0,
            ];

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
                $currencyCode = $snapshot['currency_code'] ?? $snapshot['currency'] ?? 'USD';

                FrontStockDailyClosingLine::create([
                    'id' => (string) Str::uuid(),
                    'front_stock_daily_closing_id' => $closing->id,
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'product_code' => $snapshot['product_code'] ?? null,
                    'product_sku' => $snapshot['product_sku'] ?? null,
                    'product_name' => $snapshot['product_name'] ?? 'Product',
                    'product_type' => $snapshot['product_type'] ?? null,
                    'uom_code' => $snapshot['uom_code'] ?? null,
                    'stock_area' => $stockArea,
                    'expected_quantity' => $expectedQty,
                    'counted_quantity' => $countedQty,
                    'variance_quantity' => $varianceQty,
                    'unit_cost' => $unitCost,
                    'variance_value' => $varianceValue,
                    'notes' => $inputLine['notes'] ?? null,
                    'metadata' => [
                        'snapshot_quantity_on_hand' => $snapshot['quantity_on_hand'] ?? null,
                        'snapshot_reserved_quantity' => $snapshot['reserved_quantity'] ?? null,
                        'counted_at' => now()->toISOString(),
                        'currency_code' => $currencyCode,
                    ],
                ]);

                $this->adjustFrontStockToCountedQuantity(
                    companyId: $company->id,
                    branchId: $branchId,
                    stockArea: $stockArea,
                    productId: (string) $productId,
                    countedQty: $countedQty,
                    unitCost: $unitCost,
                    currencyCode: $currencyCode,
                    closing: $closing,
                    till: $till,
                    productName: $snapshot['product_name'] ?? 'Product'
                );

                app(InventoryVarianceAgentService::class)->resetProduct(
                    (string) $company->id,
                    (string) $branchId,
                    (string) $productId,
                    'front_stock_closing',
                    'front_stock_daily_closing',
                    (string) $closing->id,
                    auth()->id() ? (string) auth()->id() : null,
                    (string) $stockArea
                );

                $summary['total_lines']++;

                if (abs($varianceQty) > 0.000001) {
                    $summary['variance_lines']++;
                }

                $summary['expected_total_quantity'] += $expectedQty;
                $summary['counted_total_quantity'] += $countedQty;
                $summary['variance_total_quantity'] += $varianceQty;
                $summary['variance_total_value'] += $varianceValue;
            }

            $summary = array_map(fn ($value) => is_numeric($value) ? round((float) $value, 6) : $value, $summary);
            $closing->update($summary);

            if (config('salexa_sync.deployment_mode') === 'edge') {
                $syncState = SyncLocalState::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->whereNotNull('node_id')
                    ->latest('updated_at')
                    ->first();
                if ($syncState) {
                    // The branch reconciliation and its outbox event commit
                    // together. The till is provenance only; Day End consumes
                    // this once for the whole company + branch + date.
                    app(SyncFrontStockClosingService::class)->queueClosing(
                        $syncState,
                        $closing->fresh(['lines'])
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Daily front stock closing saved successfully.',
                'data' => $closing->fresh(['lines' => fn ($query) => $query->orderBy('product_name')]),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Daily front stock closing failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildFrontStockSnapshotLines(
        string $companyId,
        string $branchId,
        string $stockArea = 'front',
        ?string $search = null,
        ?string $productType = null,
        ?array $productIds = null
    ): array {
        if ($stockArea !== self::STOCK_AREA) {
            return [];
        }

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
            ->leftJoin('product_stocks as ps', function ($join) use ($companyId, $branchId, $stockArea) {
                $join->on('ps.product_id', '=', 'p.id')
                    ->where('ps.company_id', '=', $companyId)
                    ->where('ps.branch_id', '=', $branchId)
                    ->where('ps.stock_area', '=', $stockArea);
            })
            ->leftJoin('product_costs as pc', function ($join) use ($companyId, $branchId) {
                $join->on('pc.product_id', '=', 'p.id')
                    ->where('pc.company_id', '=', $companyId)
                    ->where(function ($query) use ($branchId) {
                        $query->whereNull('pc.branch_id')
                            ->orWhere('pc.branch_id', '=', $branchId);
                    });
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where(function ($query) {
                $query->where('p.is_active', true)
                    ->orWhereNull('p.is_active');
            })
            ->whereIn(DB::raw('LOWER(p.product_type)'), self::ALLOWED_FRONT_STOCK_CLOSING_PRODUCT_TYPES)
            ->when($productType, function ($query, $type) {
                $normalizedType = strtolower(trim((string) $type));

                if (!in_array($normalizedType, self::ALLOWED_FRONT_STOCK_CLOSING_PRODUCT_TYPES, true)) {
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
                        ->orWhere('p.code', 'like', $like)
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
                COALESCE(SUM(ps.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(ps.quantity_on_hand), 0) as expected_quantity,
                COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost,
                {$costCurrencyExpression} as currency_code
            ")
            ->groupBy(
                'p.id',
                'p.name',
                'p.code',
                'p.sku',
                'p.product_type',
                'u.code',
                'u.name'
            )
            ->orderBy('p.name');

        return $query->get()->map(function ($row) use ($stockArea) {
            return [
                'product_id' => (string) $row->product_id,
                'product_name' => $row->product_name,
                'product_code' => $row->product_code,
                'product_sku' => $row->product_sku,
                'product_type' => $row->product_type,
                'uom_code' => $row->uom_code ?: 'EACH',
                'stock_area' => $stockArea,
                'quantity_on_hand' => round((float) $row->quantity_on_hand, 6),
                'reserved_quantity' => round((float) $row->reserved_quantity, 6),
                'expected_quantity' => round((float) $row->expected_quantity, 6),
                'counted_quantity' => '',
                'variance_quantity' => 0,
                'unit_cost' => round((float) $row->unit_cost, 6),
                'currency_code' => $row->currency_code ?: 'USD',
                'variance_value' => 0,
                'notes' => '',
            ];
        })->values()->all();
    }


    private function adjustFrontStockToCountedQuantity(
        string $companyId,
        string $branchId,
        string $stockArea,
        string $productId,
        float $countedQty,
        float $unitCost,
        ?string $currencyCode,
        FrontStockDailyClosing $closing,
        mixed $till,
        string $productName
    ): void {
        $stockQuery = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId);

        if (Schema::hasColumn('product_stocks', 'stock_area')) {
            $stockQuery->where('stock_area', $stockArea);
        }

        $stock = $stockQuery->lockForUpdate()->first();

        if (!$stock) {
            $insertPayload = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
                'stock_area' => $stockArea,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('product_stocks')->insert($this->filterColumns('product_stocks', $insertPayload));

            $stockQuery = DB::table('product_stocks')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId);

            if (Schema::hasColumn('product_stocks', 'stock_area')) {
                $stockQuery->where('stock_area', $stockArea);
            }

            $stock = $stockQuery->lockForUpdate()->first();
        }

        $previousQty = round((float) ($stock->quantity_on_hand ?? 0), 6);
        $reservedQty = round((float) ($stock->reserved_quantity ?? 0), 6);
        $newQty = round($countedQty, 6);
        $adjustmentQty = round($newQty - $previousQty, 6);
        $availableQty = max(round($newQty - $reservedQty, 6), 0);

        $updatePayload = [
            'quantity_on_hand' => $newQty,
            'available_quantity' => $availableQty,
            'updated_at' => now(),
        ];

        DB::table('product_stocks')
            ->where('id', $stock->id)
            ->update($this->filterColumns('product_stocks', $updatePayload));

        if (abs($adjustmentQty) <= 0.000001) {
            return;
        }

        $quantityIn = $adjustmentQty > 0 ? $adjustmentQty : 0;
        $quantityOut = $adjustmentQty < 0 ? abs($adjustmentQty) : 0;
        $totalCost = round(abs($adjustmentQty) * $unitCost, 6);
        $currency = $currencyCode ?: 'USD';

        $movementPayload = [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $till?->id,
            'product_id' => $productId,
            'stock_area' => $stockArea,
            'movement_type' => 'adjustment',
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'currency' => $currency,
            'currency_code' => $currency,
            'reference_type' => 'front_stock_daily_closing',
            'reference_id' => $closing->id,
            'notes' => 'Daily front stock closing adjusted front stock to physical count for ' . $productName,
            'metadata' => json_encode([
                'source' => 'daily_stock_closing_module',
                'movement_reason' => 'daily_front_stock_closing_variance_adjustment',
                'closing_id' => $closing->id,
                'closing_date' => $closing->closing_date,
                'stock_area' => $stockArea,
                'previous_quantity_on_hand' => $previousQty,
                'counted_quantity' => $newQty,
                'adjustment_quantity' => $adjustmentQty,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('stock_movements')->insert($this->filterColumns('stock_movements', $movementPayload));
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function resolveCompany(Request $request): mixed
    {
        if (app()->bound('company')) {
            return app('company');
        }

        return $request->attributes->get('company')
            ?? $request->company
            ?? null;
    }

    private function resolveTill(Request $request): mixed
    {
        if (app()->bound('till')) {
            return app('till');
        }

        return $request->attributes->get('till')
            ?? $request->till
            ?? null;
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
