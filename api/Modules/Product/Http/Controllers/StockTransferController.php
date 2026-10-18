<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\CompanySettingService;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $query = $this->transferBaseQuery($company->id, $branchIds)
            ->when($request->input('status'), fn ($q) => $q->where('sth.status', $request->input('status')))
            ->when($request->input('from_branch_id'), fn ($q) => $q->where('sth.from_branch_id', $request->input('from_branch_id')))
            ->when($request->input('to_branch_id'), fn ($q) => $q->where('sth.to_branch_id', $request->input('to_branch_id')))
            ->when($request->input('from_warehouse_id'), fn ($q) => $q->where('sth.from_warehouse_id', $request->input('from_warehouse_id')))
            ->when($request->input('to_warehouse_id'), fn ($q) => $q->where('sth.to_warehouse_id', $request->input('to_warehouse_id')))
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->when($request->input('q'), function ($q) use ($request) {
                $term = trim((string) $request->input('q'));

                $q->where(function ($sub) use ($term) {
                    $sub->where('sth.transfer_no', 'like', "%{$term}%")
                        ->orWhere('from_br.branch_name', 'like', "%{$term}%")
                        ->orWhere('to_br.branch_name', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('sth.created_at');

        return response()->json([
            'message' => 'Stock transfers fetched successfully.',
            'data' => $query->paginate((int) $request->input('per_page', 25)),
        ]);
    }

    public function show(string $id)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds(request(), (string) $company->id);

        $transfer = $this->transferBaseQuery($company->id, $branchIds)
            ->where('sth.id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        $lines = DB::table('stock_transfer_lines')
            ->where('company_id', $company->id)
            ->where('stock_transfer_id', $id)
            ->orderBy('product_name')
            ->get();

        return response()->json([
            'message' => 'Stock transfer fetched successfully.',
            'data' => [
                'transfer' => $transfer,
                'lines' => $lines,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'transfer_date' => ['nullable', 'date'],
            'from_location_type' => ['required', Rule::in(['branch', 'warehouse'])],
            'to_location_type' => ['required', Rule::in(['branch', 'warehouse'])],
            'from_branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'to_branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'from_warehouse_id' => ['nullable', 'required_if:from_location_type,warehouse', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'to_warehouse_id' => ['nullable', 'required_if:to_location_type,warehouse', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'request_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.quantity_requested' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.line_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $branchIds = $this->reportBranchIds($request, (string) $company->id);
        $this->assertTransferEndpoints($company->id, $branchIds, $request->from_location_type, $request->from_branch_id, $request->from_warehouse_id, $request->to_location_type, $request->to_branch_id, $request->to_warehouse_id);

        DB::beginTransaction();

        try {
            $id = (string) Str::uuid();
            $transferDate = $request->input('transfer_date')
                ? Carbon::parse($request->input('transfer_date'))->toDateString()
                : now()->toDateString();

            $requiresApproval = app(CompanySettingService::class)->get($company->id, 'stock_transfer_requires_approval', true);
            DB::table('stock_transfer_headers')->insert([
                'id' => $id,
                'company_id' => $company->id,
                'transfer_no' => $this->generateTransferNo($company->id, $transferDate),
                'transfer_date' => $transferDate,
                'from_branch_id' => $request->input('from_branch_id'),
                'from_warehouse_id' => $request->input('from_warehouse_id'),
                'from_location_type' => $request->input('from_location_type'),
                'to_branch_id' => $request->input('to_branch_id'),
                'to_warehouse_id' => $request->input('to_warehouse_id'),
                'to_location_type' => $request->input('to_location_type'),
                'status' => $requiresApproval ? 'requested' : 'approved',
                'request_notes' => $request->input('request_notes'),
                'requested_by' => $user?->id,
                'requested_at' => now(),
                'approved_by' => $requiresApproval ? null : $user?->id,
                'approved_at' => $requiresApproval ? null : now(),
                'approval_notes' => $requiresApproval ? null : 'Automatically approved by company inventory policy.',
                'metadata' => json_encode([
                    'created_from' => 'stock_transfer_module',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($request->input('lines', []) as $line) {
                $productLine = $this->buildProductLine(
                    companyId: $company->id,
                    branchId: $request->input('from_branch_id'),
                    productId: $line['product_id'],
                    qty: (float) $line['quantity_requested']
                );

                DB::table('stock_transfer_lines')->insert(array_merge($productLine, [
                    'id' => (string) Str::uuid(),
                    'stock_transfer_id' => $id,
                    'company_id' => $company->id,
                    'quantity_requested' => (float) $line['quantity_requested'],
                    'quantity_approved' => 0,
                    'quantity_dispatched' => 0,
                    'quantity_received' => 0,
                    'quantity_variance' => 0,
                    'line_notes' => $line['line_notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $this->refreshTransferTotals($id);

            DB::commit();

            return response()->json([
                'message' => 'Stock transfer request created successfully.',
                'data' => $this->show($id)->getData(true)['data'],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock transfer creation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if (!in_array($transfer->status, ['draft', 'requested'], true)) {
            return response()->json([
                'message' => 'Only draft/requested transfers can be edited.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'transfer_date' => ['nullable', 'date'],
            'from_location_type' => ['required', Rule::in(['branch', 'warehouse'])],
            'to_location_type' => ['required', Rule::in(['branch', 'warehouse'])],
            'from_branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'to_branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'from_warehouse_id' => ['nullable', 'required_if:from_location_type,warehouse', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'to_warehouse_id' => ['nullable', 'required_if:to_location_type,warehouse', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'request_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.quantity_requested' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.line_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $this->assertTransferEndpoints($company->id, $branchIds, $request->from_location_type, $request->from_branch_id, $request->from_warehouse_id, $request->to_location_type, $request->to_branch_id, $request->to_warehouse_id);

        DB::beginTransaction();

        try {
            DB::table('stock_transfer_headers')
                ->where('id', $id)
                ->update([
                    'transfer_date' => Carbon::parse($request->input('transfer_date') ?: $transfer->transfer_date)->toDateString(),
                    'from_branch_id' => $request->input('from_branch_id'),
                    'from_warehouse_id' => $request->input('from_warehouse_id'),
                    'from_location_type' => $request->input('from_location_type'),
                    'to_branch_id' => $request->input('to_branch_id'),
                    'to_warehouse_id' => $request->input('to_warehouse_id'),
                    'to_location_type' => $request->input('to_location_type'),
                    'request_notes' => $request->input('request_notes'),
                    'updated_at' => now(),
                ]);

            DB::table('stock_transfer_lines')->where('stock_transfer_id', $id)->delete();

            foreach ($request->input('lines', []) as $line) {
                $productLine = $this->buildProductLine(
                    companyId: $company->id,
                    branchId: $request->input('from_branch_id'),
                    productId: $line['product_id'],
                    qty: (float) $line['quantity_requested']
                );

                DB::table('stock_transfer_lines')->insert(array_merge($productLine, [
                    'id' => (string) Str::uuid(),
                    'stock_transfer_id' => $id,
                    'company_id' => $company->id,
                    'quantity_requested' => (float) $line['quantity_requested'],
                    'line_notes' => $line['line_notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $this->refreshTransferTotals($id);

            DB::commit();

            return response()->json([
                'message' => 'Stock transfer updated successfully.',
                'data' => $this->show($id)->getData(true)['data'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock transfer update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function approve(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if ($transfer->status !== 'requested') {
            return response()->json([
                'message' => 'Only requested transfers can be approved.',
            ], 422);
        }
        $user?->loadMissing('role');
        $normaliseRole = static fn ($value) => strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
        $isSuperAdministrator = (bool) ($user?->is_super_admin ?? false)
            || in_array($normaliseRole($user?->user_type ?? ''), ['super_admin', 'super_administrator'], true)
            || in_array($normaliseRole($user?->role?->name ?? ''), ['super_admin', 'super_administrator'], true);
        $allowRequesterApproval = app(CompanySettingService::class)->get($company->id, 'stock_transfer_allow_requester_approval', false);
        if (!$isSuperAdministrator && !$allowRequesterApproval && (string) $transfer->requested_by === (string) $user?->id) {
            return response()->json(['message' => 'Separation of duties is enabled: the transfer requester cannot approve this request.'], 403);
        }

        DB::beginTransaction();

        try {
            $lines = $request->input('lines', []);

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    DB::table('stock_transfer_lines')
                        ->where('stock_transfer_id', $id)
                        ->where('product_id', $line['product_id'])
                        ->update([
                            'quantity_approved' => (float) ($line['quantity_approved'] ?? 0),
                            'updated_at' => now(),
                        ]);
                }
            } else {
                DB::table('stock_transfer_lines')
                    ->where('stock_transfer_id', $id)
                    ->update([
                        'quantity_approved' => DB::raw('quantity_requested'),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('stock_transfer_headers')
                ->where('id', $id)
                ->update([
                    'status' => 'approved',
                    'approval_notes' => $isSuperAdministrator && (string) $transfer->requested_by === (string) $user?->id
                        ? trim('Super administrator override. ' . (string) $request->input('approval_notes'))
                        : $request->input('approval_notes'),
                    'approved_by' => $user?->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->refreshTransferTotals($id);

            DB::commit();

            return response()->json([
                'message' => 'Stock transfer approved successfully.',
                'data' => $this->show($id)->getData(true)['data'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock transfer approval failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function dispatch(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if ($transfer->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved transfers can be dispatched.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payloadLines = collect($request->input('lines', []))->keyBy('product_id');

            $lines = DB::table('stock_transfer_lines')
                ->where('stock_transfer_id', $id)
                ->get();

            foreach ($lines as $line) {
                $dispatchQty = $payloadLines->has($line->product_id)
                    ? (float) ($payloadLines->get($line->product_id)['quantity_dispatched'] ?? 0)
                    : (float) $line->quantity_approved;

                if ($dispatchQty < 0) {
                    throw new \Exception('Dispatch quantity cannot be negative.');
                }

                if ($dispatchQty > (float) $line->quantity_approved) {
                    throw new \Exception("Dispatch quantity cannot exceed approved quantity for {$line->product_name}.");
                }

                $allocatedByBatch = $this->allocateTransferBatches($company->id, $transfer, $line, $dispatchQty, $user?->id);

                $this->decreaseBranchStock(
                    companyId: $company->id,
                    branchId: $transfer->from_branch_id,
                    productId: $line->product_id,
                    quantity: $dispatchQty,
                    transferId: $id,
                    transferNo: $transfer->transfer_no,
                    unitCost: (float) $line->unit_cost,
                    currency: $line->cost_currency,
                    userId: $user?->id,
                    stockArea: ($transfer->from_location_type ?? 'warehouse') === 'branch' ? 'front' : 'back',
                    writeMovement: !$allocatedByBatch
                );

                DB::table('stock_transfer_lines')
                    ->where('id', $line->id)
                    ->update([
                        'quantity_dispatched' => $dispatchQty,
                        'cost_value' => $dispatchQty * (float) $line->unit_cost,
                        'selling_value' => $dispatchQty * (float) $line->selling_price,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('stock_transfer_headers')
                ->where('id', $id)
                ->update([
                    'status' => 'dispatched',
                    'dispatch_notes' => $request->input('dispatch_notes'),
                    'dispatched_by' => $user?->id,
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->refreshTransferTotals($id);

            DB::commit();

            return response()->json([
                'message' => 'Stock transfer dispatched successfully.',
                'data' => $this->show($id)->getData(true)['data'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock transfer dispatch failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function receive(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if (!in_array($transfer->status, ['dispatched', 'partially_received'], true)) {
            return response()->json([
                'message' => 'Only dispatched transfers can be received.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payloadLines = collect($request->input('lines', []))->keyBy('product_id');

            $lines = DB::table('stock_transfer_lines')
                ->where('stock_transfer_id', $id)
                ->get();

            foreach ($lines as $line) {
                $targetReceivedQty = $payloadLines->has($line->product_id)
                    ? (float) ($payloadLines->get($line->product_id)['quantity_received'] ?? 0)
                    : (float) $line->quantity_dispatched;

                if ($targetReceivedQty < (float) $line->quantity_received) {
                    throw new \Exception('Cumulative received quantity cannot be reduced.');
                }

                $receiveQty = $targetReceivedQty - (float) $line->quantity_received;
                if ($receiveQty < 0) {
                    throw new \Exception('Received quantity cannot be negative.');
                }

                if ($targetReceivedQty > (float) $line->quantity_dispatched) {
                    throw new \Exception("Received quantity cannot exceed dispatched quantity for {$line->product_name}.");
                }

                $varianceQty = $targetReceivedQty - (float) $line->quantity_dispatched;

                $receivedByBatch = $this->receiveTransferBatches($company->id, $transfer, $line, $receiveQty, $user?->id);

                $this->increaseBranchStock(
                    companyId: $company->id,
                    branchId: $transfer->to_branch_id,
                    productId: $line->product_id,
                    quantity: $receiveQty,
                    transferId: $id,
                    transferNo: $transfer->transfer_no,
                    unitCost: (float) $line->unit_cost,
                    currency: $line->cost_currency,
                    userId: $user?->id,
                    stockArea: ($transfer->to_location_type ?? 'warehouse') === 'branch' ? 'front' : 'back',
                    writeMovement: !$receivedByBatch
                );

                DB::table('stock_transfer_lines')
                    ->where('id', $line->id)
                    ->update([
                        'quantity_received' => $targetReceivedQty,
                        'quantity_variance' => $varianceQty,
                        'variance_cost_value' => $varianceQty * (float) $line->unit_cost,
                        'variance_selling_value' => $varianceQty * (float) $line->selling_price,
                        'updated_at' => now(),
                    ]);
            }

            $anyVariance = DB::table('stock_transfer_lines')
                ->where('stock_transfer_id', $id)
                ->whereRaw('quantity_received != quantity_dispatched')
                ->exists();

            DB::table('stock_transfer_headers')
                ->where('id', $id)
                ->update([
                    'status' => $anyVariance ? 'partially_received' : 'received',
                    'receiving_notes' => $request->input('receiving_notes'),
                    'received_by' => $user?->id,
                    'received_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->refreshTransferTotals($id);

            DB::commit();

            return response()->json([
                'message' => $anyVariance
                    ? 'Stock transfer received with variance.'
                    : 'Stock transfer received successfully.',
                'data' => $this->show($id)->getData(true)['data'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Stock transfer receiving failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if (in_array($transfer->status, ['received', 'partially_received'], true)) {
            return response()->json([
                'message' => 'Received transfers cannot be cancelled.',
            ], 422);
        }

        if ($transfer->status === 'dispatched') {
            return response()->json([
                'message' => 'Dispatched transfers cannot be cancelled. Receive them or process a return transfer.',
            ], 422);
        }

        DB::table('stock_transfer_headers')
            ->where('id', $id)
            ->update([
                'status' => 'cancelled',
                'cancel_notes' => $request->input('cancel_notes'),
                'cancelled_by' => $user?->id,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Stock transfer cancelled successfully.',
            'data' => $this->show($id)->getData(true)['data'],
        ]);
    }

    public function reject(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $transfer = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->where('id', $id)
            ->first();

        if (!$transfer) {
            return response()->json(['message' => 'Stock transfer not found.'], 404);
        }

        if ($transfer->status !== 'requested') {
            return response()->json([
                'message' => 'Only requested transfers can be rejected.',
            ], 422);
        }

        DB::table('stock_transfer_headers')
            ->where('id', $id)
            ->update([
                'status' => 'rejected',
                'approval_notes' => $request->input('rejection_notes'),
                'approved_by' => $user?->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Stock transfer rejected successfully.',
            'data' => $this->show($id)->getData(true)['data'],
        ]);
    }

    public function lookupProducts(Request $request)
    {
        $company = app('company');
        $branchId = $request->input('branch_id') ?: $request->header('X-Branch-Id');
        $warehouseId = $request->input('warehouse_id');
        $sourceType = $request->input('source_type', $warehouseId ? 'warehouse' : 'branch');
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            is_scalar($branchId) && trim((string) $branchId) !== '' ? [trim((string) $branchId)] : []
        );
        $branchIds = $scope['branch_ids'];

        if ($warehouseId) {
            $warehouseExists = DB::table('inventory_warehouses')
                ->where('company_id', $company->id)
                ->where('id', $warehouseId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($branchIds) {
                    $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
                })
                ->exists();
            if (!$warehouseExists) {
                return response()->json(['message' => 'Warehouse not found in the active business-mode workspace.'], 404);
            }
        }

        $stockSource = $warehouseId
            ? DB::table('inventory_location_balances')->where('company_id', $company->id)->where('warehouse_id', $warehouseId)
                ->whereIn('branch_id', $branchIds)
                ->selectRaw('product_id, SUM(quantity_on_hand) as quantity_on_hand')->groupBy('product_id')
            : DB::table('product_stocks')->where('company_id', $company->id)
                ->whereIn('branch_id', $branchIds)
                ->when($sourceType === 'branch' && Schema::hasColumn('product_stocks', 'stock_area'), fn ($query) => $query->where('stock_area', 'front'))
                ->selectRaw('product_id, SUM(quantity_on_hand) as quantity_on_hand')->groupBy('product_id');

        $rows = DB::table('products')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->leftJoinSub($stockSource, 'ps', fn ($join) => $join->on('ps.product_id', '=', 'products.id'))
            ->where('products.company_id', $company->id)
            ->when(Schema::hasColumn('products', 'branch_id'), fn ($query) => $query->where(function ($scope) use ($branchIds) {
                $scope->whereNull('products.branch_id')->orWhereIn('products.branch_id', $branchIds);
            }))
            ->where('products.stock_tracking_enabled', 1)
            ->where('products.is_active', 1)
            ->when($request->input('q'), function ($q) use ($request) {
                $term = trim((string) $request->input('q'));

                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', "%{$term}%")
                        ->orWhere('products.code', 'like', "%{$term}%")
                        ->orWhere('products.sku', 'like', "%{$term}%")
                        ->orWhere('products.barcode', 'like', "%{$term}%");
                });
            })
            ->select([
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                DB::raw('COALESCE(pc.name, "Uncategorised") as category_name'),
                DB::raw('COALESCE(sup.name, "No Supplier") as supplier_name'),
                DB::raw('COALESCE(MAX(ps.quantity_on_hand), 0) as quantity_on_hand'),
            ])
            ->groupBy('products.id', 'products.name', 'products.code', 'products.sku', 'products.barcode', 'pc.name', 'sup.name')
            ->orderBy('products.name')
            ->limit((int) $request->input('limit', 50))
            ->get();

        return response()->json([
            'message' => 'Products fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function dashboard(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $base = DB::table('stock_transfer_headers')
            ->where('company_id', $company->id)
            ->whereIn('from_branch_id', $branchIds)
            ->whereIn('to_branch_id', $branchIds)
            ->when($request->input('from_branch_id'), fn ($q) => $q->where('from_branch_id', $request->input('from_branch_id')))
            ->when($request->input('to_branch_id'), fn ($q) => $q->where('to_branch_id', $request->input('to_branch_id')))
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('transfer_date', '<=', $request->input('date_to')));

        $summary = (clone $base)
            ->selectRaw('
                COUNT(*) as transfer_count,
                SUM(total_quantity_requested) as requested_quantity,
                SUM(total_quantity_approved) as approved_quantity,
                SUM(total_quantity_dispatched) as dispatched_quantity,
                SUM(total_quantity_received) as received_quantity,
                SUM(total_quantity_variance) as variance_quantity,
                SUM(total_cost_value) as cost_value,
                SUM(total_selling_value) as selling_value,
                SUM(total_variance_cost_value) as variance_cost_value,
                SUM(total_variance_selling_value) as variance_selling_value,
                MAX(cost_currency) as cost_currency,
                MAX(selling_currency) as selling_currency
            ')
            ->first();

        $statusSummary = (clone $base)
            ->selectRaw('status, COUNT(*) as count, SUM(total_cost_value) as cost_value')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $branchFlow = DB::table('stock_transfer_headers as sth')
            ->leftJoin('company_branches as from_br', 'from_br.id', '=', 'sth.from_branch_id')
            ->leftJoin('company_branches as to_br', 'to_br.id', '=', 'sth.to_branch_id')
            ->where('sth.company_id', $company->id)
            ->whereIn('sth.from_branch_id', $branchIds)
            ->whereIn('sth.to_branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->selectRaw('
                sth.from_branch_id,
                COALESCE(from_br.branch_name, from_br.branch_code, "Unknown") as from_branch_name,
                sth.to_branch_id,
                COALESCE(to_br.branch_name, to_br.branch_code, "Unknown") as to_branch_name,
                COUNT(*) as transfer_count,
                SUM(sth.total_quantity_dispatched) as dispatched_quantity,
                SUM(sth.total_quantity_received) as received_quantity,
                SUM(sth.total_cost_value) as cost_value,
                SUM(sth.total_selling_value) as selling_value
            ')
            ->groupBy('sth.from_branch_id', DB::raw('COALESCE(from_br.branch_name, from_br.branch_code, "Unknown")'), 'sth.to_branch_id', DB::raw('COALESCE(to_br.branch_name, to_br.branch_code, "Unknown")'))
            ->orderByDesc('cost_value')
            ->limit(20)
            ->get();

        $topProducts = DB::table('stock_transfer_lines as stl')
            ->join('stock_transfer_headers as sth', 'sth.id', '=', 'stl.stock_transfer_id')
            ->where('stl.company_id', $company->id)
            ->whereIn('sth.from_branch_id', $branchIds)
            ->whereIn('sth.to_branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->selectRaw('
                stl.product_id,
                MAX(stl.product_name) as product_name,
                MAX(stl.code) as code,
                MAX(stl.category_name) as category_name,
                SUM(stl.quantity_dispatched) as dispatched_quantity,
                SUM(stl.quantity_received) as received_quantity,
                SUM(stl.cost_value) as cost_value,
                SUM(stl.selling_value) as selling_value,
                MAX(stl.cost_currency) as cost_currency,
                MAX(stl.selling_currency) as selling_currency
            ')
            ->groupBy('stl.product_id')
            ->orderByDesc('cost_value')
            ->limit(15)
            ->get();

        return response()->json([
            'message' => 'Stock transfer dashboard fetched successfully.',
            'data' => [
                'summary' => $summary,
                'status_summary' => $statusSummary,
                'branch_flow' => $branchFlow,
                'top_products' => $topProducts,
            ],
        ]);
    }

    public function report(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $rows = $this->transferBaseQuery($company->id, $branchIds)
            ->when($request->input('status'), fn ($q) => $q->where('sth.status', $request->input('status')))
            ->when($request->input('from_branch_id'), fn ($q) => $q->where('sth.from_branch_id', $request->input('from_branch_id')))
            ->when($request->input('to_branch_id'), fn ($q) => $q->where('sth.to_branch_id', $request->input('to_branch_id')))
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->orderByDesc('sth.transfer_date')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'message' => 'Stock transfer report fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function lineReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $rows = DB::table('stock_transfer_lines as stl')
            ->join('stock_transfer_headers as sth', 'sth.id', '=', 'stl.stock_transfer_id')
            ->leftJoin('company_branches as from_br', 'from_br.id', '=', 'sth.from_branch_id')
            ->leftJoin('company_branches as to_br', 'to_br.id', '=', 'sth.to_branch_id')
            ->where('stl.company_id', $company->id)
            ->whereIn('sth.from_branch_id', $branchIds)
            ->whereIn('sth.to_branch_id', $branchIds)
            ->when($request->input('status'), fn ($q) => $q->where('sth.status', $request->input('status')))
            ->when($request->input('from_branch_id'), fn ($q) => $q->where('sth.from_branch_id', $request->input('from_branch_id')))
            ->when($request->input('to_branch_id'), fn ($q) => $q->where('sth.to_branch_id', $request->input('to_branch_id')))
            ->when($request->input('product_id'), fn ($q) => $q->where('stl.product_id', $request->input('product_id')))
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->when($request->input('q'), function ($q) use ($request) {
                $term = trim((string) $request->input('q'));

                $q->where(function ($sub) use ($term) {
                    $sub->where('stl.product_name', 'like', "%{$term}%")
                        ->orWhere('stl.code', 'like', "%{$term}%")
                        ->orWhere('stl.sku', 'like', "%{$term}%")
                        ->orWhere('stl.barcode', 'like', "%{$term}%")
                        ->orWhere('sth.transfer_no', 'like', "%{$term}%");
                });
            })
            ->selectRaw('
                sth.transfer_no,
                sth.transfer_date,
                sth.status,
                COALESCE(from_br.branch_name, from_br.branch_code, "Unknown") as from_branch_name,
                COALESCE(to_br.branch_name, to_br.branch_code, "Unknown") as to_branch_name,
                stl.product_id,
                stl.product_name,
                stl.code,
                stl.sku,
                stl.barcode,
                stl.category_name,
                stl.supplier_name,
                stl.quantity_requested,
                stl.quantity_approved,
                stl.quantity_dispatched,
                stl.quantity_received,
                stl.quantity_variance,
                stl.unit_cost,
                stl.cost_value,
                stl.variance_cost_value,
                stl.selling_price,
                stl.selling_value,
                stl.variance_selling_value,
                stl.cost_currency,
                stl.selling_currency
            ')
            ->orderByDesc('sth.transfer_date')
            ->orderBy('stl.product_name')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'message' => 'Stock transfer line report fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function branchReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $rows = DB::table('stock_transfer_headers as sth')
            ->leftJoin('company_branches as from_br', 'from_br.id', '=', 'sth.from_branch_id')
            ->leftJoin('company_branches as to_br', 'to_br.id', '=', 'sth.to_branch_id')
            ->where('sth.company_id', $company->id)
            ->whereIn('sth.from_branch_id', $branchIds)
            ->whereIn('sth.to_branch_id', $branchIds)
            ->when($request->input('date_from'), fn ($q) => $q->whereDate('sth.transfer_date', '>=', $request->input('date_from')))
            ->when($request->input('date_to'), fn ($q) => $q->whereDate('sth.transfer_date', '<=', $request->input('date_to')))
            ->selectRaw('
                sth.from_branch_id,
                COALESCE(from_br.branch_name, from_br.branch_code, "Unknown") as from_branch_name,
                sth.to_branch_id,
                COALESCE(to_br.branch_name, to_br.branch_code, "Unknown") as to_branch_name,
                COUNT(*) as transfer_count,
                SUM(sth.total_quantity_requested) as requested_quantity,
                SUM(sth.total_quantity_dispatched) as dispatched_quantity,
                SUM(sth.total_quantity_received) as received_quantity,
                SUM(sth.total_quantity_variance) as variance_quantity,
                SUM(sth.total_cost_value) as cost_value,
                SUM(sth.total_selling_value) as selling_value,
                SUM(sth.total_variance_cost_value) as variance_cost_value,
                SUM(sth.total_variance_selling_value) as variance_selling_value,
                MAX(sth.cost_currency) as cost_currency,
                MAX(sth.selling_currency) as selling_currency
            ')
            ->groupBy('sth.from_branch_id', DB::raw('COALESCE(from_br.branch_name, from_br.branch_code, "Unknown")'), 'sth.to_branch_id', DB::raw('COALESCE(to_br.branch_name, to_br.branch_code, "Unknown")'))
            ->orderByDesc('cost_value')
            ->get();

        return response()->json([
            'message' => 'Stock transfer branch report fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function agingReport(Request $request)
    {
        $company = app('company');
        $branchIds = $this->reportBranchIds($request, (string) $company->id);

        $rows = $this->transferBaseQuery($company->id, $branchIds)
            ->whereIn('sth.status', ['requested', 'approved', 'dispatched', 'partially_received'])
            ->selectRaw('DATEDIFF(CURRENT_DATE, sth.transfer_date) as age_days')
            ->when($request->input('from_branch_id'), fn ($q) => $q->where('sth.from_branch_id', $request->input('from_branch_id')))
            ->when($request->input('to_branch_id'), fn ($q) => $q->where('sth.to_branch_id', $request->input('to_branch_id')))
            ->orderByDesc('age_days')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'message' => 'Stock transfer aging report fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function exportReport(Request $request)
    {
        $type = $request->input('type') ?: 'transfers';
        $format = $request->input('format') ?: 'xlsx';

        $response = match ($type) {
            'lines' => $this->lineReport($request)->getData(true)['data']['data'] ?? [],
            'branch' => $this->branchReport($request)->getData(true)['data'] ?? [],
            'aging' => $this->agingReport($request)->getData(true)['data']['data'] ?? [],
            default => $this->report($request)->getData(true)['data']['data'] ?? [],
        };

        $spreadsheet = $this->buildReportWorkbook(
            title: 'Stock Transfer ' . ucwords(str_replace('_', ' ', $type)) . ' Report',
            rows: $response
        );

        return $this->streamSpreadsheet($spreadsheet, "stock_transfer_{$type}_report.{$format}", $format);
    }

    /** @param array<int, string> $branchIds */
    private function transferBaseQuery(string $companyId, array $branchIds)
    {
        return DB::table('stock_transfer_headers as sth')
            ->leftJoin('company_branches as from_br', 'from_br.id', '=', 'sth.from_branch_id')
            ->leftJoin('company_branches as to_br', 'to_br.id', '=', 'sth.to_branch_id')
            ->leftJoin('inventory_warehouses as from_wh', 'from_wh.id', '=', 'sth.from_warehouse_id')
            ->leftJoin('inventory_warehouses as to_wh', 'to_wh.id', '=', 'sth.to_warehouse_id')
            ->where('sth.company_id', $companyId)
            ->whereIn('sth.from_branch_id', $branchIds)
            ->whereIn('sth.to_branch_id', $branchIds)
            ->selectRaw('
                sth.*,
                COALESCE(from_br.branch_name, from_br.branch_code, "Unknown") as from_branch_name,
                COALESCE(to_br.branch_name, to_br.branch_code, "Unknown") as to_branch_name,
                from_wh.name as from_warehouse_name,
                to_wh.name as to_warehouse_name
            ');
    }

    private function reportBranchIds(Request $request, string $companyId): array
    {
        $branchScope = app(BusinessModeBranchScopeService::class);
        $scope = $branchScope->resolve($request, $companyId);
        $requestedBranchIds = collect([
            $request->input('from_branch_id'),
            $request->input('to_branch_id'),
        ])->filter(fn ($branchId) => is_scalar($branchId) && trim((string) $branchId) !== '')
            ->map(fn ($branchId) => trim((string) $branchId))
            ->unique()
            ->values()
            ->all();

        if ($requestedBranchIds !== []) {
            $branchScope->resolve($request, $companyId, $requestedBranchIds);
        }

        return $scope['branch_ids'];
    }

    /** @param array<int, string> $branchIds */
    private function assertTransferEndpoints(string $companyId, array $branchIds, string $fromType, string $fromBranchId, ?string $fromWarehouseId, string $toType, string $toBranchId, ?string $toWarehouseId): void
    {
        $warehouseQuery = fn () => DB::table('inventory_warehouses')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
            });
        $from = $fromWarehouseId ? $warehouseQuery()->where('id', $fromWarehouseId)->first() : null;
        $to = $toWarehouseId ? $warehouseQuery()->where('id', $toWarehouseId)->first() : null;
        if (($fromType === 'warehouse' && !$from) || ($toType === 'warehouse' && !$to)) throw new \RuntimeException('The selected warehouse must be active.');
        if (($from && $from->branch_id && (string) $from->branch_id !== (string) $fromBranchId) || ($to && $to->branch_id && (string) $to->branch_id !== (string) $toBranchId)) {
            throw new \RuntimeException('The selected warehouse does not belong to its selected branch.');
        }
        if ($fromType === $toType && (string) $fromBranchId === (string) $toBranchId && ($fromType === 'branch' || (string) $fromWarehouseId === (string) $toWarehouseId)) {
            throw new \RuntimeException('Source and destination cannot be the same stock location.');
        }
    }

    private function buildProductLine(string $companyId, string $branchId, string $productId, float $qty): array
    {
        $product = DB::table('products')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->where('products.company_id', $companyId)
            ->when(Schema::hasColumn('products', 'branch_id'), fn ($query) => $query->where(function ($scope) use ($branchId) {
                $scope->whereNull('products.branch_id')->orWhere('products.branch_id', $branchId);
            }))
            ->where('products.id', $productId)
            ->selectRaw('
                products.id,
                products.name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name
            ')
            ->first();

        if (!$product) {
            throw new \Exception('Product not found.');
        }

        $cost = DB::table('product_costs')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        $selling = $this->standardSellingPrice($companyId, $branchId, $productId);

        $unitCost = (float) ($cost->average_cost ?? $cost->last_cost ?? 0);
        $sellingPrice = (float) ($selling['price'] ?? 0);

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'code' => $product->code,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category_name' => $product->category_name,
            'supplier_name' => $product->supplier_name,
            'uom_name' => '',
            'unit_cost' => $unitCost,
            'cost_value' => $qty * $unitCost,
            'selling_price' => $sellingPrice,
            'selling_value' => $qty * $sellingPrice,
            'cost_currency' => $cost->currency ?? 'USD',
            'selling_currency' => $selling['currency'] ?? ($cost->currency ?? 'USD'),
            'metadata' => json_encode([]),
        ];
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

    private function decreaseBranchStock(
        string $companyId,
        string $branchId,
        string $productId,
        float $quantity,
        string $transferId,
        string $transferNo,
        float $unitCost,
        ?string $currency,
        ?string $userId,
        string $stockArea = 'back',
        bool $writeMovement = true
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $stock = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when(Schema::hasColumn('product_stocks', 'stock_area'), fn ($query) => $query->where('stock_area', $stockArea))
            ->lockForUpdate()
            ->first();

        $currentQty = (float) ($stock->quantity_on_hand ?? 0);
        $newQty = $currentQty - $quantity;

        if (!$stock) {
            DB::table('product_stocks')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'stock_area' => $stockArea,
                'quantity_on_hand' => $newQty,
                'reserved_quantity' => 0,
                'available_quantity' => $newQty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('product_stocks')
                ->where('id', $stock->id)
                ->update([
                    'quantity_on_hand' => $newQty,
                    'available_quantity' => $newQty - (float) ($stock->reserved_quantity ?? 0),
                    'updated_at' => now(),
                ]);
        }

        if ($writeMovement) DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => null,
            'shift_session_id' => null,
            'product_id' => $productId,
            'stock_area' => $stockArea,
            'movement_type' => 'transfer_out',
            'quantity_in' => 0,
            'quantity_out' => $quantity,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'currency' => $currency,
            'currency_code' => $currency,
            'reference_type' => 'stock_transfer',
            'reference_id' => $transferId,
            'notes' => 'Interbranch transfer out ' . $transferNo,
            'metadata' => json_encode([
                'transfer_no' => $transferNo,
                'action' => 'dispatch',
            ]),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function increaseBranchStock(
        string $companyId,
        string $branchId,
        string $productId,
        float $quantity,
        string $transferId,
        string $transferNo,
        float $unitCost,
        ?string $currency,
        ?string $userId,
        string $stockArea = 'back',
        bool $writeMovement = true
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $stock = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when(Schema::hasColumn('product_stocks', 'stock_area'), fn ($query) => $query->where('stock_area', $stockArea))
            ->lockForUpdate()
            ->first();

        $currentQty = (float) ($stock->quantity_on_hand ?? 0);
        $newQty = $currentQty + $quantity;

        if (!$stock) {
            DB::table('product_stocks')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'stock_area' => $stockArea,
                'quantity_on_hand' => $newQty,
                'reserved_quantity' => 0,
                'available_quantity' => $newQty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('product_stocks')
                ->where('id', $stock->id)
                ->update([
                    'quantity_on_hand' => $newQty,
                    'available_quantity' => $newQty - (float) ($stock->reserved_quantity ?? 0),
                    'updated_at' => now(),
                ]);
        }

        if ($writeMovement) DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => null,
            'shift_session_id' => null,
            'product_id' => $productId,
            'stock_area' => $stockArea,
            'movement_type' => 'transfer_in',
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'currency' => $currency,
            'currency_code' => $currency,
            'reference_type' => 'stock_transfer',
            'reference_id' => $transferId,
            'notes' => 'Interbranch transfer in ' . $transferNo,
            'metadata' => json_encode([
                'transfer_no' => $transferNo,
                'action' => 'receive',
            ]),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveTransferDestination(string $companyId, object $transfer): ?object
    {
        $type = $transfer->to_location_type ?? 'warehouse';
        return DB::table('inventory_locations as l')->join('inventory_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', $companyId)
            ->where('l.is_active', true)->whereNull('l.deleted_at')->where('w.is_active', true)->whereNull('w.deleted_at')
            ->when($type === 'warehouse', fn ($query) => $query->where('w.id', $transfer->to_warehouse_id)->where(fn ($sub) => $sub->where('l.branch_id', $transfer->to_branch_id)->orWhereNull('l.branch_id')))
            ->when($type === 'branch', fn ($query) => $query->where('l.branch_id', $transfer->to_branch_id)->where('l.location_type', 'picking'))
            ->select('w.id as warehouse_id', 'l.id as location_id')
            ->orderByRaw($type === 'warehouse' ? "CASE WHEN l.location_type = 'storage' THEN 0 WHEN l.location_type = 'receiving' THEN 1 ELSE 2 END" : 'l.pick_sequence')
            ->orderBy('l.pick_sequence')->first();
    }

    private function allocateTransferBatches(string $companyId, object $transfer, object $line, float $quantity, ?string $userId): bool
    {
        if ($quantity <= 0) return false;
        $tracked = (bool) DB::table('products')->where('company_id', $companyId)->where('id', $line->product_id)->value('batch_tracking_enabled');
        if (!$tracked) return $this->allocateUnbatchedTransfer($companyId, $transfer, $line, $quantity, $userId);

        $batches = DB::table('inventory_batches')->where('company_id', $companyId)
            ->where('branch_id', $transfer->from_branch_id)->where('product_id', $line->product_id)
            ->when(($transfer->from_location_type ?? 'warehouse') === 'warehouse', fn ($query) => $query->where('warehouse_id', $transfer->from_warehouse_id))
            ->when(($transfer->from_location_type ?? 'warehouse') === 'branch', fn ($query) => $query->whereIn('location_id', DB::table('inventory_locations')->where('company_id', $companyId)->where('branch_id', $transfer->from_branch_id)->where('location_type', 'picking')->where('is_active', true)->whereNull('deleted_at')->select('id')))
            ->where('status', 'available')->where('quality_status', 'released')->where('quantity_on_hand', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
            ->orderByRaw('expires_at IS NULL, expires_at ASC, created_at ASC')->lockForUpdate()->get();
        $available = $batches->sum(fn ($b) => max(0, (float) $b->quantity_on_hand - (float) $b->reserved_quantity - (float) $b->quarantined_quantity));
        if ($available + 0.00001 < $quantity) throw new \RuntimeException("Insufficient released FEFO batch stock for {$line->product_name}.");

        $destination = $this->resolveTransferDestination($companyId, $transfer);
        if (!$destination) throw new \RuntimeException('Destination branch has no active warehouse location.');

        $remaining = $quantity;
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $availableBatch = max(0, (float) $batch->quantity_on_hand - (float) $batch->reserved_quantity - (float) $batch->quarantined_quantity);
            $allocated = min($remaining, $availableBatch);
            if ($allocated <= 0) continue;
            DB::table('inventory_batches')->where('id', $batch->id)->update(['quantity_on_hand' => (float) $batch->quantity_on_hand - $allocated, 'status' => ((float) $batch->quantity_on_hand - $allocated) <= 0 ? 'depleted' : 'available', 'updated_at' => now()]);
            $this->changeTransferLocationBalance($batch->company_id, $batch->branch_id, $batch->warehouse_id, $batch->location_id, $batch->product_id, $batch->id, -$allocated);
            DB::table('inventory_transfer_allocations')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'stock_transfer_id' => $transfer->id, 'stock_transfer_line_id' => $line->id,
                'product_id' => $line->product_id, 'batch_id' => $batch->id, 'source_warehouse_id' => $batch->warehouse_id, 'source_location_id' => $batch->location_id,
                'destination_warehouse_id' => $destination->warehouse_id, 'destination_location_id' => $destination->location_id,
                'quantity_dispatched' => $allocated, 'quantity_received' => 0, 'unit_cost' => $batch->base_unit_cost ?: $batch->unit_cost,
                'status' => 'in_transit', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertTransferBatchMovement($companyId, $transfer->from_branch_id, $batch, $line->product_id, $allocated, false, $transfer, $userId);
            $remaining = round($remaining - $allocated, 4);
        }
        return true;
    }

    private function allocateUnbatchedTransfer(string $companyId, object $transfer, object $line, float $quantity, ?string $userId): bool
    {
        $destination = $this->resolveTransferDestination($companyId, $transfer);
        if (!$destination) throw new \RuntimeException('Destination warehouse has no active receiving or storage location.');

        $balances = DB::table('inventory_location_balances')
            ->where('company_id', $companyId)
            ->where('branch_id', $transfer->from_branch_id)
            ->when(($transfer->from_location_type ?? 'warehouse') === 'warehouse', fn ($query) => $query->where('warehouse_id', $transfer->from_warehouse_id))
            ->when(($transfer->from_location_type ?? 'warehouse') === 'branch', fn ($query) => $query->whereIn('location_id', DB::table('inventory_locations')->where('company_id', $companyId)->where('branch_id', $transfer->from_branch_id)->where('location_type', 'picking')->where('is_active', true)->whereNull('deleted_at')->select('id')))
            ->where('product_id', $line->product_id)->whereNull('batch_id')->where('available_quantity', '>', 0)
            ->orderBy('created_at')->lockForUpdate()->get();
        $available = (float) $balances->sum('available_quantity');
        if ($available + 0.00001 < $quantity) {
            throw new \RuntimeException("Insufficient warehouse stock for {$line->product_name}. Required: {$quantity}, available: {$available}.");
        }

        $remaining = $quantity;
        foreach ($balances as $balance) {
            if ($remaining <= 0) break;
            $allocated = min($remaining, (float) $balance->available_quantity);
            DB::table('inventory_location_balances')->where('id', $balance->id)->update([
                'quantity_on_hand' => (float) $balance->quantity_on_hand - $allocated,
                'available_quantity' => (float) $balance->available_quantity - $allocated,
                'updated_at' => now(),
            ]);
            DB::table('inventory_transfer_allocations')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'stock_transfer_id' => $transfer->id,
                'stock_transfer_line_id' => $line->id, 'product_id' => $line->product_id, 'batch_id' => null,
                'source_warehouse_id' => $balance->warehouse_id, 'source_location_id' => $balance->location_id,
                'destination_warehouse_id' => $destination->warehouse_id, 'destination_location_id' => $destination->location_id,
                'quantity_dispatched' => $allocated, 'quantity_received' => 0, 'unit_cost' => $line->unit_cost,
                'status' => 'in_transit', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertUnbatchedTransferMovement($companyId, $transfer->from_branch_id, $balance->warehouse_id, $balance->location_id, $line, $allocated, false, $transfer, $userId);
            $remaining = round($remaining - $allocated, 4);
        }
        return true;
    }

    private function receiveTransferBatches(string $companyId, object $transfer, object $line, float $quantity, ?string $userId): bool
    {
        $allocations = DB::table('inventory_transfer_allocations')->where('company_id', $companyId)
            ->where('stock_transfer_id', $transfer->id)->where('stock_transfer_line_id', $line->id)->whereIn('status', ['in_transit', 'partially_received'])
            ->orderBy('created_at')->lockForUpdate()->get();
        if ($allocations->isEmpty()) return false;
        if ($allocations->sum('quantity_dispatched') + 0.00001 < $quantity) throw new \RuntimeException('Received batch quantity exceeds the traceable dispatched quantity.');

        $remaining = $quantity;
        foreach ($allocations as $allocation) {
            if ($remaining <= 0) break;
            if (!$allocation->batch_id) {
                $received = min($remaining, (float) $allocation->quantity_dispatched - (float) $allocation->quantity_received);
                if ($received <= 0) continue;
                $balanceQuery = DB::table('inventory_location_balances')->where('company_id', $companyId)
                    ->where('location_id', $allocation->destination_location_id)->where('product_id', $line->product_id)->whereNull('batch_id');
                $destinationBalance = $balanceQuery->lockForUpdate()->first();
                if ($destinationBalance) {
                    $balanceQuery->update([
                        'quantity_on_hand' => (float) $destinationBalance->quantity_on_hand + $received,
                        'available_quantity' => (float) $destinationBalance->available_quantity + $received,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('inventory_location_balances')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $transfer->to_branch_id,
                        'warehouse_id' => $allocation->destination_warehouse_id, 'location_id' => $allocation->destination_location_id,
                        'product_id' => $line->product_id, 'batch_id' => null, 'quantity_on_hand' => $received,
                        'reserved_quantity' => 0, 'available_quantity' => $received, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('inventory_transfer_allocations')->where('id', $allocation->id)->update([
                    'quantity_received' => (float) $allocation->quantity_received + $received,
                    'status' => ((float) $allocation->quantity_received + $received) >= (float) $allocation->quantity_dispatched ? 'received' : 'partially_received',
                    'updated_at' => now(),
                ]);
                $this->insertUnbatchedTransferMovement($companyId, $transfer->to_branch_id, $allocation->destination_warehouse_id, $allocation->destination_location_id, $line, $received, true, $transfer, $userId);
                $remaining = round($remaining - $received, 4);
                continue;
            }
            $source = DB::table('inventory_batches')->where('company_id', $companyId)->where('id', $allocation->batch_id)->first();
            if (!$source) throw new \RuntimeException('Source batch trace was lost during transfer.');
            $received = min($remaining, (float) $allocation->quantity_dispatched - (float) $allocation->quantity_received);
            if ($received <= 0) continue;
            $destinationBatch = DB::table('inventory_batches')->where('company_id', $companyId)->where('branch_id', $transfer->to_branch_id)
                ->where('warehouse_id', $allocation->destination_warehouse_id)
                ->where('product_id', $line->product_id)->where('lot_number', $source->lot_number)->lockForUpdate()->first();
            if ($destinationBatch) {
                DB::table('inventory_batches')->where('id', $destinationBatch->id)->update(['quantity_received' => (float) $destinationBatch->quantity_received + $received, 'quantity_on_hand' => (float) $destinationBatch->quantity_on_hand + $received, 'status' => 'available', 'updated_at' => now()]);
            } else {
                $destinationBatch = (object) array_merge((array) $source, ['id' => (string) Str::uuid(), 'branch_id' => $transfer->to_branch_id, 'warehouse_id' => $allocation->destination_warehouse_id, 'location_id' => $allocation->destination_location_id, 'quantity_received' => $received, 'quantity_on_hand' => $received, 'reserved_quantity' => 0, 'quarantined_quantity' => 0, 'status' => 'available']);
                DB::table('inventory_batches')->insert([
                    'id' => $destinationBatch->id, 'company_id' => $companyId, 'branch_id' => $destinationBatch->branch_id, 'warehouse_id' => $destinationBatch->warehouse_id,
                    'location_id' => $destinationBatch->location_id, 'product_id' => $line->product_id, 'purchase_receipt_line_id' => $source->purchase_receipt_line_id,
                    'lot_number' => $source->lot_number, 'manufactured_at' => $source->manufactured_at, 'expires_at' => $source->expires_at,
                    'quantity_received' => $received, 'quantity_on_hand' => $received, 'reserved_quantity' => 0, 'quarantined_quantity' => 0,
                    'unit_cost' => $source->unit_cost, 'base_unit_cost' => $source->base_unit_cost, 'currency_code' => $source->currency_code,
                    'exchange_rate' => $source->exchange_rate, 'status' => 'available', 'quality_status' => $source->quality_status, 'notes' => 'Received via ' . $transfer->transfer_no,
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->changeTransferLocationBalance($companyId, $transfer->to_branch_id, $destinationBatch->warehouse_id, $destinationBatch->location_id, $line->product_id, $destinationBatch->id, $received);
            DB::table('inventory_transfer_allocations')->where('id', $allocation->id)->update(['quantity_received' => (float) $allocation->quantity_received + $received, 'status' => ((float) $allocation->quantity_received + $received) >= (float) $allocation->quantity_dispatched ? 'received' : 'partially_received', 'updated_at' => now()]);
            $this->insertTransferBatchMovement($companyId, $transfer->to_branch_id, $destinationBatch, $line->product_id, $received, true, $transfer, $userId);
            $remaining = round($remaining - $received, 4);
        }
        return true;
    }

    private function insertUnbatchedTransferMovement(string $companyId, string $branchId, ?string $warehouseId, ?string $locationId, object $line, float $quantity, bool $incoming, object $transfer, ?string $userId): void
    {
        $stockArea = ($incoming ? ($transfer->to_location_type ?? 'warehouse') : ($transfer->from_location_type ?? 'warehouse')) === 'branch'
            ? 'front' : 'back';
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
            'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'batch_id' => null,
            'till_id' => null, 'shift_session_id' => null, 'product_id' => $line->product_id, 'stock_area' => $stockArea,
            'movement_type' => $incoming ? 'transfer_in' : 'transfer_out',
            'quantity_in' => $incoming ? $quantity : 0, 'quantity_out' => $incoming ? 0 : $quantity,
            'unit_cost' => $line->unit_cost, 'total_cost' => $quantity * (float) $line->unit_cost,
            'currency' => $line->cost_currency, 'currency_code' => $line->cost_currency,
            'reference_type' => 'stock_transfer', 'reference_id' => $transfer->id,
            'notes' => ($incoming ? 'Warehouse transfer receipt ' : 'Warehouse transfer dispatch ') . $transfer->transfer_no,
            'metadata' => json_encode(['transfer_no' => $transfer->transfer_no, 'warehouse_transfer' => true]),
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function changeTransferLocationBalance(string $companyId, string $branchId, ?string $warehouseId, ?string $locationId, string $productId, string $batchId, float $delta): void
    {
        if (!$locationId) throw new \RuntimeException('A batch transfer requires a warehouse location.');
        $query = DB::table('inventory_location_balances')->where('company_id', $companyId)->where('location_id', $locationId)->where('product_id', $productId)->where('batch_id', $batchId);
        $row = $query->lockForUpdate()->first();
        if (!$row) {
            if ($delta < 0) throw new \RuntimeException('Source batch location balance is missing.');
            DB::table('inventory_location_balances')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'product_id' => $productId, 'batch_id' => $batchId, 'quantity_on_hand' => $delta, 'reserved_quantity' => 0, 'available_quantity' => $delta, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            if ($delta < 0 && (float) $row->available_quantity + 0.00001 < abs($delta)) throw new \RuntimeException('Insufficient source batch location balance.');
            $query->update(['quantity_on_hand' => (float) $row->quantity_on_hand + $delta, 'available_quantity' => (float) $row->available_quantity + $delta, 'updated_at' => now()]);
        }
    }

    private function insertTransferBatchMovement(string $companyId, string $branchId, object $batch, string $productId, float $quantity, bool $incoming, object $transfer, ?string $userId): void
    {
        $stockArea = ($incoming ? ($transfer->to_location_type ?? 'warehouse') : ($transfer->from_location_type ?? 'warehouse')) === 'branch'
            ? 'front' : 'back';
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'warehouse_id' => $batch->warehouse_id, 'location_id' => $batch->location_id, 'batch_id' => $batch->id,
            'till_id' => null, 'shift_session_id' => null, 'product_id' => $productId, 'stock_area' => $stockArea,
            'movement_type' => $incoming ? 'transfer_in' : 'transfer_out',
            'quantity_in' => $incoming ? $quantity : 0, 'quantity_out' => $incoming ? 0 : $quantity, 'unit_cost' => $batch->base_unit_cost ?: $batch->unit_cost,
            'total_cost' => $quantity * (float) ($batch->base_unit_cost ?: $batch->unit_cost), 'currency' => $batch->currency_code, 'currency_code' => $batch->currency_code,
            'reference_type' => 'stock_transfer', 'reference_id' => $transfer->id, 'notes' => ($incoming ? 'Batch transfer receipt ' : 'FEFO batch transfer dispatch ') . $transfer->transfer_no,
            'metadata' => json_encode(['transfer_no' => $transfer->transfer_no, 'allocation_method' => 'FEFO', 'lot_number' => $batch->lot_number]), 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function refreshTransferTotals(string $id): void
    {
        $totals = DB::table('stock_transfer_lines')
            ->where('stock_transfer_id', $id)
            ->selectRaw('
                SUM(quantity_requested) as requested,
                SUM(quantity_approved) as approved,
                SUM(quantity_dispatched) as dispatched,
                SUM(quantity_received) as received,
                SUM(quantity_variance) as variance_qty,
                SUM(cost_value) as cost_value,
                SUM(selling_value) as selling_value,
                SUM(variance_cost_value) as variance_cost_value,
                SUM(variance_selling_value) as variance_selling_value,
                MAX(cost_currency) as cost_currency,
                MAX(selling_currency) as selling_currency
            ')
            ->first();

        DB::table('stock_transfer_headers')
            ->where('id', $id)
            ->update([
                'total_quantity_requested' => (float) ($totals->requested ?? 0),
                'total_quantity_approved' => (float) ($totals->approved ?? 0),
                'total_quantity_dispatched' => (float) ($totals->dispatched ?? 0),
                'total_quantity_received' => (float) ($totals->received ?? 0),
                'total_quantity_variance' => (float) ($totals->variance_qty ?? 0),
                'total_cost_value' => (float) ($totals->cost_value ?? 0),
                'total_selling_value' => (float) ($totals->selling_value ?? 0),
                'total_variance_cost_value' => (float) ($totals->variance_cost_value ?? 0),
                'total_variance_selling_value' => (float) ($totals->variance_selling_value ?? 0),
                'cost_currency' => $totals->cost_currency ?? null,
                'selling_currency' => $totals->selling_currency ?? null,
                'updated_at' => now(),
            ]);
    }

    private function generateTransferNo(string $companyId, string $date): string
    {
        $prefix = 'IBT-' . Carbon::parse($date)->format('ymd');

        $count = DB::table('stock_transfer_headers')
                ->where('company_id', $companyId)
                ->whereDate('transfer_date', $date)
                ->count() + 1;

        return $prefix . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function buildReportWorkbook(string $title, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 30));

        $sheet->fromArray([[$title], ['Generated', now()->toDateTimeString()], []], null, 'A1');

        if (empty($rows)) {
            $sheet->fromArray([['No Data']], null, 'A4');
            return $spreadsheet;
        }

        $normalRows = array_map(fn ($row) => (array) $row, $rows);
        $headers = array_keys($normalRows[0]);

        $sheet->fromArray($headers, null, 'A4');
        $sheet->fromArray(array_map('array_values', $normalRows), null, 'A5');

        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();

        $sheet->freezePane('A5');
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A4:{$highestColumn}4")->getFont()->setBold(true);
        $sheet->getStyle("A4:{$highestColumn}4")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF002B49');
        $sheet->getStyle("A4:{$highestColumn}4")->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A4:{$highestColumn}{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');

        foreach (range('A', $highestColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
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
}
