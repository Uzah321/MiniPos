<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Inventory\InventoryVarianceAgentService;
use App\Services\Sales\SalesCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function App\Helpers\hasPermission;

class StockTakeController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $query = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->when($request->input('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('as_of_date', '<=', $request->input('date_to')))
            ->when($request->input('q'), function ($q) use ($request) {
                $term = trim($request->input('q'));
                $q->where(function ($sub) use ($term) {
                    $sub->where('stock_take_no', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at');

        return response()->json([
            'message' => 'Stock take sessions fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 25),
        ]);
    }

    public function show(string $sessionId)
    {
        $company = app('company');

        $session = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Stock take session not found.'], 404);
        }

        $lines = DB::table('stock_take_lines')
            ->where('stock_take_session_id', $sessionId)
            ->when(request('variance_type'), fn ($q) => $q->where('variance_type', request('variance_type')))
            ->when(request('q'), function ($q) {
                $term = trim(request('q'));
                $q->where(function ($sub) use ($term) {
                    $sub->where('product_name', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhere('category_name', 'like', "%{$term}%")
                        ->orWhere('supplier_name', 'like', "%{$term}%");
                });
            })
            ->orderByRaw("FIELD(variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
            ->orderBy('product_name')
            ->paginate((int) request('per_page', 500));

        return response()->json([
            'message' => 'Stock take fetched successfully.',
            'data' => [
                'session' => $session,
                'lines' => $lines,
            ],
        ]);
    }

    public function downloadCountSheet(Request $request)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'format' => ['nullable', 'in:xlsx,csv'],
            'count_mode' => ['nullable', 'in:blank,zero,system'],
            'system_qty_mode' => ['nullable', 'in:actual,zero'],
            'category_id' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'string'],
            'include_zero_stock' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        $asOfDate = Carbon::parse($request->input('as_of_date'))->toDateString();
        $format = $request->input('format') ?: 'xlsx';
        $countMode = $request->input('count_mode') ?: 'zero';
        $systemQtyMode = $request->input('system_qty_mode') ?: 'actual';

        $rows = $this->stockRows(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            categoryId: $request->input('category_id'),
            supplierId: $request->input('supplier_id'),
            includeZeroStock: $request->boolean('include_zero_stock')
        );

        $session = $this->createOrUpdateDraftSession(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            title: $request->input('title') ?: 'Stock take ' . $asOfDate,
            filters: $request->only(['category_id', 'supplier_id', 'include_zero_stock', 'count_mode', 'system_qty_mode'])
        );

        $spreadsheet = $this->buildCountSheetWorkbook($session, $rows, $countMode, $systemQtyMode);

        $fileName = 'stock_take_' . $asOfDate . '_' . $session->stock_take_no . '.' . $format;

        return $this->streamSpreadsheet($spreadsheet, $fileName, $format);
    }


    public function downloadBalanceSnapshot(Request $request)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'format' => ['nullable', 'in:xlsx,csv'],
            'category_id' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'string'],
            'include_zero_stock' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        $asOfDate = Carbon::parse($request->input('as_of_date'))->toDateString();
        $format = $request->input('format') ?: 'xlsx';

        $rows = $this->stockRows(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            categoryId: $request->input('category_id'),
            supplierId: $request->input('supplier_id'),
            includeZeroStock: $request->boolean('include_zero_stock')
        );

        $session = $this->createOrUpdateDraftSession(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            title: $request->input('title') ?: 'Trading stock take ' . $asOfDate,
            filters: $request->only(['category_id', 'supplier_id', 'include_zero_stock'])
        );

        $this->updateSessionColumns($session->id, [
            'mode' => 'trading',
            'metadata' => $this->mergeJson($session->metadata ?? null, [
                'balance_snapshot_downloaded_at' => now()->toDateTimeString(),
                'process' => 'trading_stock_take',
            ]),
        ]);

        $session = DB::table('stock_take_sessions')->where('id', $session->id)->first();

        $spreadsheet = $this->buildBalanceSnapshotWorkbook($session, $rows);
        $fileName = 'stock_take_balance_snapshot_' . $asOfDate . '_' . $session->stock_take_no . '.' . $format;

        return $this->streamSpreadsheet($spreadsheet, $fileName, $format);
    }

    public function downloadTradingCountSheet(Request $request)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'format' => ['nullable', 'in:xlsx,csv'],
            'session_id' => ['nullable', 'string'],
            'count_mode' => ['nullable', 'in:blank,zero'],
            'system_qty_mode' => ['nullable', 'in:zero,actual'],
            'category_id' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'string'],
            'include_zero_stock' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        $asOfDate = Carbon::parse($request->input('as_of_date'))->toDateString();
        $format = $request->input('format') ?: 'xlsx';
        $countMode = $request->input('count_mode') ?: 'zero';
        $systemQtyMode = $request->input('system_qty_mode') ?: 'zero';

        $session = null;

        if ($request->input('session_id')) {
            $session = DB::table('stock_take_sessions')
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('id', $request->input('session_id'))
                ->first();
        }

        if (!$session) {
            $session = $this->createOrUpdateDraftSession(
                companyId: $company->id,
                branchId: $branchId,
                asOfDate: $asOfDate,
                title: 'Trading count sheet ' . $asOfDate,
                filters: $request->only(['category_id', 'supplier_id', 'include_zero_stock', 'count_mode', 'system_qty_mode'])
            );
        }

        $this->updateSessionColumns($session->id, [
            'mode' => 'trading',
            'metadata' => $this->mergeJson($session->metadata ?? null, [
                'count_sheet_downloaded_at' => now()->toDateTimeString(),
                'system_qty_mode' => $systemQtyMode,
            ]),
        ]);

        $rows = $this->stockRows(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            categoryId: $request->input('category_id'),
            supplierId: $request->input('supplier_id'),
            includeZeroStock: $request->boolean('include_zero_stock')
        );

        $session = DB::table('stock_take_sessions')->where('id', $session->id)->first();

        $spreadsheet = $this->buildCountSheetWorkbook($session, $rows, $countMode, $systemQtyMode);
        $fileName = 'stock_take_count_input_' . $asOfDate . '_' . $session->stock_take_no . '.' . $format;

        return $this->streamSpreadsheet($spreadsheet, $fileName, $format);
    }

    public function beginTradingStockTake(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'session_id' => ['nullable', 'string'],
            'category_id' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'string'],
            'include_zero_stock' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        $asOfDate = Carbon::parse($request->input('as_of_date'))->toDateString();

        DB::beginTransaction();

        try {
            $session = null;

            if ($request->input('session_id')) {
                $session = DB::table('stock_take_sessions')
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('id', $request->input('session_id'))
                    ->lockForUpdate()
                    ->first();
            }

            if (!$session) {
                $session = $this->createOrUpdateDraftSession(
                    companyId: $company->id,
                    branchId: $branchId,
                    asOfDate: $asOfDate,
                    title: $request->input('title') ?: 'Trading stock take ' . $asOfDate,
                    filters: $request->only(['category_id', 'supplier_id', 'include_zero_stock'])
                );
            }

            if (!in_array($session->status, ['draft', 'count_uploaded', 'variance_review'], true)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Only draft/review stock take sessions can be moved into trading mode.',
                ], 422);
            }

            $alreadyStarted = $session->trading_started_at ?? null;
            if ($alreadyStarted) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Trading stock take has already been started for this session.',
                    'data' => ['session' => $session],
                ], 422);
            }

            $rows = $this->stockRows(
                companyId: $company->id,
                branchId: $branchId,
                asOfDate: $asOfDate,
                categoryId: $request->input('category_id'),
                supplierId: $request->input('supplier_id'),
                includeZeroStock: $request->boolean('include_zero_stock')
            );

            DB::table('stock_take_lines')->where('stock_take_session_id', $session->id)->delete();

            $productIds = [];
            $originalNegative = [];

            foreach ($rows as $row) {
                $productIds[] = $row['product_id'];

                $product = DB::table('products')
                    ->where('company_id', $company->id)
                    ->where('id', $row['product_id'])
                    ->first();

                $originalAllowNegative = (int) ($product->allow_negative_stock ?? 0);
                $originalNegative[$row['product_id']] = $originalAllowNegative;

                $this->insertSnapshotLine(
                    sessionId: $session->id,
                    companyId: $company->id,
                    branchId: $branchId,
                    row: $row,
                    originalAllowNegative: $originalAllowNegative
                );
            }

            if (!empty($productIds)) {
                DB::table('products')
                    ->where('company_id', $company->id)
                    ->whereIn('id', $productIds)
                    ->update([
                        'allow_negative_stock' => 1,
                        'updated_at' => now(),
                    ]);

                $stocks = DB::table('product_stocks')
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->whereIn('product_id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');

                foreach ($productIds as $productId) {
                    $stock = $stocks->get($productId);

                    if (!$stock) {
                        DB::table('product_stocks')->insert([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'branch_id' => $branchId,
                            'product_id' => $productId,
                            'quantity_on_hand' => 0,
                            'reserved_quantity' => 0,
                            'available_quantity' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('product_stocks')
                            ->where('id', $stock->id)
                            ->update([
                                'quantity_on_hand' => 0,
                                'available_quantity' => 0 - (float) ($stock->reserved_quantity ?? 0),
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('stock_movements')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branchId,
                        'till_id' => null,
                        'shift_session_id' => null,
                        'product_id' => $productId,
                        'movement_type' => 'adjustment',
                        'quantity_in' => 0,
                        'quantity_out' => 0,
                        'balance_after' => 0,
                        'unit_cost' => 0,
                        'total_cost' => 0,
                        'currency' => null,
                        'currency_code' => null,
                        'reference_type' => 'stock_take_clear_to_zero',
                        'reference_id' => $session->id,
                        'notes' => 'Stock take trading mode started. System balance cleared to zero.',
                        'metadata' => json_encode([
                            'stock_take_session_id' => $session->id,
                            'stock_take_no' => $session->stock_take_no,
                            'action' => 'clear_to_zero_and_enable_negative',
                        ]),
                        'created_by' => $user?->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->refreshSessionTotals($session->id, 'variance_review', [
                'zeroed_at' => now(),
                'trading_started_at' => now(),
                'mode' => 'trading',
                'metadata' => $this->mergeJson($session->metadata ?? null, [
                    'trading_started_by' => $user?->id,
                    'trading_started_at' => now()->toDateTimeString(),
                    'original_allow_negative_stock' => $originalNegative,
                    'important' => 'Product allow_negative_stock is product-level. It is restored on post/cancel using this metadata.',
                ]),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Trading stock take started. Stock balances were cleared to zero and negative stock was enabled.',
                'data' => [
                    'session' => DB::table('stock_take_sessions')->where('id', $session->id)->first(),
                    'products_enabled' => count($productIds),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to start trading stock take.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

//    public function uploadTradingCountSheets(Request $request)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => ['required', 'string'],
//            'as_of_date' => ['required', 'date'],
//            'session_id' => ['required', 'string'],
//            'balance_file' => ['nullable', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
//            'count_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $branchId = $request->input('branch_id');
//        $session = DB::table('stock_take_sessions')
//            ->where('company_id', $company->id)
//            ->where('branch_id', $branchId)
//            ->where('id', $request->input('session_id'))
//            ->first();
//
//        if (!$session) {
//            return response()->json(['message' => 'Stock take session not found.'], 404);
//        }
//
//        if (($session->mode ?? 'normal') !== 'trading') {
//            return response()->json([
//                'message' => 'This endpoint is only for trading stock take sessions.',
//            ], 422);
//        }
//
//        $countRows = $this->readUploadedSheet($request->file('count_file')->getPathname());
//
//        if (empty($countRows)) {
//            return response()->json([
//                'message' => 'No product rows were found in the count sheet.',
//                'data' => [
//                    'hint' => 'Upload the counted values sheet downloaded from Trading Count Sheet. It must contain product_id and counted_quantity.',
//                ],
//            ], 422);
//        }
//
//        $balanceRows = [];
//        if ($request->hasFile('balance_file')) {
//            $balanceRows = collect($this->readUploadedSheet($request->file('balance_file')->getPathname()))
//                ->keyBy('product_id');
//        }
//
//        $errors = [];
//        $results = [];
//
//        DB::beginTransaction();
//
//        try {
//            foreach ($countRows as $index => $row) {
//                $rowNo = (int) ($row['_row_number'] ?? ($index + 2));
//                $productId = trim((string) ($row['product_id'] ?? ''));
//
//                if ($productId === '') {
//                    $errors[] = [
//                        'row_number' => $rowNo,
//                        'message' => 'Product ID is required.',
//                    ];
//                    continue;
//                }
//
//                $line = DB::table('stock_take_lines')
//                    ->where('stock_take_session_id', $session->id)
//                    ->where('product_id', $productId)
//                    ->first();
//
//                if (!$line) {
//                    $errors[] = [
//                        'row_number' => $rowNo,
//                        'message' => 'Product was not found in the trading stock take snapshot.',
//                        'product_id' => $productId,
//                    ];
//                    continue;
//                }
//
//                $countedQty = $this->toNumber($row['counted_quantity'] ?? null);
//
//                if ($countedQty === null) {
//                    $errors[] = [
//                        'row_number' => $rowNo,
//                        'message' => 'Counted quantity is required.',
//                        'product_id' => $productId,
//                    ];
//                    continue;
//                }
//
//                $snapshotQty = (float) ($line->snapshot_quantity ?? $line->system_quantity ?? 0);
//
//                if ($balanceRows->has($productId)) {
//                    $balanceRow = $balanceRows->get($productId);
//                    $snapshotQty = $this->toNumber($balanceRow['snapshot_quantity'] ?? $balanceRow['system_quantity'] ?? null) ?? $snapshotQty;
//                }
//
//                $stock = DB::table('product_stocks')
//                    ->where('company_id', $company->id)
//                    ->where('branch_id', $branchId)
//                    ->where('product_id', $productId)
//                    ->first();
//
//                $tradingQty = (float) ($stock->quantity_on_hand ?? 0);
//                $finalQty = $countedQty + $tradingQty;
//                $varianceQty = $finalQty - $snapshotQty;
//
//                $unitCost = (float) ($line->unit_cost ?? 0);
//                $sellingPrice = (float) ($line->selling_price ?? 0);
//                $varianceType = $varianceQty < 0 ? 'short' : ($varianceQty > 0 ? 'over' : 'matched');
//
//                if ($countedQty == 0 && $snapshotQty != 0) {
//                    $varianceType = 'zero_count';
//                }
//
//                $update = [
//                    'snapshot_quantity' => $snapshotQty,
//                    'system_quantity' => $snapshotQty,
//                    'counted_quantity' => $countedQty,
//                    'trading_quantity' => $tradingQty,
//                    'trading_sales_quantity' => $tradingQty < 0 ? abs($tradingQty) : 0,
//                    'trading_returns_quantity' => $tradingQty > 0 ? $tradingQty : 0,
//                    'final_quantity' => $finalQty,
//                    'variance_quantity' => $varianceQty,
//                    'system_cost_value' => $snapshotQty * $unitCost,
//                    'counted_cost_value' => $countedQty * $unitCost,
//                    'variance_cost_value' => $varianceQty * $unitCost,
//                    'system_selling_value' => $snapshotQty * $sellingPrice,
//                    'counted_selling_value' => $countedQty * $sellingPrice,
//                    'variance_selling_value' => $varianceQty * $sellingPrice,
//                    'variance_type' => $varianceType,
//                    'count_notes' => trim((string) ($row['count_notes'] ?? $row['notes'] ?? '')),
//                    'updated_at' => now(),
//                ];
//
//                DB::table('stock_take_lines')
//                    ->where('id', $line->id)
//                    ->update(array_intersect_key($update, array_flip(Schema::getColumnListing('stock_take_lines'))));
//
//                $results[] = [
//                    'product_id' => $productId,
//                    'product_name' => $line->product_name,
//                    'snapshot_quantity' => $snapshotQty,
//                    'counted_quantity' => $countedQty,
//                    'trading_quantity' => $tradingQty,
//                    'final_quantity' => $finalQty,
//                    'variance_quantity' => $varianceQty,
//                    'variance_type' => $varianceType,
//                ];
//            }
//
//            if (!empty($errors)) {
//                DB::rollBack();
//
//                return response()->json([
//                    'message' => 'Trading count upload has validation errors. No variance was saved.',
//                    'data' => [
//                        'errors' => $errors,
//                        'results' => $results,
//                    ],
//                ], 422);
//            }
//
//            $this->refreshSessionTotals($session->id, 'count_uploaded', [
//                'uploaded_by' => $user?->id,
//                'uploaded_at' => now(),
//                'snapshot_uploaded_at' => $request->hasFile('balance_file') ? now() : null,
//                'count_uploaded_at' => now(),
//                'metadata' => $this->mergeJson($session->metadata ?? null, [
//                    'trading_count_uploaded_at' => now()->toDateTimeString(),
//                    'trading_count_uploaded_by' => $user?->id,
//                    'balance_file_uploaded' => $request->hasFile('balance_file'),
//                ]),
//            ]);
//
//            DB::commit();
//
//            $freshSession = DB::table('stock_take_sessions')->where('id', $session->id)->first();
//            $freshLines = DB::table('stock_take_lines')
//                ->where('stock_take_session_id', $session->id)
//                ->orderByRaw("FIELD(variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
//                ->orderBy('product_name')
//                ->limit(500)
//                ->get();
//
//            return response()->json([
//                'message' => 'Trading stock take sheets uploaded and variance analysis completed successfully.',
//                'data' => [
//                    'session' => $freshSession,
//                    'lines' => [
//                        'data' => $freshLines,
//                        'total' => $freshLines->count(),
//                        'current_page' => 1,
//                        'last_page' => 1,
//                    ],
//                    'results' => $results,
//                ],
//            ]);
//        } catch (\Throwable $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Trading stock take upload failed.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
    public function uploadTradingCountSheets(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'session_id' => ['required', 'string'],
            'balance_file' => ['nullable', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'count_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');

        $session = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('id', $request->input('session_id'))
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Stock take session not found.',
            ], 404);
        }

        if (($session->mode ?? 'normal') !== 'trading') {
            return response()->json([
                'message' => 'This endpoint is only for trading stock take sessions.',
            ], 422);
        }

        $countRows = $this->readUploadedSheet($request->file('count_file')->getPathname());

        if (empty($countRows)) {
            return response()->json([
                'message' => 'No product rows were found in the count sheet.',
                'data' => [
                    'hint' => 'Upload the counted values sheet downloaded from Trading Count Sheet. It must contain product_id and counted_quantity.',
                ],
            ], 422);
        }

        /*
         |--------------------------------------------------------------------------
         | Important
         |--------------------------------------------------------------------------
         | The trusted frozen snapshot is stock_take_lines.snapshot_quantity.
         | We do not allow balance_file to override it, because an old/wrong
         | balance file would make variance analysis lie.
         |--------------------------------------------------------------------------
         */

        $errors = [];
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($countRows as $index => $row) {
                $rowNo = (int) ($row['_row_number'] ?? ($index + 2));
                $productId = trim((string) ($row['product_id'] ?? ''));

                if ($productId === '') {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Product ID is required.',
                    ];
                    continue;
                }

                $line = DB::table('stock_take_lines')
                    ->where('stock_take_session_id', $session->id)
                    ->where('product_id', $productId)
                    ->first();

                if (!$line) {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Product was not found in the trading stock take snapshot.',
                        'product_id' => $productId,
                    ];
                    continue;
                }

                $countedQty = $this->toNumber($row['counted_quantity'] ?? null);

                if ($countedQty === null) {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Counted quantity is required.',
                        'product_id' => $productId,
                    ];
                    continue;
                }

                /*
                 * Trusted frozen balance saved when beginTradingStockTake() ran.
                 */
                $snapshotQty = (float) ($line->snapshot_quantity ?? $line->system_quantity ?? 0);

                /*
                 * Current stock after trading started.
                 * Because trading mode cleared stock to zero, sales after that usually
                 * push this negative.
                 */
                $stock = DB::table('product_stocks')
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->first();

                $tradingQty = (float) ($stock->quantity_on_hand ?? 0);

                /*
                 * Final physical stock after deducting trading activity.
                 */
                $finalQty = $countedQty + $tradingQty;

                /*
                 * Variance against original frozen snapshot.
                 */
                $varianceQty = $finalQty - $snapshotQty;

                $unitCost = (float) ($line->unit_cost ?? 0);
                $sellingPrice = (float) ($line->selling_price ?? 0);

                $varianceType = 'matched';

                if ($countedQty == 0 && $snapshotQty != 0) {
                    $varianceType = 'zero_count';
                } elseif ($varianceQty < 0) {
                    $varianceType = 'short';
                } elseif ($varianceQty > 0) {
                    $varianceType = 'over';
                }

                $update = [
                    'snapshot_quantity' => $snapshotQty,
                    'system_quantity' => $snapshotQty,
                    'counted_quantity' => $countedQty,
                    'trading_quantity' => $tradingQty,
                    'trading_sales_quantity' => $tradingQty < 0 ? abs($tradingQty) : 0,
                    'trading_returns_quantity' => $tradingQty > 0 ? $tradingQty : 0,
                    'final_quantity' => $finalQty,
                    'variance_quantity' => $varianceQty,
                    'system_cost_value' => $snapshotQty * $unitCost,
                    'counted_cost_value' => $countedQty * $unitCost,
                    'variance_cost_value' => $varianceQty * $unitCost,
                    'system_selling_value' => $snapshotQty * $sellingPrice,
                    'counted_selling_value' => $countedQty * $sellingPrice,
                    'variance_selling_value' => $varianceQty * $sellingPrice,
                    'variance_type' => $varianceType,
                    'count_notes' => trim((string) ($row['count_notes'] ?? $row['notes'] ?? '')),
                    'updated_at' => now(),
                ];

                DB::table('stock_take_lines')
                    ->where('id', $line->id)
                    ->update(array_intersect_key(
                        $update,
                        array_flip(Schema::getColumnListing('stock_take_lines'))
                    ));

                $results[] = [
                    'product_id' => $productId,
                    'product_name' => $line->product_name,
                    'snapshot_quantity' => $snapshotQty,
                    'counted_quantity' => $countedQty,
                    'trading_quantity' => $tradingQty,
                    'final_quantity' => $finalQty,
                    'variance_quantity' => $varianceQty,
                    'variance_type' => $varianceType,
                ];
            }

            if (!empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Trading count upload has validation errors. No variance was saved.',
                    'data' => [
                        'errors' => $errors,
                        'results' => $results,
                    ],
                ], 422);
            }

            $this->refreshSessionTotals($session->id, 'count_uploaded', [
                'uploaded_by' => $user?->id,
                'uploaded_at' => now(),
                'snapshot_uploaded_at' => $request->hasFile('balance_file') ? now() : null,
                'count_uploaded_at' => now(),
                'metadata' => $this->mergeJson($session->metadata ?? null, [
                    'trading_count_uploaded_at' => now()->toDateTimeString(),
                    'trading_count_uploaded_by' => $user?->id,
                    'balance_file_uploaded' => $request->hasFile('balance_file'),
                    'snapshot_source' => 'stock_take_lines.snapshot_quantity',
                    'important' => 'Uploaded balance_file is not used to override the frozen snapshot.',
                ]),
            ]);

            DB::commit();

            $freshSession = DB::table('stock_take_sessions')
                ->where('id', $session->id)
                ->first();

            $freshLines = DB::table('stock_take_lines')
                ->where('stock_take_session_id', $session->id)
                ->orderByRaw("FIELD(variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
                ->orderBy('product_name')
                ->limit(500)
                ->get();

            return response()->json([
                'message' => 'Trading stock take sheets uploaded and variance analysis completed successfully.',
                'data' => [
                    'session' => $freshSession,
                    'lines' => [
                        'data' => $freshLines,
                        'total' => $freshLines->count(),
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                    'results' => $results,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Trading stock take upload failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadCountSheet(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'branch_id' => ['required', 'string'],
            'as_of_date' => ['required', 'date'],
            'session_id' => ['nullable', 'string'],
            'auto_create_session' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        $asOfDate = Carbon::parse($request->input('as_of_date'))->toDateString();

        $session = null;

        if ($request->input('session_id')) {
            $session = DB::table('stock_take_sessions')
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('id', $request->input('session_id'))
                ->first();
        }

        if (!$session && $request->boolean('auto_create_session', true)) {
            $session = $this->createOrUpdateDraftSession(
                companyId: $company->id,
                branchId: $branchId,
                asOfDate: $asOfDate,
                title: 'Uploaded stock take ' . $asOfDate,
                filters: []
            );
        }

        if (!$session) {
            return response()->json(['message' => 'Stock take session not found.'], 404);
        }

        if (in_array($session->status, ['approved', 'posted', 'cancelled'], true)) {
            return response()->json([
                'message' => 'This stock take session can no longer be changed.',
            ], 422);
        }

        $uploadedRows = $this->readUploadedSheet($request->file('file')->getPathname());

        if (empty($uploadedRows)) {
            return response()->json([
                'message' => 'No product rows were found in the uploaded stock take sheet.',
                'data' => [
                    'hint' => 'Upload the sheet downloaded from Stock Take. The sheet must contain a header row with product_id and counted_quantity, and product_id must not be blank.',
                ],
            ], 422);
        }

        $systemRows = collect($this->stockRows(
            companyId: $company->id,
            branchId: $branchId,
            asOfDate: $asOfDate,
            categoryId: null,
            supplierId: null,
            includeZeroStock: true
        ))->keyBy('product_id');

        $results = [];
        $errors = [];

        DB::beginTransaction();

        try {
            DB::table('stock_take_lines')->where('stock_take_session_id', $session->id)->delete();

            foreach ($uploadedRows as $index => $row) {
                $rowNo = (int) ($row['_row_number'] ?? ($index + 2));
                $productId = trim((string) ($row['product_id'] ?? ''));

                if ($productId === '') {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Product ID is required.',
                        'data' => $row,
                    ];
                    continue;
                }

                $system = $systemRows->get($productId);

                if (!$system) {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Product not found in this branch/as-of snapshot.',
                        'data' => $row,
                    ];
                    continue;
                }

                $countedQty = $this->toNumber($row['counted_quantity'] ?? null);

                if ($countedQty === null) {
                    $errors[] = [
                        'row_number' => $rowNo,
                        'message' => 'Counted quantity is required.',
                        'data' => $row,
                    ];
                    continue;
                }

                $damageQty = $this->toNumber($row['damage_quantity'] ?? 0) ?: 0;
                $notes = trim((string) ($row['count_notes'] ?? $row['notes'] ?? ''));

                $line = $this->buildVarianceLine(
                    sessionId: $session->id,
                    companyId: $company->id,
                    branchId: $branchId,
                    system: (array) $system,
                    countedQty: $countedQty,
                    damageQty: $damageQty,
                    notes: $notes
                );

                DB::table('stock_take_lines')->insert($line);

                $results[] = [
                    'row_number' => $rowNo,
                    'status' => $line['variance_type'],
                    'product_id' => $productId,
                    'product_name' => $line['product_name'],
                    'system_quantity' => $line['system_quantity'],
                    'counted_quantity' => $line['counted_quantity'],
                    'variance_quantity' => $line['variance_quantity'],
                    'variance_cost_value' => $line['variance_cost_value'],
                    'variance_selling_value' => $line['variance_selling_value'],
                ];
            }

            if (empty($results) && empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'No variance lines were created from the uploaded sheet.',
                    'data' => [
                        'hint' => 'The upload was read, but no product rows were matched. Check that product_id values exist and belong to the selected branch/as-of snapshot.',
                    ],
                ], 422);
            }

            if (!empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Stock take upload has validation errors. No variance was saved.',
                    'data' => [
                        'errors' => $errors,
                        'results' => $results,
                    ],
                ], 422);
            }

            $this->refreshSessionTotals($session->id, 'count_uploaded', [
                'uploaded_by' => $user?->id,
                'uploaded_at' => now(),
            ]);

            DB::commit();

            $freshSession = DB::table('stock_take_sessions')->where('id', $session->id)->first();
            $freshLines = DB::table('stock_take_lines')
                ->where('stock_take_session_id', $session->id)
                ->orderByRaw("FIELD(variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
                ->orderBy('product_name')
                ->limit(500)
                ->get();

            return response()->json([
                'message' => 'Stock take uploaded and variance analysis completed successfully.',
                'data' => [
                    'session' => $freshSession,
                    'lines' => [
                        'data' => $freshLines,
                        'total' => $freshLines->count(),
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                    'results' => $results,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock take upload failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function approve(Request $request, string $sessionId)
    {
        $company = app('company');
        $user = auth()->user();

        $session = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Stock take session not found.'], 404);
        }

        if (!in_array($session->status, ['count_uploaded', 'variance_review'], true)) {
            return response()->json([
                'message' => 'Only uploaded/review sessions can be approved.',
            ], 422);
        }

        DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->update([
                'status' => 'approved',
                'approved_by' => $user?->id,
                'approved_at' => now(),
                'metadata' => $this->mergeJson($session->metadata, [
                    'approval_notes' => $request->input('notes'),
                ]),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Stock take approved successfully.',
            'data' => DB::table('stock_take_sessions')->where('id', $sessionId)->first(),
        ]);
    }

    public function postAdjustments(Request $request, string $sessionId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'stock_take', $company->id);
        if ($permission !== true) {
            return $permission;
        }

        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $session = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Stock take session not found.'], 404);
        }

        if ($session->status !== 'approved') {
            return response()->json([
                'message' => 'Stock take must be approved before posting adjustments.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $isTradingMode = ($session->mode ?? 'normal') === 'trading';

            $lines = DB::table('stock_take_lines')
                ->where('stock_take_session_id', $sessionId)
                ->get();

            foreach ($lines as $line) {
                $stock = DB::table('product_stocks')
                    ->where('company_id', $company->id)
                    ->where('branch_id', $session->branch_id)
                    ->where('product_id', $line->product_id)
                    ->where('stock_area', 'front')
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    DB::table('product_stocks')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $session->branch_id,
                        'product_id' => $line->product_id,
                        'stock_area' => 'front',
                        'quantity_on_hand' => 0,
                        'reserved_quantity' => 0,
                        'available_quantity' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $stock = DB::table('product_stocks')
                        ->where('company_id', $company->id)
                        ->where('branch_id', $session->branch_id)
                        ->where('product_id', $line->product_id)
                        ->where('stock_area', 'front')
                        ->lockForUpdate()
                        ->first();
                }

                $currentQty = (float) ($stock->quantity_on_hand ?? 0);

                /*
                 |--------------------------------------------------------------------------
                 | Trading mode posting
                 |--------------------------------------------------------------------------
                 | During counting we cleared stock to zero and allowed sales to continue.
                 | Those sales have already been recorded normally by POS and have driven
                 | product_stocks negative. Therefore:
                 |
                 | final stock = counted physical stock + current trading stock
                 |
                 | Example: counted 100, sold 7 while stock take ongoing, current stock = -7
                 | final stock = 100 + (-7) = 93
                 |--------------------------------------------------------------------------
                 */
                if ($isTradingMode) {
                    $countedQty = (float) $line->counted_quantity;
                    $finalQty = Schema::hasColumn('stock_take_lines', 'final_quantity')
                        ? (float) ($line->final_quantity ?? ($countedQty + $currentQty))
                        : ($countedQty + $currentQty);

                    $movementQty = $finalQty - $currentQty;
                    $newQty = $finalQty;
                } else {
                    $newQty = (float) $line->counted_quantity;
                    $movementQty = $newQty - $currentQty;
                }

                DB::table('product_stocks')
                    ->where('id', $stock->id)
                    ->update([
                        'quantity_on_hand' => $newQty,
                        'available_quantity' => $newQty - (float) ($stock->reserved_quantity ?? 0),
                        'updated_at' => now(),
                    ]);

                if (Schema::hasColumn('stock_take_lines', 'posted_quantity_adjustment')) {
                    DB::table('stock_take_lines')
                        ->where('id', $line->id)
                        ->update([
                            'posted_quantity_adjustment' => $movementQty,
                            'updated_at' => now(),
                        ]);
                }

                if ($movementQty != 0) {
                    DB::table('stock_movements')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $session->branch_id,
                        'till_id' => null,
                        'shift_session_id' => null,
                        'product_id' => $line->product_id,
                        'stock_area' => 'front',
                        'movement_type' => 'adjustment',
                        'quantity_in' => $movementQty > 0 ? abs($movementQty) : 0,
                        'quantity_out' => $movementQty < 0 ? abs($movementQty) : 0,
                        'balance_after' => $newQty,
                        'unit_cost' => (float) $line->unit_cost,
                        'total_cost' => abs($movementQty) * (float) $line->unit_cost,
                        'currency' => $line->cost_currency,
                        'currency_code' => $line->cost_currency,
                        'reference_type' => $isTradingMode ? 'trading_stock_take_post' : 'stock_take',
                        'reference_id' => $sessionId,
                        'notes' => $isTradingMode
                            ? 'Trading stock take post. Counted stock adjusted after deducting sales made during stock take.'
                            : 'Stock take adjustment ' . $session->stock_take_no,
                        'metadata' => json_encode([
                            'stock_take_session_id' => $sessionId,
                            'stock_take_no' => $session->stock_take_no,
                            'mode' => $session->mode ?? 'normal',
                            'snapshot_quantity' => $line->snapshot_quantity ?? $line->system_quantity,
                            'counted_quantity' => $line->counted_quantity,
                            'trading_quantity' => $line->trading_quantity ?? $currentQty,
                            'final_quantity' => $newQty,
                            'posted_quantity_adjustment' => $movementQty,
                            'posted_by' => $user?->id,
                        ]),
                        'created_by' => $user?->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($isTradingMode) {
                    $original = Schema::hasColumn('stock_take_lines', 'original_allow_negative_stock')
                        ? $line->original_allow_negative_stock
                        : null;

                    if ($original !== null) {
                        DB::table('products')
                            ->where('company_id', $company->id)
                            ->where('id', $line->product_id)
                            ->update([
                                'allow_negative_stock' => (int) $original,
                                'updated_at' => now(),
                            ]);
                    }
                }

                // A posted physical count establishes the next trusted baseline,
                // including lines whose counted quantity happened to match stock.
                app(InventoryVarianceAgentService::class)->resetProduct(
                    (string) $company->id,
                    (string) $session->branch_id,
                    (string) $line->product_id,
                    'stock_take',
                    'stock_take_session',
                    (string) $sessionId,
                    $user?->id ? (string) $user->id : null,
                    (string) ($stock->stock_area ?? 'front')
                );
            }

            $update = [
                'status' => 'posted',
                'posted_by' => $user?->id,
                'posted_at' => now(),
                'updated_at' => now(),
            ];

            if ($isTradingMode && Schema::hasColumn('stock_take_sessions', 'trading_completed_at')) {
                $update['trading_completed_at'] = now();
            }

            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update($update);

            DB::commit();

            return response()->json([
                'message' => $isTradingMode
                    ? 'Trading stock take posted successfully. Sales during stock take remained recorded as normal sales and were deducted from the counted stock.'
                    : 'Stock take adjustments posted successfully.',
                'data' => DB::table('stock_take_sessions')->where('id', $sessionId)->first(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock take posting failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function dashboard(Request $request)
    {
        $company = app('company');
        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $base = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('as_of_date', '<=', $request->input('date_to')));

        $summary = (clone $base)
            ->selectRaw('
                COUNT(*) as session_count,
                SUM(line_count) as line_count,
                SUM(system_quantity) as system_quantity,
                SUM(counted_quantity) as counted_quantity,
                SUM(variance_quantity) as variance_quantity,
                SUM(system_cost_value) as system_cost_value,
                SUM(counted_cost_value) as counted_cost_value,
                SUM(variance_cost_value) as variance_cost_value,
                SUM(system_selling_value) as system_selling_value,
                SUM(counted_selling_value) as counted_selling_value,
                SUM(variance_selling_value) as variance_selling_value,
                SUM(matched_count) as matched_count,
                SUM(short_count) as short_count,
                SUM(over_count) as over_count,
                SUM(zero_count) as zero_count,
                SUM(unmatched_count) as unmatched_count,
                MAX(cost_currency) as cost_currency,
                MAX(selling_currency) as selling_currency
            ')
            ->first();

        $statusSummary = (clone $base)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $monthlyTrend = (clone $base)
            ->selectRaw('DATE_FORMAT(as_of_date, "%Y-%m") as period, COUNT(*) as sessions, SUM(ABS(variance_cost_value)) as abs_cost_variance, SUM(ABS(variance_quantity)) as abs_qty_variance')
            ->groupByRaw('DATE_FORMAT(as_of_date, "%Y-%m")')
            ->orderBy('period')
            ->limit(12)
            ->get();

        $topVariance = DB::table('stock_take_lines as stl')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stl.stock_take_session_id')
            ->where('stl.company_id', $company->id)
            ->whereIn('stl.branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sts.as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sts.as_of_date', '<=', $request->input('date_to')))
            ->selectRaw('
                stl.product_id,
                MAX(stl.product_name) as product_name,
                MAX(stl.code) as code,
                MAX(stl.category_name) as category_name,
                SUM(stl.variance_quantity) as variance_quantity,
                SUM(stl.variance_cost_value) as variance_cost_value,
                SUM(stl.variance_selling_value) as variance_selling_value,
                MAX(stl.cost_currency) as cost_currency,
                MAX(stl.selling_currency) as selling_currency
            ')
            ->groupBy('stl.product_id')
            ->orderByRaw('ABS(SUM(stl.variance_cost_value)) DESC')
            ->limit(15)
            ->get();

        return response()->json([
            'message' => 'Stock take dashboard fetched successfully.',
            'data' => [
                'summary' => $summary,
                'status_summary' => $statusSummary,
                'monthly_trend' => $monthlyTrend,
                'top_variance' => $topVariance,
            ],
        ]);
    }

    public function varianceReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $query = DB::table('stock_take_lines as stl')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stl.stock_take_session_id')
            ->where('stl.company_id', $company->id)
            ->whereIn('stl.branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sts.as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sts.as_of_date', '<=', $request->input('date_to')))
            ->when($request->input('variance_type'), fn ($q) => $q->where('stl.variance_type', $request->input('variance_type')))
            ->when($request->input('category_name'), fn ($q) => $q->where('stl.category_name', $request->input('category_name')))
            ->when($request->input('q'), function ($q) use ($request) {
                $term = trim($request->input('q'));
                $q->where(function ($sub) use ($term) {
                    $sub->where('stl.product_name', 'like', "%{$term}%")
                        ->orWhere('stl.code', 'like', "%{$term}%")
                        ->orWhere('stl.sku', 'like', "%{$term}%")
                        ->orWhere('stl.barcode', 'like', "%{$term}%")
                        ->orWhere('sts.stock_take_no', 'like', "%{$term}%");
                });
            })
            ->select([
                'sts.stock_take_no',
                'sts.as_of_date',
                'sts.status as session_status',
                'stl.product_id',
                'stl.product_name',
                'stl.code',
                'stl.sku',
                'stl.barcode',
                'stl.category_name',
                'stl.supplier_name',
                'stl.system_quantity',
                'stl.counted_quantity',
                'stl.variance_quantity',
                'stl.variance_type',
                'stl.unit_cost',
                'stl.system_cost_value',
                'stl.counted_cost_value',
                'stl.variance_cost_value',
                'stl.selling_price',
                'stl.system_selling_value',
                'stl.counted_selling_value',
                'stl.variance_selling_value',
                'stl.cost_currency',
                'stl.selling_currency',
                'stl.count_notes',
            ])
            ->orderByRaw("FIELD(stl.variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
            ->orderByDesc(DB::raw('ABS(stl.variance_cost_value)'));

        return response()->json([
            'message' => 'Stock take variance report fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 50),
        ]);
    }

    public function categoryVarianceReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $rows = DB::table('stock_take_lines as stl')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stl.stock_take_session_id')
            ->where('stl.company_id', $company->id)
            ->whereIn('stl.branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sts.as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sts.as_of_date', '<=', $request->input('date_to')))
            ->selectRaw('
                COALESCE(stl.category_name, "Uncategorised") as category_name,
                COUNT(*) as line_count,
                SUM(stl.system_quantity) as system_quantity,
                SUM(stl.counted_quantity) as counted_quantity,
                SUM(stl.variance_quantity) as variance_quantity,
                SUM(stl.system_cost_value) as system_cost_value,
                SUM(stl.counted_cost_value) as counted_cost_value,
                SUM(stl.variance_cost_value) as variance_cost_value,
                SUM(stl.system_selling_value) as system_selling_value,
                SUM(stl.counted_selling_value) as counted_selling_value,
                SUM(stl.variance_selling_value) as variance_selling_value,
                SUM(CASE WHEN stl.variance_type = "matched" THEN 1 ELSE 0 END) as matched_count,
                SUM(CASE WHEN stl.variance_type = "short" THEN 1 ELSE 0 END) as short_count,
                SUM(CASE WHEN stl.variance_type = "over" THEN 1 ELSE 0 END) as over_count,
                SUM(CASE WHEN stl.variance_type = "zero_count" THEN 1 ELSE 0 END) as zero_count,
                MAX(stl.cost_currency) as cost_currency,
                MAX(stl.selling_currency) as selling_currency
            ')
            ->groupByRaw('COALESCE(stl.category_name, "Uncategorised")')
            ->orderByRaw('ABS(SUM(stl.variance_cost_value)) DESC')
            ->get();

        return response()->json([
            'message' => 'Stock take category variance fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function branchVarianceReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $rows = DB::table('stock_take_sessions as sts')
            ->leftJoin('company_branches as br', 'br.id', '=', 'sts.branch_id')
            ->where('sts.company_id', $company->id)
            ->whereIn('sts.branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sts.as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sts.as_of_date', '<=', $request->input('date_to')))
            ->selectRaw('
                sts.branch_id,
                COALESCE(br.branch_name, br.branch_code, "Unknown Branch") as branch_name,
                COUNT(*) as session_count,
                SUM(sts.line_count) as line_count,
                SUM(sts.system_quantity) as system_quantity,
                SUM(sts.counted_quantity) as counted_quantity,
                SUM(sts.variance_quantity) as variance_quantity,
                SUM(sts.variance_cost_value) as variance_cost_value,
                SUM(sts.variance_selling_value) as variance_selling_value,
                SUM(sts.short_count) as short_count,
                SUM(sts.over_count) as over_count,
                SUM(sts.zero_count) as zero_count,
                MAX(sts.cost_currency) as cost_currency,
                MAX(sts.selling_currency) as selling_currency
            ')
            ->groupBy('sts.branch_id', DB::raw('COALESCE(br.branch_name, br.branch_code, "Unknown Branch")'))
            ->orderByRaw('ABS(SUM(sts.variance_cost_value)) DESC')
            ->get();

        return response()->json([
            'message' => 'Stock take branch variance fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function auditReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->resolveBranchIds($request, (string) $company->id);

        $rows = DB::table('stock_take_sessions as sts')
            ->leftJoin('pos_users as created_user', 'created_user.id', '=', 'sts.created_by')
            ->leftJoin('pos_users as uploaded_user', 'uploaded_user.id', '=', 'sts.uploaded_by')
            ->leftJoin('pos_users as approved_user', 'approved_user.id', '=', 'sts.approved_by')
            ->leftJoin('pos_users as posted_user', 'posted_user.id', '=', 'sts.posted_by')
            ->where('sts.company_id', $company->id)
            ->whereIn('sts.branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sts.as_of_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sts.as_of_date', '<=', $request->input('date_to')))
            ->selectRaw('
                sts.stock_take_no,
                sts.as_of_date,
                sts.title,
                sts.status,
                sts.line_count,
                sts.variance_quantity,
                sts.variance_cost_value,
                COALESCE(created_user.name, created_user.username, sts.created_by) as created_by_name,
                sts.created_at,
                COALESCE(uploaded_user.name, uploaded_user.username, sts.uploaded_by) as uploaded_by_name,
                sts.uploaded_at,
                COALESCE(approved_user.name, approved_user.username, sts.approved_by) as approved_by_name,
                sts.approved_at,
                COALESCE(posted_user.name, posted_user.username, sts.posted_by) as posted_by_name,
                sts.posted_at
            ')
            ->orderByDesc('sts.created_at')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Stock take audit report fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function exportReport(Request $request)
    {
        $type = $request->input('type') ?: 'variance';
        $format = $request->input('format') ?: 'xlsx';

        $data = match ($type) {
            'category' => $this->categoryVarianceReport($request)->getData(true)['data'],
            'branch' => $this->branchVarianceReport($request)->getData(true)['data'],
            'audit' => $this->auditReport($request)->getData(true)['data']['data'] ?? [],
            default => $this->varianceReport($request)->getData(true)['data']['data'] ?? [],
        };

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(ucwords(str_replace(['-', '_'], ' ', $type)), 0, 30));

        $headers = empty($data) ? ['No Data'] : array_keys((array) $data[0]);
        $sheet->fromArray($headers, null, 'A1');

        $rows = [];
        foreach ($data as $row) {
            $rows[] = array_values((array) $row);
        }

        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $lastRow = max(1, count($rows) + 1);
        $lastColumn = $sheet->getHighestColumn();

        $sheet->freezePane('A2');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF002B49');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $this->streamSpreadsheet($spreadsheet, 'stock_take_' . $type . '_report.' . $format, $format);
    }

    public function exportVariance(Request $request, string $sessionId)
    {
        $company = app('company');

        $session = DB::table('stock_take_sessions')
            ->where('company_id', $company->id)
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Stock take session not found.'], 404);
        }

        $lines = DB::table('stock_take_lines')
            ->where('stock_take_session_id', $sessionId)
            ->orderByRaw("FIELD(variance_type, 'short', 'over', 'zero_count', 'unmatched', 'matched')")
            ->orderBy('product_name')
            ->get();

        $spreadsheet = $this->buildVarianceWorkbook($session, $lines);
        $format = $request->input('format') ?: 'xlsx';
        $fileName = 'stock_take_variance_' . $session->stock_take_no . '.' . $format;

        return $this->streamSpreadsheet($spreadsheet, $fileName, $format);
    }

    private function stockRows(
        string $companyId,
        string $branchId,
        string $asOfDate,
        ?string $categoryId,
        ?string $supplierId,
        bool $includeZeroStock
    ): array {
        /*
         |--------------------------------------------------------------------------
         | Stock take snapshot source
         |--------------------------------------------------------------------------
         | Snapshot quantity must come from product_stocks.quantity_on_hand.
         | This matches the real system balance used by the stock report.
         |
         | Do NOT use stock_movements here for trading stock take snapshot quantity,
         | because if stock_movements and product_stocks are out of sync, the
         | variance analysis will be wrong.
         |--------------------------------------------------------------------------
         */

        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('costs') ?: 'NULL';
        $query = DB::table('products')
            ->leftJoin('product_stocks as ps', function ($join) use ($companyId, $branchId) {
                $join->on('ps.product_id', '=', 'products.id')
                    ->where('ps.company_id', '=', $companyId)
                    ->where('ps.branch_id', '=', $branchId);
            })
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->leftJoin('product_costs as costs', function ($join) use ($companyId, $branchId) {
                $join->on('costs.product_id', '=', 'products.id')
                    ->where('costs.company_id', '=', $companyId)
                    ->where('costs.branch_id', '=', $branchId);
            })
            ->where('products.company_id', $companyId)
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->when($supplierId, fn ($q) => $q->where('products.supplier_id', $supplierId));

        if (!$includeZeroStock) {
            $query->whereRaw('COALESCE(ps.quantity_on_hand, 0) != 0');
        }

        return $query
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'products.code',
                'products.sku',
                'products.barcode',
                DB::raw('COALESCE(pc.name, "Uncategorised") as category_name'),
                DB::raw('COALESCE(sup.name, "No Supplier") as supplier_name'),

                /*
                 * Correct system balance source.
                 */
                DB::raw('COALESCE(ps.quantity_on_hand, 0) as system_quantity'),

                /*
                 * Correct columns for your product_costs table.
                 */
                DB::raw('COALESCE(costs.average_cost, costs.last_cost, 0) as unit_cost'),
                DB::raw("COALESCE({$costCurrency}, 'USD') as cost_currency"),
            ])
            ->orderBy('pc.name')
            ->orderBy('products.name')
            ->get()
            ->map(function ($row) use ($companyId, $branchId) {
                $selling = $this->standardSellingPrice($companyId, $branchId, $row->product_id);

                $systemQty = (float) $row->system_quantity;
                $unitCost = (float) $row->unit_cost;
                $sellingPrice = (float) ($selling['price'] ?? 0);

                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'code' => $row->code,
                    'sku' => $row->sku,
                    'barcode' => $row->barcode,
                    'category_name' => $row->category_name,
                    'supplier_name' => $row->supplier_name,
                    'uom_name' => '',

                    /*
                     * This becomes snapshot_quantity in:
                     * - buildBalanceSnapshotWorkbook()
                     * - insertSnapshotLine()
                     */
                    'system_quantity' => $systemQty,

                    'unit_cost' => $unitCost,
                    'system_cost_value' => $systemQty * $unitCost,

                    'selling_price' => $sellingPrice,
                    'system_selling_value' => $systemQty * $sellingPrice,

                    'cost_currency' => $row->cost_currency ?: 'USD',
                    'selling_currency' => $selling['currency'] ?? ($row->cost_currency ?: 'USD'),
                ];
            })
            ->all();
    }

    private function standardSellingPrice(string $companyId, string $branchId, string $productId): array
    {
        if (!Schema::hasTable('product_prices')) {
            return ['price' => 0, 'currency' => 'USD'];
        }

        $price = DB::table('product_prices as pp')
            ->leftJoin('price_levels as pl', 'pl.id', '=', 'pp.price_level_id')
            ->where('pp.company_id', $companyId)
            ->where('pp.product_id', $productId)
            ->where('pp.is_active', 1)
            ->whereNull('pp.deleted_at')
            ->where(function ($q) use ($branchId) {
                $q->where('pp.branch_id', $branchId)
                    ->orWhereNull('pp.branch_id');
            })
            ->orderByRaw("
                CASE
                    WHEN LOWER(COALESCE(pl.name, '')) = 'standard' THEN 0
                    WHEN LOWER(COALESCE(pl.code, '')) = 'standard' THEN 1
                    WHEN COALESCE(pl.is_default, 0) = 1 THEN 2
                    WHEN COALESCE(pp.is_default, 0) = 1 THEN 3
                    ELSE 4
                END
            ")
            ->orderByRaw("CASE WHEN pp.branch_id = ? THEN 0 ELSE 1 END", [$branchId])
            ->orderByDesc('pp.updated_at')
            ->first();

        return [
            'price' => (float) ($price->price ?? 0),
            'currency' => $price->currency ?? 'USD',
        ];
    }

    private function buildCountSheetWorkbook(object $session, array $rows, string $countMode, string $systemQtyMode = 'actual'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock Count');

        $sheet->fromArray([
            ['Stock Take No', $session->stock_take_no, '', 'As Of Date', $session->as_of_date],
            ['Session ID', $session->id, '', 'Branch ID', $session->branch_id],
            ['Instructions', 'Counted Quantity is next to Product Name for easy counting. Only edit Counted Quantity, Damage Quantity and Count Notes. Do not change Product ID. If System Quantity is downloaded as zero, backend still calculates variance using actual system stock.'],
            [],
        ], null, 'A1');

        $headers = [
            'product_id',
            'product_name',
            'counted_quantity',
            'code',
            'sku',
            'barcode',
            'category_name',
            'supplier_name',
            'system_quantity',
            'damage_quantity',
            'variance_quantity',
            'unit_cost',
            'variance_cost_value',
            'selling_price',
            'variance_selling_value',
            'cost_currency',
            'selling_currency',
            'count_notes',
        ];

        $sheet->fromArray($headers, null, 'A5');

        $data = [];

        foreach ($rows as $row) {
            $actualSystemQty = (float) $row['system_quantity'];
            $displaySystemQty = $systemQtyMode === 'zero' ? 0 : $actualSystemQty;

            $countedQty = match ($countMode) {
                'zero' => 0,
                'system' => $displaySystemQty,
                default => '',
            };

            $data[] = [
                $row['product_id'],
                $row['product_name'],
                $countedQty,
                $row['code'],
                $row['sku'],
                $row['barcode'],
                $row['category_name'],
                $row['supplier_name'],
                $displaySystemQty,
                0,
                '',
                $row['unit_cost'],
                '',
                $row['selling_price'],
                '',
                $row['cost_currency'],
                $row['selling_currency'],
                '',
            ];
        }

        if (!empty($data)) {
            $sheet->fromArray($data, null, 'A6');

            $lastRow = 5 + count($data);

            for ($row = 6; $row <= $lastRow; $row++) {
                $sheet->setCellValue("K{$row}", "=C{$row}-I{$row}");
                $sheet->setCellValue("M{$row}", "=K{$row}*L{$row}");
                $sheet->setCellValue("O{$row}", "=K{$row}*N{$row}");
            }
        }

        $this->styleStockSheet($sheet, count($data) + 5);

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Step', 'Description'],
            ['1', 'Download this sheet for the correct branch and as-of date.'],
            ['2', 'If count mode is zero, counted quantities start at zero. You can also download System Quantity as zero to hide expected balances from counters.'],
            ['3', 'Physically count stock and enter values in Counted Quantity next to the product name.'],
            ['4', 'Enter damaged stock in Damage Quantity if required.'],
            ['5', 'Upload the sheet back into Stock Take.'],
            ['6', 'Review variance analysis.'],
            ['7', 'Approve then post adjustments to update stock balances.'],
        ], null, 'A1');

        return $spreadsheet;
    }

    private function buildVarianceWorkbook(object $session, $lines): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Summary');

        $summary->fromArray([
            ['Stock Take Variance Report'],
            ['Stock Take No', $session->stock_take_no],
            ['Status', $session->status],
            ['As Of Date', $session->as_of_date],
            ['System Qty', $session->system_quantity],
            ['Counted Qty', $session->counted_quantity],
            ['Variance Qty', $session->variance_quantity],
            ['System Cost Value', $session->system_cost_value],
            ['Counted Cost Value', $session->counted_cost_value],
            ['Variance Cost Value', $session->variance_cost_value],
            ['System Selling Value', $session->system_selling_value],
            ['Counted Selling Value', $session->counted_selling_value],
            ['Variance Selling Value', $session->variance_selling_value],
        ], null, 'A1');

        $linesSheet = $spreadsheet->createSheet();
        $linesSheet->setTitle('Variance Lines');

        $headers = [
            'product_id',
            'product_name',
            'code',
            'sku',
            'barcode',
            'category_name',
            'supplier_name',
            'system_quantity',
            'counted_quantity',
            'variance_quantity',
            'variance_type',
            'unit_cost',
            'variance_cost_value',
            'selling_price',
            'variance_selling_value',
            'cost_currency',
            'selling_currency',
            'count_notes',
        ];

        $linesSheet->fromArray($headers, null, 'A1');

        $data = [];

        foreach ($lines as $line) {
            $data[] = [
                $line->product_id,
                $line->product_name,
                $line->code,
                $line->sku,
                $line->barcode,
                $line->category_name,
                $line->supplier_name,
                $line->system_quantity,
                $line->counted_quantity,
                $line->variance_quantity,
                $line->variance_type,
                $line->unit_cost,
                $line->variance_cost_value,
                $line->selling_price,
                $line->variance_selling_value,
                $line->cost_currency,
                $line->selling_currency,
                $line->count_notes,
            ];
        }

        if (!empty($data)) {
            $linesSheet->fromArray($data, null, 'A2');
        }

        $this->styleStockSheet($linesSheet, count($data) + 1);

        return $spreadsheet;
    }

    private function styleStockSheet($sheet, int $lastRow): void
    {
        $highestColumn = $sheet->getHighestColumn();

        $sheet->freezePane('A6');
        $sheet->getStyle("A1:{$highestColumn}5")->getFont()->setBold(true);
        $sheet->getStyle("A5:{$highestColumn}5")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF002B49');
        $sheet->getStyle("A5:{$highestColumn}5")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A5:{$highestColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle("A5:{$highestColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range('A', $highestColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->getStyle("C6:C{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle("I6:O{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
    }

    private function readUploadedSheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        /*
         |--------------------------------------------------------------------------
         | Robust sheet selection
         |--------------------------------------------------------------------------
         | Some Excel files are uploaded after users rename sheets or save as CSV.
         | We first look for the expected Stock Count sheet, then scan all sheets
         | until we find a header row containing product_id and counted_quantity.
         |--------------------------------------------------------------------------
         */
        $candidateSheets = [];

        if ($spreadsheet->getSheetByName('Stock Count')) {
            $candidateSheets[] = $spreadsheet->getSheetByName('Stock Count');
        }

        foreach ($spreadsheet->getAllSheets() as $candidate) {
            if (!in_array($candidate, $candidateSheets, true)) {
                $candidateSheets[] = $candidate;
            }
        }

        foreach ($candidateSheets as $sheet) {
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();

            if (!$highestRow || !$highestColumn) {
                continue;
            }

            $rows = $sheet->rangeToArray(
                "A1:{$highestColumn}{$highestRow}",
                null,
                true,
                true,
                false
            );

            $headerRowIndex = null;
            $headers = [];

            foreach ($rows as $rowIndex => $row) {
                $normalised = array_map(
                    fn ($value) => $this->normaliseHeader((string) $value),
                    $row
                );

                if (
                    in_array('product_id', $normalised, true) &&
                    in_array('counted_quantity', $normalised, true)
                ) {
                    $headerRowIndex = $rowIndex;
                    $headers = $normalised;
                    break;
                }
            }

            if ($headerRowIndex === null) {
                continue;
            }

            $mapped = [];

            for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
                $row = $rows[$i] ?? [];
                $record = [];

                foreach ($headers as $position => $header) {
                    if (!$header) {
                        continue;
                    }

                    $record[$header] = $row[$position] ?? null;
                }

                $productId = trim((string) ($record['product_id'] ?? ''));

                /*
                 |--------------------------------------------------------------------------
                 | Skip only rows that truly do not contain a product.
                 |--------------------------------------------------------------------------
                 | Do not skip rows where counted_quantity is 0, because 0 is a
                 | valid stock take count.
                 |--------------------------------------------------------------------------
                 */
                if ($productId === '') {
                    continue;
                }

                $record['_row_number'] = $i + 1;
                $mapped[] = $record;
            }

            if (!empty($mapped)) {
                return $mapped;
            }
        }

        return [];
    }

    private function buildVarianceLine(
        string $sessionId,
        string $companyId,
        string $branchId,
        array $system,
        float $countedQty,
        float $damageQty,
        string $notes
    ): array {
        $systemQty = (float) $system['system_quantity'];
        $varianceQty = $countedQty - $systemQty;

        $unitCost = (float) $system['unit_cost'];
        $sellingPrice = (float) $system['selling_price'];

        $varianceType = 'matched';

        if ($countedQty == 0 && $systemQty != 0) {
            $varianceType = 'zero_count';
        } elseif ($varianceQty < 0) {
            $varianceType = 'short';
        } elseif ($varianceQty > 0) {
            $varianceType = 'over';
        }

        return [
            'id' => (string) Str::uuid(),
            'stock_take_session_id' => $sessionId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $system['product_id'],
            'product_name' => $system['product_name'],
            'code' => $system['code'],
            'sku' => $system['sku'],
            'barcode' => $system['barcode'],
            'category_name' => $system['category_name'],
            'supplier_name' => $system['supplier_name'],
            'uom_name' => $system['uom_name'] ?? '',
            'system_quantity' => $systemQty,
            'counted_quantity' => $countedQty,
            'variance_quantity' => $varianceQty,
            'unit_cost' => $unitCost,
            'system_cost_value' => $systemQty * $unitCost,
            'counted_cost_value' => $countedQty * $unitCost,
            'variance_cost_value' => $varianceQty * $unitCost,
            'selling_price' => $sellingPrice,
            'system_selling_value' => $systemQty * $sellingPrice,
            'counted_selling_value' => $countedQty * $sellingPrice,
            'variance_selling_value' => $varianceQty * $sellingPrice,
            'cost_currency' => $system['cost_currency'],
            'selling_currency' => $system['selling_currency'],
            'variance_type' => $varianceType,
            'count_notes' => $notes,
            'metadata' => json_encode([
                'damage_quantity' => $damageQty,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function refreshSessionTotals(string $sessionId, string $status, array $extra = []): void
    {
        $totals = DB::table('stock_take_lines')
            ->where('stock_take_session_id', $sessionId)
            ->selectRaw('
                COUNT(*) as line_count,
                SUM(system_quantity) as system_quantity,
                SUM(counted_quantity) as counted_quantity,
                SUM(variance_quantity) as variance_quantity,
                SUM(system_cost_value) as system_cost_value,
                SUM(counted_cost_value) as counted_cost_value,
                SUM(variance_cost_value) as variance_cost_value,
                SUM(system_selling_value) as system_selling_value,
                SUM(counted_selling_value) as counted_selling_value,
                SUM(variance_selling_value) as variance_selling_value,
                SUM(CASE WHEN variance_type = "matched" THEN 1 ELSE 0 END) as matched_count,
                SUM(CASE WHEN variance_type = "short" THEN 1 ELSE 0 END) as short_count,
                SUM(CASE WHEN variance_type = "over" THEN 1 ELSE 0 END) as over_count,
                SUM(CASE WHEN variance_type = "zero_count" THEN 1 ELSE 0 END) as zero_count,
                SUM(CASE WHEN variance_type = "unmatched" THEN 1 ELSE 0 END) as unmatched_count,
                MAX(cost_currency) as cost_currency,
                MAX(selling_currency) as selling_currency
            ')
            ->first();

        DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->update(array_merge([
                'status' => $status,
                'line_count' => (int) ($totals->line_count ?? 0),
                'system_quantity' => (float) ($totals->system_quantity ?? 0),
                'counted_quantity' => (float) ($totals->counted_quantity ?? 0),
                'variance_quantity' => (float) ($totals->variance_quantity ?? 0),
                'system_cost_value' => (float) ($totals->system_cost_value ?? 0),
                'counted_cost_value' => (float) ($totals->counted_cost_value ?? 0),
                'variance_cost_value' => (float) ($totals->variance_cost_value ?? 0),
                'system_selling_value' => (float) ($totals->system_selling_value ?? 0),
                'counted_selling_value' => (float) ($totals->counted_selling_value ?? 0),
                'variance_selling_value' => (float) ($totals->variance_selling_value ?? 0),
                'matched_count' => (int) ($totals->matched_count ?? 0),
                'short_count' => (int) ($totals->short_count ?? 0),
                'over_count' => (int) ($totals->over_count ?? 0),
                'zero_count' => (int) ($totals->zero_count ?? 0),
                'unmatched_count' => (int) ($totals->unmatched_count ?? 0),
                'cost_currency' => $totals->cost_currency ?? null,
                'selling_currency' => $totals->selling_currency ?? null,
                'updated_at' => now(),
            ], $extra));
    }


    private function buildBalanceSnapshotWorkbook(object $session, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Balance Snapshot');

        $sheet->fromArray([
            ['Stock Take No', $session->stock_take_no, '', 'As Of Date', $session->as_of_date],
            ['Session ID', $session->id, '', 'Branch ID', $session->branch_id],
            ['Instructions', 'This is the frozen system stock balance before trading stock take starts. Do not edit product_id. Upload this file together with the counted sheet if you want the original snapshot to be revalidated.'],
            [],
        ], null, 'A1');

        $headers = [
            'product_id',
            'product_name',
            'snapshot_quantity',
            'code',
            'sku',
            'barcode',
            'category_name',
            'supplier_name',
            'unit_cost',
            'snapshot_cost_value',
            'selling_price',
            'snapshot_selling_value',
            'cost_currency',
            'selling_currency',
        ];

        $sheet->fromArray($headers, null, 'A5');

        $data = [];

        foreach ($rows as $row) {
            $snapshotQty = (float) $row['system_quantity'];

            $data[] = [
                $row['product_id'],
                $row['product_name'],
                $snapshotQty,
                $row['code'],
                $row['sku'],
                $row['barcode'],
                $row['category_name'],
                $row['supplier_name'],
                $row['unit_cost'],
                $snapshotQty * (float) $row['unit_cost'],
                $row['selling_price'],
                $snapshotQty * (float) $row['selling_price'],
                $row['cost_currency'],
                $row['selling_currency'],
            ];
        }

        if (!empty($data)) {
            $sheet->fromArray($data, null, 'A6');
        }

        $this->styleStockSheet($sheet, count($data) + 5);

        return $spreadsheet;
    }

    private function insertSnapshotLine(
        string $sessionId,
        string $companyId,
        string $branchId,
        array $row,
        int $originalAllowNegative
    ): void {
        $snapshotQty = (float) $row['system_quantity'];
        $unitCost = (float) $row['unit_cost'];
        $sellingPrice = (float) $row['selling_price'];

        $payload = [
            'id' => (string) Str::uuid(),
            'stock_take_session_id' => $sessionId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'code' => $row['code'],
            'sku' => $row['sku'],
            'barcode' => $row['barcode'],
            'category_name' => $row['category_name'],
            'supplier_name' => $row['supplier_name'],
            'uom_name' => $row['uom_name'] ?? '',
            'snapshot_quantity' => $snapshotQty,
            'system_quantity' => $snapshotQty,
            'counted_quantity' => 0,
            'variance_quantity' => 0 - $snapshotQty,
            'trading_quantity' => 0,
            'trading_sales_quantity' => 0,
            'trading_returns_quantity' => 0,
            'final_quantity' => 0,
            'posted_quantity_adjustment' => 0,
            'unit_cost' => $unitCost,
            'system_cost_value' => $snapshotQty * $unitCost,
            'counted_cost_value' => 0,
            'variance_cost_value' => (0 - $snapshotQty) * $unitCost,
            'selling_price' => $sellingPrice,
            'system_selling_value' => $snapshotQty * $sellingPrice,
            'counted_selling_value' => 0,
            'variance_selling_value' => (0 - $snapshotQty) * $sellingPrice,
            'cost_currency' => $row['cost_currency'],
            'selling_currency' => $row['selling_currency'],
            'variance_type' => $snapshotQty == 0 ? 'matched' : 'zero_count',
            'count_notes' => null,
            'original_allow_negative_stock' => $originalAllowNegative,
            'metadata' => json_encode([
                'snapshot_created_at' => now()->toDateTimeString(),
                'original_allow_negative_stock' => $originalAllowNegative,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('stock_take_lines')->insert(array_intersect_key(
            $payload,
            array_flip(Schema::getColumnListing('stock_take_lines'))
        ));
    }

    private function updateSessionColumns(string $sessionId, array $payload): void
    {
        $columns = Schema::getColumnListing('stock_take_sessions');

        DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->update(array_intersect_key(
                array_merge($payload, ['updated_at' => now()]),
                array_flip($columns)
            ));
    }


    private function createOrUpdateDraftSession(
        string $companyId,
        string $branchId,
        string $asOfDate,
        string $title,
        array $filters
    ): object {
        $existing = DB::table('stock_take_sessions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('as_of_date', $asOfDate)
            ->where('status', 'draft')
            ->latest()
            ->first();

        if ($existing) {
            DB::table('stock_take_sessions')
                ->where('id', $existing->id)
                ->update([
                    'title' => $title,
                    'filters' => json_encode($filters),
                    'updated_at' => now(),
                ]);

            return DB::table('stock_take_sessions')->where('id', $existing->id)->first();
        }

        $id = (string) Str::uuid();

        DB::table('stock_take_sessions')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'stock_take_no' => $this->generateStockTakeNo($companyId, $branchId, $asOfDate),
            'as_of_date' => $asOfDate,
            'title' => $title,
            'status' => 'draft',
            'filters' => json_encode($filters),
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('stock_take_sessions')->where('id', $id)->first();
    }

    private function generateStockTakeNo(string $companyId, string $branchId, string $asOfDate): string
    {
        $date = Carbon::parse($asOfDate)->format('ymd');

        $count = DB::table('stock_take_sessions')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereDate('as_of_date', $asOfDate)
                ->count() + 1;

        return 'STK-' . $date . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function streamSpreadsheet(Spreadsheet $spreadsheet, string $fileName, string $format): StreamedResponse
    {
        $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function resolveBranchIds(Request $request, string $companyId): array
    {
        $requestedBranchIds = $request->filled('branch_id')
            ? [$request->input('branch_id')]
            : [];

        return app(BusinessModeBranchScopeService::class)
            ->resolve($request, $companyId, $requestedBranchIds)['branch_ids'];
    }

    private function normaliseHeader(string $header): string
    {
        return Str::of($header)->lower()->replace([' ', '-', '.'], '_')->replaceMatches('/[^a-z0-9_]/', '')->toString();
    }

    private function toNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }

    private function mergeJson($json, array $data): string
    {
        $existing = is_array($json) ? $json : (json_decode($json ?: '{}', true) ?: []);

        return json_encode(array_merge($existing, $data));
    }
}
