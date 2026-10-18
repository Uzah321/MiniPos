<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\PurchaseOrder;
use Modules\Product\Models\PurchaseOrderLine;
use Modules\Product\Models\PurchaseReceipt;
use Modules\Product\Models\PurchaseReceiptLine;
use Modules\Product\Models\PurchaseReturn;
use Modules\Product\Models\PurchaseReturnLine;
use Modules\Product\Models\StockMovement;

use Modules\Product\Models\SupplierInvoice;
use Modules\Product\Models\SupplierInvoiceLine;
use function App\Helpers\hasPermission;

class PurchaseController extends Controller
{
    private EnterpriseReportDownloadService $reportDownloads;

    public function __construct(?EnterpriseReportDownloadService $reportDownloads = null)
    {
        $this->reportDownloads = $reportDownloads ?? new EnterpriseReportDownloadService();
    }

    public function overview(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $scope = fn ($query) => $query->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds);

        $orders = $scope(PurchaseOrder::query());
        $receipts = $scope(PurchaseReceipt::query());
        $returns = $scope(PurchaseReturn::query());
        $invoices = $scope(SupplierInvoice::query());

        return response()->json([
            'message' => 'Purchasing overview fetched successfully.',
            'data' => [
                'summary' => [
                    'open_orders' => (clone $orders)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received'])->count(),
                    'overdue_orders' => (clone $orders)->whereDate('expected_date', '<', today())->whereNotIn('status', ['received', 'closed', 'cancelled'])->count(),
                    'goods_received_today' => (clone $receipts)->whereDate('received_date', today())->where('status', 'posted')->count(),
                    'goods_received_value_today' => round((float) (clone $receipts)->whereDate('received_date', today())->where('status', 'posted')->sum('base_grand_total'), 4),
                    'open_supplier_invoices' => (clone $invoices)->whereIn('status', ['draft', 'posted', 'partially_paid'])->count(),
                    'supplier_balance_due' => round((float) (clone $invoices)->whereIn('status', ['posted', 'partially_paid'])->sum('balance_due'), 4),
                    'returns_this_month' => (clone $returns)->whereDate('return_date', '>=', now()->startOfMonth())->where('status', 'posted')->count(),
                ],
                'workspace' => [
                    'layout' => 'single_page_compact',
                    'sections' => [
                        ['key' => 'orders', 'label' => 'Purchase orders', 'route' => '/purchases/orders'],
                        ['key' => 'receive', 'label' => 'Receive goods', 'route' => '/purchases/receipts'],
                        ['key' => 'invoices', 'label' => 'Supplier invoices', 'route' => '/purchases/supplier-invoices'],
                        ['key' => 'returns', 'label' => 'Purchase returns', 'route' => '/purchases/returns'],
                        ['key' => 'payments', 'label' => 'Supplier payments', 'route' => '/purchases/supplier-payments'],
                    ],
                ],
                'recent_receipts' => (clone $receipts)->with(['supplier', 'branch'])
                    ->withCount(['lines' => fn ($query) => $query->whereIn('branch_id', $branchIds)])
                    ->latest('received_date')->latest()->limit(8)->get()
                    ->each(fn ($receipt) => $this->decorateReceipt($receipt)),
                'recent_orders' => (clone $orders)->withCount([
                    'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                    'receipts' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                ])->latest('order_date')->limit(8)->get(),
            ],
        ]);
    }

    public function orders(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];

        $query = PurchaseOrder::query()
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->withCount([
                'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'receipts' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'returns' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'invoices' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            ])
            ->with([
                'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'lines.product',
            ])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('order_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('order_date', '<=', $request->date_to))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->q;
                $q->where(function ($sub) use ($term) {
                    $sub->where('po_number', 'like', "%{$term}%")
                        ->orWhereHas('lines', fn ($l) => $l->where('product_name', 'like', "%{$term}%")->orWhere('product_code', 'like', "%{$term}%"));
                });
            })
            ->latest();

        return response()->json(['message' => 'Purchase orders fetched successfully.', 'data' => $query->paginate($request->per_page ?? 25)]);
    }

    public function storeOrder(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $permission = hasPermission($user->role_id, 'purchase_orders', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'currency_code' => 'required|string|max:10',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'receipt_group_key' => 'nullable|string|max:120',
            'source' => 'nullable|in:purchase_order,quick_receive,direct_receive,import',
            'status' => 'nullable|in:draft,submitted,approved',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.ordered_quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.currency_code' => 'nullable|string|max:10',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_amount' => 'nullable|numeric|min:0',
            'lines.*.warehouse_id' => 'nullable|exists:inventory_warehouses,id',
            'lines.*.location_id' => 'nullable|exists:inventory_locations,id',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.manufactured_at' => 'nullable|date',
            'lines.*.expires_at' => 'nullable|date|after_or_equal:lines.*.manufactured_at',
            'lines.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id, [(string) $request->branch_id])['branch_ids'];
        if (!$this->purchaseLineLocationsValid((string) $company->id, (string) $request->branch_id, $request->input('lines', []))) {
            return response()->json(['message' => 'One or more warehouse/location selections are not available for the selected branch.'], 422);
        }

        DB::beginTransaction();
        try {
            $totals = $this->calculateTotals($request->lines);
            $po = PurchaseOrder::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'supplier_id' => $request->supplier_id,
                'po_number' => $request->po_number ?: $this->nextNumber('PO'),
                'order_date' => $request->order_date,
                'expected_date' => $request->expected_date,
                'currency_code' => strtoupper($request->currency_code),
                'exchange_rate' => $request->exchange_rate ?: 1,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'shipping_total' => (float) ($request->shipping_total ?? 0),
                'grand_total' => $totals['grand_total'] + (float) ($request->shipping_total ?? 0),
                'base_grand_total' => ($totals['grand_total'] + (float) ($request->shipping_total ?? 0)) * (float) ($request->exchange_rate ?: 1),
                'status' => $request->status ?: 'draft',
                'notes' => $request->notes,
                'created_by' => $user?->id,
            ]);

            foreach ($request->lines as $line) {
                $product = $this->purchaseProduct((string) $company->id, (string) $line['product_id'], $branchIds, true);
                PurchaseOrderLine::create($this->linePayload($company->id, $request->branch_id, $po->id, $product, $line));
            }

            DB::commit();
            return response()->json(['message' => 'Purchase order saved successfully.', 'data' => $this->loadOrder($po->id)], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Purchase order failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateOrder(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();
        $permission = hasPermission($user->role_id, 'purchase_orders', $company->id);
        if ($permission !== true) return $permission;

        $branchIds = app(BusinessModeBranchScopeService::class)
            ->resolve($request, (string) $company->id)['branch_ids'];
        $po = PurchaseOrder::where('company_id', $company->id)->whereIn('branch_id', $branchIds)->where('id', $id)->first();
        if (!$po) return response()->json(['message' => 'Purchase order not found.'], 404);
        if (in_array($po->status, ['received', 'closed', 'cancelled'], true)) {
            return response()->json(['message' => 'This purchase order cannot be edited.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'currency_code' => 'required|string|max:10',
            'status' => 'nullable|in:draft,submitted,approved,cancelled',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.ordered_quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_amount' => 'nullable|numeric|min:0',
            'lines.*.warehouse_id' => ['required', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.location_id' => ['nullable', Rule::exists('inventory_locations', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        $targetBranchIds = $this->purchaseBranchScope($request, (string) $company->id, [(string) $request->branch_id])['branch_ids'];
        if (!$this->purchaseLineLocationsValid((string) $company->id, (string) $request->branch_id, $request->input('lines', []))) {
            return response()->json(['message' => 'One or more warehouse/location selections are not available for the selected branch.'], 422);
        }

        DB::beginTransaction();
        try {
            $totals = $this->calculateTotals($request->lines);
            $po->update([
                'branch_id' => $request->branch_id,
                'supplier_id' => $request->supplier_id,
                'order_date' => $request->order_date,
                'expected_date' => $request->expected_date,
                'currency_code' => strtoupper($request->currency_code),
                'exchange_rate' => $request->exchange_rate ?: $po->exchange_rate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'shipping_total' => (float) ($request->shipping_total ?? 0),
                'grand_total' => $totals['grand_total'] + (float) ($request->shipping_total ?? 0),
                'base_grand_total' => ($totals['grand_total'] + (float) ($request->shipping_total ?? 0)) * (float) ($request->exchange_rate ?: $po->exchange_rate),
                'status' => $request->status ?: $po->status,
                'notes' => $request->notes,
            ]);
            PurchaseOrderLine::where('purchase_order_id', $po->id)->delete();
            foreach ($request->lines as $line) {
                $product = $this->purchaseProduct((string) $company->id, (string) $line['product_id'], $targetBranchIds, true);
                PurchaseOrderLine::create($this->linePayload($company->id, $request->branch_id, $po->id, $product, $line));
            }
            DB::commit();
            return response()->json(['message' => 'Purchase order updated successfully.', 'data' => $this->loadOrder($po->id)]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Purchase order update failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function receiveOrder(Request $request, ?string $purchaseOrderId = null)
    {
        $company = app('company');
        $user = auth()->user();
        $permission = hasPermission($user->role_id, 'receive_stock', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'purchase_order_id' => ['nullable', Rule::exists('purchase_orders', 'id')->where('company_id', $company->id)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'received_date' => 'required|date',
            'currency_code' => 'required|string|max:10',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_line_id' => ['nullable', Rule::exists('purchase_order_lines', 'id')->where('company_id', $company->id)],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.tax_amount' => 'nullable|numeric|min:0',
            'lines.*.warehouse_id' => ['required', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.location_id' => ['nullable', Rule::exists('inventory_locations', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id, [(string) $request->branch_id])['branch_ids'];
        if (!$this->purchaseLineLocationsValid((string) $company->id, (string) $request->branch_id, $request->input('lines', []))) {
            return response()->json(['message' => 'One or more warehouse/location selections are not available for the selected branch.'], 422);
        }

        $poId = $purchaseOrderId ?: $request->purchase_order_id;
        $po = $poId ? PurchaseOrder::where('company_id', $company->id)->whereIn('branch_id', $branchIds)->where('id', $poId)->first() : null;
        if ($poId && (!$po || (string) $po->branch_id !== (string) $request->branch_id)) {
            return response()->json(['message' => 'The selected purchase order is not available for the receiving branch in this workspace.'], 422);
        }
        $requestedGroupKey = $request->filled('receipt_group_key') ? trim((string) $request->receipt_group_key) : null;
        if ($requestedGroupKey) {
            $groupReceipt = PurchaseReceipt::where('company_id', $company->id)
                ->where('receipt_group_key', $requestedGroupKey)
                ->first();
            if ($groupReceipt && (!in_array((string) $groupReceipt->branch_id, $branchIds, true)
                || (string) $groupReceipt->branch_id !== (string) $request->branch_id)) {
                return response()->json(['message' => 'The receipt group belongs to another branch or business-mode workspace.'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $totals = $this->calculateTotals($request->lines, 'quantity');
            $exchangeRate = (float) ($request->exchange_rate ?: $po?->exchange_rate ?: 1);
            $groupKey = $requestedGroupKey;
            $receipt = $groupKey ? PurchaseReceipt::where('company_id', $company->id)
                ->whereIn('branch_id', $branchIds)
                ->where('receipt_group_key', $groupKey)->lockForUpdate()->first() : null;
            if ($receipt && ($receipt->branch_id !== $request->branch_id || strtoupper($receipt->currency_code) !== strtoupper($request->currency_code))) {
                throw new \RuntimeException('Items grouped on one goods-received voucher must use the same branch and currency.');
            }
            $receipt = $receipt ?: PurchaseReceipt::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'purchase_order_id' => $po?->id,
                'supplier_id' => $request->supplier_id ?: $po?->supplier_id,
                'grn_number' => $request->grn_number ?: $this->nextNumber('GRN'),
                'source' => $request->input('source', $po ? 'purchase_order' : 'quick_receive'),
                'receipt_group_key' => $groupKey,
                'received_date' => $request->received_date,
                'currency_code' => strtoupper($request->currency_code),
                'exchange_rate' => $exchangeRate,
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'base_grand_total' => $totals['grand_total'] * $exchangeRate,
                'status' => 'posted',
                'notes' => $request->notes,
                'created_by' => $user?->id,
            ]);

            foreach ($request->lines as $line) {
                $product = $this->purchaseProduct((string) $company->id, (string) $line['product_id'], $branchIds, true);
                $poLine = !empty($line['purchase_order_line_id']) ? PurchaseOrderLine::where('company_id', $company->id)
                    ->whereIn('branch_id', $branchIds)
                    ->when($po, fn ($query) => $query->where('purchase_order_id', $po->id))
                    ->find($line['purchase_order_line_id']) : null;
                if (!empty($line['purchase_order_line_id']) && !$poLine) {
                    throw new \RuntimeException('A selected purchase-order line is not available in this workspace.');
                }
                $lineTotal = ((float) $line['quantity'] * (float) $line['unit_cost']) - (float) ($line['discount_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
                $warehouse = DB::table('inventory_warehouses')
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id)->orWhereNull('branch_id'))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->when(!empty($line['warehouse_id']), fn ($query) => $query->where('id', $line['warehouse_id']))
                    ->orderBy('created_at')
                    ->first();
                if (!$warehouse) throw new \RuntimeException('No active warehouse is configured for the receiving branch.');

                $location = DB::table('inventory_locations')
                    ->where('company_id', $company->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where(fn ($query) => $query->where('branch_id', $request->branch_id)->orWhereNull('branch_id'))
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->when(!empty($line['location_id']), fn ($query) => $query->where('id', $line['location_id']))
                    ->when(empty($line['location_id']), fn ($query) => $query->orderByRaw("CASE WHEN location_type = 'receiving' THEN 0 WHEN location_type = 'storage' THEN 1 ELSE 2 END")->orderBy('pick_sequence'))
                    ->first();
                if (!$location) throw new \RuntimeException('No receiving/storage location is configured for the selected warehouse.');

                if ($product->batch_tracking_enabled && empty($line['lot_number'])) {
                    throw new \RuntimeException("A lot number is required for batch-tracked product {$product->name}.");
                }
                if ($product->expiry_tracking_enabled && empty($line['expires_at'])) {
                    throw new \RuntimeException("An expiry date is required for expiry-tracked product {$product->name}.");
                }

                $receiptLine = PurchaseReceiptLine::create([
                    'id' => (string) Str::uuid(),
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_order_id' => $po?->id,
                    'purchase_order_line_id' => $poLine?->id,
                    'company_id' => $company->id,
                    'branch_id' => $request->branch_id,
                    'supplier_id' => $line['supplier_id'] ?? $poLine?->supplier_id ?? $request->supplier_id ?? $po?->supplier_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'location_id' => $location->id,
                    'lot_number' => $line['lot_number'] ?? null,
                    'manufactured_at' => $line['manufactured_at'] ?? null,
                    'expires_at' => $line['expires_at'] ?? null,
                    'quantity' => (float) $line['quantity'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'currency_code' => strtoupper($line['currency_code'] ?? $request->currency_code),
                    'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                    'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                    'line_total' => $lineTotal,
                    'notes' => $line['notes'] ?? null,
                ]);

                if ($poLine) {
                    $poLine->received_quantity = (float) $poLine->received_quantity + (float) $line['quantity'];
                    $poLine->unit_cost = (float) $line['unit_cost'];
                    $poLine->supplier_id = $receiptLine->supplier_id;
                    $poLine->save();
                }

                $batchId = $this->receiveInventoryBatch(
                    $product,
                    $request->branch_id,
                    $warehouse->id,
                    $location->id,
                    $receiptLine,
                    (float) $line['quantity'],
                    (float) $line['unit_cost'],
                    strtoupper($line['currency_code'] ?? $request->currency_code),
                    $exchangeRate
                );
                $this->receiveStockForPurchase(
                    $product,
                    $request->branch_id,
                    (float) $line['quantity'],
                    (float) $line['unit_cost'],
                    $receipt->id,
                    $receiptLine->id,
                    $receipt->grn_number,
                    $warehouse->id,
                    $location->id,
                    $batchId,
                    strtoupper($line['currency_code'] ?? $request->currency_code)
                );
            }

            if ($receipt->wasRecentlyCreated === false) {
                $receipt->subtotal = (float) $receipt->subtotal + $totals['subtotal'];
                $receipt->tax_total = (float) $receipt->tax_total + $totals['tax_total'];
                $receipt->grand_total = (float) $receipt->grand_total + $totals['grand_total'];
                $receipt->base_grand_total = (float) $receipt->base_grand_total + ($totals['grand_total'] * $exchangeRate);
                $receipt->save();
            }

            if ($po) $this->refreshPurchaseOrderStatus($po->fresh());

            DB::commit();
            $loaded = $this->loadReceipt($receipt->id);
            $this->decorateReceipt($loaded);
            return response()->json(['message' => 'Goods received successfully.', 'data' => $loaded], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Receiving failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function storeReturn(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $permission = hasPermission($user->role_id, 'purchase_returns', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'purchase_order_id' => ['nullable', Rule::exists('purchase_orders', 'id')->where('company_id', $company->id)],
            'purchase_receipt_id' => ['nullable', Rule::exists('purchase_receipts', 'id')->where('company_id', $company->id)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'return_date' => 'required|date',
            'currency_code' => 'required|string|max:10',
            'reason' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id, [(string) $request->branch_id])['branch_ids'];

        $po = $request->purchase_order_id ? PurchaseOrder::where('company_id', $company->id)->whereIn('branch_id', $branchIds)->find($request->purchase_order_id) : null;
        $receipt = $request->purchase_receipt_id ? PurchaseReceipt::where('company_id', $company->id)->whereIn('branch_id', $branchIds)->find($request->purchase_receipt_id) : null;
        if (($request->purchase_order_id && (!$po || (string) $po->branch_id !== (string) $request->branch_id))
            || ($request->purchase_receipt_id && (!$receipt || (string) $receipt->branch_id !== (string) $request->branch_id))) {
            return response()->json(['message' => 'The selected purchase document is not available for this branch and workspace.'], 422);
        }

        DB::beginTransaction();
        try {
            $totals = $this->calculateTotals($request->lines, 'quantity');
            $return = PurchaseReturn::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'purchase_order_id' => $po?->id,
                'purchase_receipt_id' => $receipt?->id,
                'supplier_id' => $request->supplier_id ?: $receipt?->supplier_id ?: $po?->supplier_id,
                'return_number' => $request->return_number ?: $this->nextNumber('PRN'),
                'return_date' => $request->return_date,
                'currency_code' => strtoupper($request->currency_code),
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'status' => 'posted',
                'reason' => $request->reason,
                'notes' => $request->notes,
                'created_by' => $user?->id,
            ]);

            foreach ($request->lines as $line) {
                $product = $this->purchaseProduct((string) $company->id, (string) $line['product_id'], $branchIds, true);
                if (!empty($line['purchase_order_line_id'])) {
                    $validOrderLine = PurchaseOrderLine::where('company_id', $company->id)
                        ->whereIn('branch_id', $branchIds)
                        ->when($po, fn ($query) => $query->where('purchase_order_id', $po->id))
                        ->where('id', $line['purchase_order_line_id'])
                        ->exists();
                    if (!$validOrderLine) throw new \RuntimeException('A selected purchase-order line is not available in this workspace.');
                }
                if (!empty($line['purchase_receipt_line_id'])) {
                    $validReceiptLine = PurchaseReceiptLine::where('company_id', $company->id)
                        ->whereIn('branch_id', $branchIds)
                        ->when($receipt, fn ($query) => $query->where('purchase_receipt_id', $receipt->id))
                        ->where('id', $line['purchase_receipt_line_id'])
                        ->exists();
                    if (!$validReceiptLine) throw new \RuntimeException('A selected receipt line is not available in this workspace.');
                }
                $lineTotal = ((float) $line['quantity'] * (float) $line['unit_cost']) + (float) ($line['tax_amount'] ?? 0);
                $returnLine = PurchaseReturnLine::create([
                    'id' => (string) Str::uuid(),
                    'purchase_return_id' => $return->id,
                    'purchase_order_id' => $po?->id,
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'purchase_receipt_id' => $receipt?->id,
                    'purchase_receipt_line_id' => $line['purchase_receipt_line_id'] ?? null,
                    'company_id' => $company->id,
                    'branch_id' => $request->branch_id,
                    'supplier_id' => $line['supplier_id'] ?? $request->supplier_id ?? $receipt?->supplier_id ?? $po?->supplier_id,
                    'product_id' => $product->id,
                    'quantity' => (float) $line['quantity'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'currency_code' => strtoupper($line['currency_code'] ?? $request->currency_code),
                    'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                    'line_total' => $lineTotal,
                    'reason' => $line['reason'] ?? $request->reason,
                ]);

                if (!empty($line['purchase_order_line_id'])) {
                    PurchaseOrderLine::where('company_id', $company->id)
                        ->whereIn('branch_id', $branchIds)
                        ->when($po, fn ($query) => $query->where('purchase_order_id', $po->id))
                        ->where('id', $line['purchase_order_line_id'])
                        ->increment('returned_quantity', (float) $line['quantity']);
                }

                $this->returnStockForPurchase($product, $request->branch_id, (float) $line['quantity'], (float) $line['unit_cost'], $return->id, $returnLine->id, $return->return_number);
            }

            if ($po) $this->refreshPurchaseOrderStatus($po->fresh());

            DB::commit();
            return response()->json(['message' => 'Purchase return posted successfully.', 'data' => $this->loadReturn($return->id)], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Purchase return failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function storeSupplierInvoice(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $permission = hasPermission($user->role_id, 'supplier_invoices', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'purchase_order_id' => ['nullable', Rule::exists('purchase_orders', 'id')->where('company_id', $company->id)],
            'purchase_receipt_id' => ['nullable', Rule::exists('purchase_receipts', 'id')->where('company_id', $company->id)],
            'invoice_number' => 'required|string|max:120',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'currency_code' => 'required|string|max:10',
            'status' => 'nullable|in:draft,posted',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        $scope = $this->purchaseBranchScope(
            $request,
            (string) $company->id,
            $request->filled('branch_id') ? [(string) $request->branch_id] : []
        );
        $branchId = $request->filled('branch_id') ? (string) $request->branch_id : $scope['context_branch_id'];
        $linkedOrder = $request->purchase_order_id
            ? PurchaseOrder::where('company_id', $company->id)->whereIn('branch_id', $scope['branch_ids'])->find($request->purchase_order_id)
            : null;
        $linkedReceipt = $request->purchase_receipt_id
            ? PurchaseReceipt::where('company_id', $company->id)->whereIn('branch_id', $scope['branch_ids'])->find($request->purchase_receipt_id)
            : null;
        if (($request->purchase_order_id && !$linkedOrder) || ($request->purchase_receipt_id && !$linkedReceipt)) {
            return response()->json(['message' => 'A linked purchase document is not available in the active workspace.'], 422);
        }
        if (($linkedOrder && (string) $linkedOrder->branch_id !== $branchId)
            || ($linkedReceipt && (string) $linkedReceipt->branch_id !== $branchId)) {
            return response()->json(['message' => 'Linked purchase documents must belong to the invoice branch.'], 422);
        }

        DB::beginTransaction();
        try {
            $totals = $this->calculateTotals($request->lines, 'quantity');
            $invoice = SupplierInvoice::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'supplier_id' => $request->supplier_id,
                'purchase_order_id' => $request->purchase_order_id,
                'purchase_receipt_id' => $request->purchase_receipt_id,
                'invoice_number' => $request->invoice_number,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'currency_code' => strtoupper($request->currency_code),
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_total' => $totals['discount_total'],
                'grand_total' => $totals['grand_total'],
                'paid_amount' => 0,
                'balance_due' => $totals['grand_total'],
                'status' => $request->status ?: 'posted',
                'notes' => $request->notes,
                'created_by' => $user?->id,
            ]);

            foreach ($request->lines as $line) {
                $product = !empty($line['product_id'])
                    ? $this->purchaseProduct((string) $company->id, (string) $line['product_id'], [$branchId], true)
                    : null;
                $lineOrderId = $line['purchase_order_id'] ?? $request->purchase_order_id;
                $lineReceiptId = $line['purchase_receipt_id'] ?? $request->purchase_receipt_id;
                if ($lineOrderId && !PurchaseOrder::where('company_id', $company->id)->where('branch_id', $branchId)->where('id', $lineOrderId)->exists()) {
                    throw new \RuntimeException('A supplier-invoice line references an unavailable purchase order.');
                }
                if ($lineReceiptId && !PurchaseReceipt::where('company_id', $company->id)->where('branch_id', $branchId)->where('id', $lineReceiptId)->exists()) {
                    throw new \RuntimeException('A supplier-invoice line references an unavailable receipt.');
                }
                if (!empty($line['purchase_order_line_id']) && !PurchaseOrderLine::where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->when($lineOrderId, fn ($query) => $query->where('purchase_order_id', $lineOrderId))
                    ->where('id', $line['purchase_order_line_id'])
                    ->exists()) {
                    throw new \RuntimeException('A supplier-invoice line references an unavailable purchase-order line.');
                }
                if (!empty($line['purchase_receipt_line_id']) && !PurchaseReceiptLine::where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->when($lineReceiptId, fn ($query) => $query->where('purchase_receipt_id', $lineReceiptId))
                    ->where('id', $line['purchase_receipt_line_id'])
                    ->exists()) {
                    throw new \RuntimeException('A supplier-invoice line references an unavailable receipt line.');
                }
                $lineTotal = ((float) $line['quantity'] * (float) $line['unit_cost']) - (float) ($line['discount_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
                SupplierInvoiceLine::create([
                    'id' => (string) Str::uuid(),
                    'supplier_invoice_id' => $invoice->id,
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'supplier_id' => $line['supplier_id'] ?? $request->supplier_id,
                    'purchase_order_id' => $lineOrderId,
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'purchase_receipt_id' => $lineReceiptId,
                    'purchase_receipt_line_id' => $line['purchase_receipt_line_id'] ?? null,
                    'product_id' => $product?->id,
                    'description' => $line['description'] ?? $product?->name ?? 'Supplier invoice line',
                    'quantity' => (float) $line['quantity'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'currency_code' => strtoupper($line['currency_code'] ?? $request->currency_code),
                    'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                    'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                    'line_total' => $lineTotal,
                    'metadata' => $line['metadata'] ?? null,
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Supplier invoice saved successfully.', 'data' => $this->loadInvoice($invoice->id)], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Supplier invoice failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function receipts(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $query = PurchaseReceipt::where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->with([
                'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'lines.product', 'supplier', 'branch',
            ])
            ->withCount(['lines' => fn ($query) => $query->whereIn('branch_id', $branchIds)])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('purchase_order_id'), fn ($q) => $q->where('purchase_order_id', $request->purchase_order_id))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->q);
                $q->where(fn ($sub) => $sub->where('grn_number', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$term}%")));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('received_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('received_date', '<=', $request->date_to))
            ->latest();
        $page = $query->paginate(min(max((int) ($request->per_page ?? 25), 1), 200));
        $page->getCollection()->each(fn ($receipt) => $this->decorateReceipt($receipt));
        return response()->json(['message' => 'Purchase receipts fetched successfully.', 'data' => $page]);
    }

    public function returns(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $query = PurchaseReturn::where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->with([
                'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'lines.product',
            ])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('return_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('return_date', '<=', $request->date_to))
            ->latest();
        return response()->json(['message' => 'Purchase returns fetched successfully.', 'data' => $query->paginate($request->per_page ?? 25)]);
    }

    public function invoices(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $query = SupplierInvoice::where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->with([
                'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'lines.product',
            ])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->date_to))
            ->latest();
        return response()->json(['message' => 'Supplier invoices fetched successfully.', 'data' => $query->paginate($request->per_page ?? 25)]);
    }

    public function supplierPayments(Request $request)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $rows = DB::table('supplier_payments as sp')
            ->leftJoin('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->where('sp.company_id', $company->id)
            ->whereIn('sp.branch_id', $branchIds)
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('sp.supplier_id', $request->supplier_id))
            ->when($request->filled('status'), fn ($q) => $q->where('sp.status', $request->status))
            ->select(['sp.*', 's.name as supplier_name'])->orderByDesc('sp.payment_date')->orderByDesc('sp.created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 200));
        return response()->json(['message' => 'Supplier payments fetched successfully.', 'data' => $rows]);
    }

    public function storeSupplierPayment(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|string', 'supplier_id' => 'required|string', 'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank_transfer,card,mobile_money,cheque,other',
            'currency_code' => 'required|string|max:10', 'exchange_rate' => 'required|numeric|gt:0', 'amount' => 'required|numeric|gt:0',
            'reference' => 'nullable|string|max:160', 'notes' => 'nullable|string',
            'allocations' => 'required|array|min:1', 'allocations.*.supplier_invoice_id' => 'required|string', 'allocations.*.amount' => 'required|numeric|gt:0',
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        $scope = $this->purchaseBranchScope(
            $request,
            (string) $company->id,
            $request->filled('branch_id') ? [(string) $request->branch_id] : []
        );
        $branchId = $request->filled('branch_id') ? (string) $request->branch_id : $scope['context_branch_id'];
        $branchIds = $scope['branch_ids'];

        try {
            $paymentId = DB::transaction(function () use ($request, $company, $user, $branchId, $branchIds) {
                $currency = strtoupper($request->currency_code);
                $amount = round((float) $request->amount, 4);
                $allocated = round(collect($request->allocations)->sum(fn ($row) => (float) $row['amount']), 4);
                if (abs($allocated - $amount) > 0.0001) throw new \RuntimeException('Payment allocations must equal the payment amount.');
                $supplierExists = DB::table('suppliers')->where('company_id', $company->id)->where('id', $request->supplier_id)->exists();
                if (!$supplierExists) throw new \RuntimeException('Supplier does not belong to this company.');

                $id = (string) Str::uuid();
                DB::table('supplier_payments')->insert([
                    'id' => $id, 'company_id' => $company->id, 'branch_id' => $branchId, 'supplier_id' => $request->supplier_id,
                    'payment_number' => 'SP-' . now()->format('ymdHis') . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 4)),
                    'payment_date' => $request->payment_date, 'payment_method' => $request->payment_method, 'currency_code' => $currency,
                    'exchange_rate' => (float) $request->exchange_rate, 'amount' => $amount, 'base_amount' => round($amount * (float) $request->exchange_rate, 4),
                    'reference' => $request->reference, 'status' => 'posted', 'notes' => $request->notes, 'created_by' => $user?->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($request->allocations as $allocation) {
                    $invoice = DB::table('supplier_invoices')->where('company_id', $company->id)->where('supplier_id', $request->supplier_id)
                        ->whereIn('branch_id', $branchIds)
                        ->where('id', $allocation['supplier_invoice_id'])->whereNotIn('status', ['draft', 'cancelled', 'paid'])->lockForUpdate()->first();
                    if (!$invoice) throw new \RuntimeException('An allocation references an unavailable supplier invoice.');
                    if (strtoupper($invoice->currency_code) !== $currency) throw new \RuntimeException('Payment and invoice currencies must match; create a separate payment per currency.');
                    $allocationAmount = round((float) $allocation['amount'], 4);
                    if ($allocationAmount > (float) $invoice->balance_due + 0.0001) throw new \RuntimeException("Allocation exceeds balance on invoice {$invoice->invoice_number}.");
                    DB::table('supplier_payment_allocations')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_payment_id' => $id,
                        'supplier_invoice_id' => $invoice->id, 'amount' => $allocationAmount,
                        'base_amount' => round($allocationAmount * (float) $request->exchange_rate, 4), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $newPaid = round((float) $invoice->paid_amount + $allocationAmount, 4);
                    $newBalance = max(0, round((float) $invoice->grand_total - $newPaid, 4));
                    DB::table('supplier_invoices')->where('id', $invoice->id)->update(['paid_amount' => $newPaid, 'balance_due' => $newBalance, 'status' => $newBalance <= 0.0001 ? 'paid' : 'partially_paid', 'updated_at' => now()]);
                }
                return $id;
            });
            return response()->json(['message' => 'Supplier payment posted and allocated successfully.', 'data' => DB::table('supplier_payments')->where('company_id', $company->id)->find($paymentId)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Supplier payment was not posted.', 'error' => $e->getMessage()], 422);
        }
    }

    public function reverseSupplierPayment(Request $request, string $id)
    {
        $company = app('company');
        $branchIds = $this->purchaseBranchScope($request, (string) $company->id)['branch_ids'];
        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:500']);
        if ($validator->fails()) return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        try {
            DB::transaction(function () use ($id, $company, $request, $branchIds) {
                $payment = DB::table('supplier_payments')->where('company_id', $company->id)->whereIn('branch_id', $branchIds)->where('id', $id)->lockForUpdate()->first();
                if (!$payment || $payment->status !== 'posted') throw new \RuntimeException('Posted supplier payment not found.');
                $allocations = DB::table('supplier_payment_allocations')->where('company_id', $company->id)->where('supplier_payment_id', $id)->get();
                $invoiceIds = $allocations->pluck('supplier_invoice_id')->filter()->unique()->values();
                if ($invoiceIds->isNotEmpty()) {
                    $scopedInvoiceCount = DB::table('supplier_invoices')
                        ->where('company_id', $company->id)
                        ->whereIn('branch_id', $branchIds)
                        ->whereIn('id', $invoiceIds)
                        ->count();
                    if ($scopedInvoiceCount !== $invoiceIds->count()) {
                        throw new \RuntimeException('This payment contains an allocation outside the active workspace.');
                    }
                }
                foreach ($allocations as $allocation) {
                    $invoice = DB::table('supplier_invoices')->where('company_id', $company->id)->whereIn('branch_id', $branchIds)->where('id', $allocation->supplier_invoice_id)->lockForUpdate()->first();
                    if (!$invoice) throw new \RuntimeException('A supplier invoice allocation is no longer available in this workspace.');
                    $paid = max(0, round((float) $invoice->paid_amount - (float) $allocation->amount, 4));
                    $balance = max(0, round((float) $invoice->grand_total - $paid, 4));
                    DB::table('supplier_invoices')->where('id', $invoice->id)->update(['paid_amount' => $paid, 'balance_due' => $balance, 'status' => $paid > 0 ? 'partially_paid' : 'posted', 'updated_at' => now()]);
                }
                $notes = trim((string) ($payment->notes ?? ''));
                $notes .= ($notes === '' ? '' : "\n") . 'Reversal: ' . $request->reason;
                DB::table('supplier_payments')->where('id', $id)->update(['status' => 'reversed', 'reversed_by' => auth()->id(), 'reversed_at' => now(), 'notes' => $notes, 'updated_at' => now()]);
            });
            return response()->json(['message' => 'Supplier payment reversed and invoice balances restored.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Supplier payment reversal failed.', 'error' => $e->getMessage()], 422);
        }
    }

    public function showOrder(string $id) { $row = $this->loadOrder($id); return $row ? response()->json(['message' => 'Purchase order fetched successfully.', 'data' => $row]) : response()->json(['message' => 'Purchase order not found.'], 404); }
    public function showReceipt(string $id)
    {
        $row = $this->loadReceipt($id);
        if (!$row) return response()->json(['message' => 'Purchase receipt not found.'], 404);
        $this->decorateReceipt($row);
        return response()->json(['message' => 'Purchase receipt fetched successfully.', 'data' => $row]);
    }

    public function receiptPdf(Request $request, string $id)
    {
        $receipt = $this->loadReceipt($id);
        if (!$receipt) abort(404, 'Goods received note not found.');

        return $this->purchaseDocumentPdf($request, 'receipt', $receipt);
    }

    public function orderPdf(Request $request, string $id)
    {
        $order = $this->loadOrder($id);
        if (!$order) abort(404, 'Purchase order not found.');

        return $this->purchaseDocumentPdf($request, 'order', $order);
    }

    public function returnPdf(Request $request, string $id)
    {
        $return = $this->loadReturn($id);
        if (!$return) abort(404, 'Purchase return not found.');

        return $this->purchaseDocumentPdf($request, 'return', $return);
    }

    public function supplierInvoicePdf(Request $request, string $id)
    {
        $invoice = $this->loadInvoice($id);
        if (!$invoice) abort(404, 'Supplier invoice not found.');

        return $this->purchaseDocumentPdf($request, 'invoice', $invoice);
    }

    public function purchaseRegisterExport(Request $request, string $type, string $format)
    {
        $records = $this->purchaseRegisterRows($request, $type);
        $definition = $this->purchaseRegisterDefinition($type);
        $filename = $definition['slug'] . '-' . now()->format('Ymd-His') . ($format === 'pdf' ? '.pdf' : '.xlsx');
        $section = [
            'title' => $definition['title'],
            'headers' => $definition['headers'],
            'rows' => $records,
        ];

        if ($format === 'pdf') {
            return $this->reportDownloads->downloadPdf(
                title: $definition['title'],
                filename: $filename,
                sections: [
                    ['title' => 'Report Scope', 'headers' => ['Filter', 'Value'], 'rows' => $this->purchaseReportScopeRows($request)],
                    $section,
                ],
                rowLimit: 1000
            );
        }

        return $this->reportDownloads->downloadSpreadsheet(
            title: $definition['title'],
            filename: $filename,
            sheets: [
                ['title' => 'Report Scope', 'headers' => ['Filter', 'Value'], 'rows' => $this->purchaseReportScopeRows($request)],
                $section,
            ],
            autoFilter: true
        );
    }
    public function showReturn(string $id) { $row = $this->loadReturn($id); return $row ? response()->json(['message' => 'Purchase return fetched successfully.', 'data' => $row]) : response()->json(['message' => 'Purchase return not found.'], 404); }
    public function showInvoice(string $id) { $row = $this->loadInvoice($id); return $row ? response()->json(['message' => 'Supplier invoice fetched successfully.', 'data' => $row]) : response()->json(['message' => 'Supplier invoice not found.'], 404); }

    /**
     * @param  array<int, string>  $requestedBranchIds
     * @return array{business_mode: string, context_branch_id: string, branch_ids: array<int, string>, accessible_branch_ids: array<int, string>}
     */
    private function purchaseBranchScope(Request $request, string $companyId, array $requestedBranchIds = []): array
    {
        if ($requestedBranchIds === [] && $request->filled('branch_id')) {
            $requestedBranchIds = [(string) $request->input('branch_id')];
        }

        return app(BusinessModeBranchScopeService::class)->resolve($request, $companyId, $requestedBranchIds);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function purchaseLineLocationsValid(string $companyId, string $branchId, array $lines): bool
    {
        foreach ($lines as $line) {
            $warehouseId = data_get($line, 'warehouse_id');
            $locationId = data_get($line, 'location_id');

            if ($warehouseId) {
                $warehouseExists = DB::table('inventory_warehouses')
                    ->where('company_id', $companyId)
                    ->where('id', $warehouseId)
                    ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$warehouseExists) return false;
            }

            if ($locationId) {
                $locationExists = DB::table('inventory_locations as purchase_location')
                    ->join('inventory_warehouses as purchase_warehouse', 'purchase_warehouse.id', '=', 'purchase_location.warehouse_id')
                    ->where('purchase_location.company_id', $companyId)
                    ->where('purchase_warehouse.company_id', $companyId)
                    ->where('purchase_location.id', $locationId)
                    ->when($warehouseId, fn ($query) => $query->where('purchase_location.warehouse_id', $warehouseId))
                    ->where(fn ($query) => $query->where('purchase_location.branch_id', $branchId)->orWhereNull('purchase_location.branch_id'))
                    ->where(fn ($query) => $query->where('purchase_warehouse.branch_id', $branchId)->orWhereNull('purchase_warehouse.branch_id'))
                    ->whereNull('purchase_location.deleted_at')
                    ->whereNull('purchase_warehouse.deleted_at')
                    ->exists();
                if (!$locationExists) return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $branchIds */
    private function purchaseProduct(string $companyId, string $productId, array $branchIds, bool $required = false): ?Product
    {
        $query = Product::query()
            ->where('company_id', $companyId);
        if (Schema::hasColumn('products', 'branch_id')) {
            $query->where(function ($scope) use ($branchIds) {
                $scope->whereNull('branch_id')->orWhereIn('branch_id', $branchIds);
            });
        }

        return $required ? $query->findOrFail($productId) : $query->find($productId);
    }

    private function linePayload(string $companyId, string $branchId, string $poId, Product $product, array $line): array
    {
        $qty = (float) $line['ordered_quantity'];
        $unitCost = (float) $line['unit_cost'];
        $discount = (float) ($line['discount_amount'] ?? 0);
        $tax = (float) ($line['tax_amount'] ?? 0);

        return [
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $poId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'supplier_id' => $line['supplier_id'] ?? null,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'sku' => $product->sku,
            'ordered_quantity' => $qty,
            'received_quantity' => (float) ($line['received_quantity'] ?? 0),
            'returned_quantity' => (float) ($line['returned_quantity'] ?? 0),
            'unit_cost' => $unitCost,
            'currency_code' => strtoupper($line['currency_code'] ?? 'USD'),
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'line_total' => ($qty * $unitCost) - $discount + $tax,
            'tax_details' => $line['tax_details'] ?? null,
            'notes' => $line['notes'] ?? null,
        ];
    }

    private function calculateTotals(array $lines, string $qtyKey = 'ordered_quantity'): array
    {
        $subtotal = $discount = $tax = $grand = 0;
        foreach ($lines as $line) {
            $qty = (float) ($line[$qtyKey] ?? $line['ordered_quantity'] ?? $line['quantity'] ?? 0);
            $unitCost = (float) ($line['unit_cost'] ?? 0);
            $lineDiscount = (float) ($line['discount_amount'] ?? 0);
            $lineTax = (float) ($line['tax_amount'] ?? 0);
            $subtotal += $qty * $unitCost;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $grand += ($qty * $unitCost) - $lineDiscount + $lineTax;
        }
        return [
            'subtotal' => round($subtotal, 4),
            'discount_total' => round($discount, 4),
            'tax_total' => round($tax, 4),
            'grand_total' => round($grand, 4),
        ];
    }

    private function receiveStockForPurchase(Product $product, string $branchId, float $quantity, float $unitCost, string $receiptId, string $receiptLineId, string $grnNumber, ?string $warehouseId = null, ?string $locationId = null, ?string $batchId = null, ?string $currencyCode = null): void
    {
        // product_stocks is the branch/stock-area aggregate. Warehouse, location
        // and batch detail is maintained in inventory_location_balances and
        // inventory_batches by receiveInventoryBatch(). Keeping those keys out
        // of this lookup also preserves the product_stocks unique index.
        $stock = ProductStock::firstOrCreate([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'stock_area' => 'back',
        ], [
            'quantity_on_hand' => 0,
            'reserved_quantity' => 0,
            'available_quantity' => 0,
        ]);

        $stock->quantity_on_hand = (float) $stock->quantity_on_hand + $quantity;
        $stock->available_quantity = (float) $stock->available_quantity + $quantity;
        $stock->save();

        $cost = ProductCost::firstOrCreate([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
        ], [
            'average_cost' => 0,
            'last_cost' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
            'currency_code' => $currencyCode,
        ]);

        $newValue = $quantity * $unitCost;
        $newTotalQuantity = (float) $cost->total_quantity + $quantity;
        $newTotalValue = (float) $cost->total_value + $newValue;

        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newTotalQuantity;
        $cost->total_value = $newTotalValue;
        $cost->average_cost = $newTotalQuantity > 0 ? $newTotalValue / $newTotalQuantity : 0;
        $cost->currency_code = $currencyCode ?: $cost->currency_code;
        $cost->save();

        StockMovement::create([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'product_id' => $product->id,
            'movement_type' => 'purchase',
            'stock_area' => 'back',
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => $unitCost,
            'total_cost' => $newValue,
            'currency' => $currencyCode,
            'currency_code' => $currencyCode,
            'reference_type' => PurchaseReceipt::class,
            'reference_id' => $receiptId,
            'notes' => "GRN {$grnNumber} / line {$receiptLineId}",
            'created_by' => auth()->id(),
        ]);
    }

    private function receiveInventoryBatch(Product $product, string $branchId, string $warehouseId, string $locationId, PurchaseReceiptLine $receiptLine, float $quantity, float $unitCost, string $currencyCode, float $exchangeRate): ?string
    {
        $lotNumber = trim((string) ($receiptLine->lot_number ?? ''));
        $batchId = null;

        if ($product->batch_tracking_enabled || $product->expiry_tracking_enabled || $lotNumber !== '') {
            $lotNumber = $lotNumber !== '' ? $lotNumber : 'GRN-' . $receiptLine->id;
            $batch = DB::table('inventory_batches')
                ->where('company_id', $product->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $batchId = $batch->id;
                DB::table('inventory_batches')->where('id', $batchId)->update([
                    'quantity_received' => (float) $batch->quantity_received + $quantity,
                    'quantity_on_hand' => (float) $batch->quantity_on_hand + $quantity,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $unitCost * $exchangeRate,
                    'expires_at' => $receiptLine->expires_at ?: $batch->expires_at,
                    'updated_at' => now(),
                ]);
            } else {
                $batchId = (string) Str::uuid();
                DB::table('inventory_batches')->insert([
                    'id' => $batchId,
                    'company_id' => $product->company_id,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'product_id' => $product->id,
                    'purchase_receipt_line_id' => $receiptLine->id,
                    'lot_number' => $lotNumber,
                    'manufactured_at' => $receiptLine->manufactured_at,
                    'expires_at' => $receiptLine->expires_at,
                    'quantity_received' => $quantity,
                    'quantity_on_hand' => $quantity,
                    'reserved_quantity' => 0,
                    'quarantined_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $unitCost * $exchangeRate,
                    'currency_code' => $currencyCode,
                    'exchange_rate' => $exchangeRate,
                    'status' => 'available',
                    'quality_status' => 'released',
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $balanceQuery = DB::table('inventory_location_balances')
            ->where('company_id', $product->company_id)
            ->where('location_id', $locationId)
            ->where('product_id', $product->id)
            ->when($batchId, fn ($query) => $query->where('batch_id', $batchId), fn ($query) => $query->whereNull('batch_id'));
        $balance = $balanceQuery->lockForUpdate()->first();
        if ($balance) {
            $balanceQuery->update([
                'quantity_on_hand' => (float) $balance->quantity_on_hand + $quantity,
                'available_quantity' => (float) $balance->available_quantity + $quantity,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('inventory_location_balances')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'product_id' => $product->id,
                'batch_id' => $batchId,
                'quantity_on_hand' => $quantity,
                'reserved_quantity' => 0,
                'available_quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $batchId;
    }

    private function returnStockForPurchase(Product $product, string $branchId, float $quantity, float $unitCost, string $returnId, string $returnLineId, string $returnNumber): void
    {
        $stock = ProductStock::firstOrCreate([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'stock_area' => 'back',
        ], [
            'quantity_on_hand' => 0,
            'reserved_quantity' => 0,
            'available_quantity' => 0,
        ]);

        if (!$product->allow_negative_stock && (float) $stock->available_quantity < $quantity) {
            throw new \Exception("Insufficient stock to return {$product->name}.");
        }

        $stock->quantity_on_hand = (float) $stock->quantity_on_hand - $quantity;
        $stock->available_quantity = (float) $stock->available_quantity - $quantity;
        $stock->save();

        $cost = ProductCost::where('company_id', $product->company_id)->where('branch_id', $branchId)->where('product_id', $product->id)->first();
        $currencyCode = $cost?->currency_code ?? $cost?->currency ?? null;
        if ($cost) {
            $returnValue = $quantity * $unitCost;
            $cost->total_quantity = max(0, (float) $cost->total_quantity - $quantity);
            $cost->total_value = max(0, (float) $cost->total_value - $returnValue);
            $cost->average_cost = (float) $cost->total_quantity > 0 ? (float) $cost->total_value / (float) $cost->total_quantity : 0;
            $cost->save();
        }

        StockMovement::create([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'movement_type' => 'purchase_return',
            'stock_area' => 'back',
            'from_stock_area' => 'back',
            'quantity_in' => 0,
            'quantity_out' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'currency' => $currencyCode,
            'currency_code' => $currencyCode,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $returnId,
            'notes' => "Purchase return {$returnNumber} / line {$returnLineId}",
            'created_by' => auth()->id(),
        ]);
    }

    private function refreshPurchaseOrderStatus(PurchaseOrder $po): void
    {
        $lines = PurchaseOrderLine::where('purchase_order_id', $po->id)->get();
        $ordered = $lines->sum('ordered_quantity');
        $received = $lines->sum('received_quantity');

        if ($ordered > 0 && $received >= $ordered) {
            $po->status = 'received';
        } elseif ($received > 0) {
            $po->status = 'partially_received';
        }
        $po->save();
    }

    private function nextNumber(string $prefix): string
    {
        return $prefix . '-' . now()->format('Ymd-His') . '-' . strtoupper(Str::random(4));
    }

    private function loadOrder(string $id)
    {
        $companyId = (string) app('company')->id;
        $branchIds = $this->purchaseBranchScope(request(), $companyId)['branch_ids'];

        return PurchaseOrder::with([
            'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'lines.product',
            'receipts' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'receipts.lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'receipts.lines.product',
            'returns' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'returns.lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'returns.lines.product',
            'invoices' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'invoices.lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'invoices.lines.product',
        ])->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->find($id);
    }
//    private function loadReceipt(string $id) { return PurchaseReceipt::with(['lines.product', 'order'])->find($id); }

    private function loadReceipt(string $id)
    {
        $companyId = (string) app('company')->id;
        $branchIds = $this->purchaseBranchScope(request(), $companyId)['branch_ids'];

        return PurchaseReceipt::with([
            'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'lines.product',
            'lines.orderLine',
            'lines.warehouse',
            'lines.location',
            'order' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'supplier',
            'branch',
        ])->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->find($id);
    }

    private function decorateReceipt(PurchaseReceipt $receipt): PurchaseReceipt
    {
        $receipt->setAttribute('document_url', url("api/pos/purchases/receipts/{$receipt->id}/pdf?download=0"));
        $receipt->setAttribute('download_url', url("api/pos/purchases/receipts/{$receipt->id}/pdf?download=1"));
        return $receipt;
    }
    private function loadReturn(string $id)
    {
        $companyId = (string) app('company')->id;
        $branchIds = $this->purchaseBranchScope(request(), $companyId)['branch_ids'];

        return PurchaseReturn::with([
            'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'lines.product',
            'order' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'receipt' => fn ($query) => $query->whereIn('branch_id', $branchIds),
        ])->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->find($id);
    }

    private function loadInvoice(string $id)
    {
        $companyId = (string) app('company')->id;
        $branchIds = $this->purchaseBranchScope(request(), $companyId)['branch_ids'];

        return SupplierInvoice::with([
            'lines' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'lines.product',
            'order' => fn ($query) => $query->whereIn('branch_id', $branchIds),
            'receipt' => fn ($query) => $query->whereIn('branch_id', $branchIds),
        ])->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->find($id);
    }

    private function purchaseDocumentPdf(Request $request, string $type, object $document)
    {
        $definitions = [
            'order' => ['title' => 'Purchase Order', 'number' => 'po_number', 'date' => 'order_date', 'quantity' => 'ordered_quantity', 'slug' => 'purchase-order'],
            'receipt' => ['title' => 'Goods Received Note', 'number' => 'grn_number', 'date' => 'received_date', 'quantity' => 'received_quantity', 'slug' => 'goods-received-note'],
            'return' => ['title' => 'Purchase Return Note', 'number' => 'return_number', 'date' => 'return_date', 'quantity' => 'quantity', 'slug' => 'purchase-return-note'],
            'invoice' => ['title' => 'Supplier Invoice', 'number' => 'invoice_number', 'date' => 'invoice_date', 'quantity' => 'quantity', 'slug' => 'supplier-invoice'],
        ];
        $definition = $definitions[$type] ?? abort(404, 'Purchase document type not found.');
        $number = (string) data_get($document, $definition['number'], data_get($document, 'id'));
        $currency = strtoupper((string) data_get($document, 'currency_code', ''));
        $supplier = $this->purchaseLookupName('suppliers', data_get($document, 'supplier_id'), ['name', 'supplier_name', 'code']);
        $branch = $this->purchaseLookupName('company_branches', data_get($document, 'branch_id'), ['branch_name', 'branch_code']);
        $summaryRows = [
            ['Document Number', $number],
            ['Document Date', $this->purchaseDateText(data_get($document, $definition['date']))],
            ['Supplier', $supplier],
            ['Branch', $branch],
            ['Status', (string) data_get($document, 'status', '-')],
            ['Currency', $currency ?: '-'],
            ['Exchange Rate', $this->purchaseAmount(data_get($document, 'exchange_rate', 1), 6)],
            ['Expected / Due Date', $this->purchaseDateText(data_get($document, 'expected_date', data_get($document, 'due_date')))],
            ['Source', (string) data_get($document, 'source', '-')],
            ['Subtotal', $this->purchaseMoney(data_get($document, 'subtotal'), $currency)],
            ['Discount', $this->purchaseMoney(data_get($document, 'discount_total'), $currency)],
            ['Tax', $this->purchaseMoney(data_get($document, 'tax_total'), $currency)],
            ['Grand Total', $this->purchaseMoney(data_get($document, 'grand_total'), $currency)],
            ['Notes / Reason', (string) data_get($document, 'notes', data_get($document, 'reason', '-'))],
        ];
        $lineRows = collect(data_get($document, 'lines', []))->values()->map(function ($line, $index) use ($definition, $currency) {
            $lineCurrency = strtoupper((string) data_get($line, 'currency_code', $currency));
            return [
                $index + 1,
                data_get($line, 'product_code', data_get($line, 'product.code', data_get($line, 'product.sku', '-'))),
                data_get($line, 'product_name', data_get($line, 'description', data_get($line, 'product.name', '-'))),
                $this->purchaseAmount(data_get($line, $definition['quantity'], 0), 3),
                $this->purchaseMoney(data_get($line, 'unit_cost'), $lineCurrency),
                $this->purchaseMoney(data_get($line, 'discount_amount'), $lineCurrency),
                $this->purchaseMoney(data_get($line, 'tax_amount'), $lineCurrency),
                $this->purchaseMoney(data_get($line, 'line_total'), $lineCurrency),
            ];
        })->all();
        $safeNumber = preg_replace('/[^A-Za-z0-9._-]/', '-', $number ?: (string) data_get($document, 'id'));

        return $this->reportDownloads->downloadPdf(
            title: $definition['title'],
            filename: $definition['slug'] . '-' . $safeNumber . '.pdf',
            sections: [
                ['title' => 'Document Summary', 'headers' => ['Field', 'Value'], 'rows' => $summaryRows],
                ['title' => 'Document Lines', 'headers' => ['#', 'Code', 'Description', 'Quantity', 'Unit Cost', 'Discount', 'Tax', 'Line Total'], 'rows' => $lineRows],
            ],
            download: $request->boolean('download', true)
        );
    }

    private function purchaseRegisterRows(Request $request, string $type): array
    {
        $companyId = (string) app('company')->id;
        $branchIds = $this->purchaseBranchScope($request, $companyId)['branch_ids'];
        $limit = min(max((int) $request->input('export_limit', 5000), 1), 10000);
        $supplierId = $request->input('supplier_id');
        $status = $request->input('status');
        $term = trim((string) $request->input('q', ''));

        if ($type === 'payments') {
            return DB::table('supplier_payments as records')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'records.supplier_id')
                ->leftJoin('company_branches', 'company_branches.id', '=', 'records.branch_id')
                ->where('records.company_id', $companyId)
                ->whereIn('records.branch_id', $branchIds)
                ->when($supplierId, fn ($q) => $q->where('records.supplier_id', $supplierId))
                ->when($status, fn ($q) => $q->where('records.status', $status))
                ->when($request->filled('date_from'), fn ($q) => $q->whereDate('records.payment_date', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn ($q) => $q->whereDate('records.payment_date', '<=', $request->date_to))
                ->when($term !== '', fn ($q) => $q->where(fn ($search) => $search->where('records.payment_number', 'like', "%{$term}%")->orWhere('suppliers.name', 'like', "%{$term}%")))
                ->select(['records.*', 'suppliers.name as supplier_name', DB::raw('COALESCE(company_branches.branch_name, company_branches.branch_code, "-") as branch_name')])
                ->orderByDesc('records.payment_date')->limit($limit)->get()
                ->map(fn ($row) => [
                    $row->payment_number,
                    $this->purchaseDateText($row->payment_date),
                    $row->supplier_name ?: '-',
                    $row->branch_name ?: '-',
                    strtoupper((string) $row->currency_code),
                    $this->purchaseAmount($row->amount),
                    $row->status,
                    $row->notes,
                ])->all();
        }

        $definitions = [
            'orders' => [PurchaseOrder::class, 'order_date', 'po_number'],
            'receipts' => [PurchaseReceipt::class, 'received_date', 'grn_number'],
            'returns' => [PurchaseReturn::class, 'return_date', 'return_number'],
            'invoices' => [SupplierInvoice::class, 'invoice_date', 'invoice_number'],
        ];
        if (!isset($definitions[$type])) abort(404, 'Purchase register type not found.');
        [$model, $dateField, $numberField] = $definitions[$type];
        $records = $model::query()
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate($dateField, '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate($dateField, '<=', $request->date_to))
            ->when($term !== '', fn ($q) => $q->where($numberField, 'like', "%{$term}%"))
            ->orderByDesc($dateField)->limit($limit)->get();
        $supplierNames = DB::table('suppliers')->where('company_id', $companyId)->whereIn('id', $records->pluck('supplier_id')->filter()->unique())->pluck('name', 'id');
        $branchNames = DB::table('company_branches')->where('company_id', $companyId)->whereIn('id', $records->pluck('branch_id')->filter()->unique())->select(['id', DB::raw('COALESCE(branch_name, branch_code, "-") as label')])->pluck('label', 'id');

        return $records->map(fn ($row) => [
            data_get($row, $numberField),
            $this->purchaseDateText(data_get($row, $dateField)),
            $supplierNames->get(data_get($row, 'supplier_id'), '-'),
            $branchNames->get(data_get($row, 'branch_id'), '-'),
            strtoupper((string) data_get($row, 'currency_code', '')),
            $this->purchaseAmount(data_get($row, 'subtotal')),
            $this->purchaseAmount(data_get($row, 'tax_total')),
            $this->purchaseAmount(data_get($row, 'grand_total')),
            (string) data_get($row, 'status', '-'),
        ])->all();
    }

    private function purchaseRegisterDefinition(string $type): array
    {
        return match ($type) {
            'orders' => ['title' => 'Purchase Orders Register', 'slug' => 'purchase-orders', 'headers' => ['Number', 'Date', 'Supplier', 'Branch', 'Currency', 'Subtotal', 'Tax', 'Total', 'Status']],
            'receipts' => ['title' => 'Goods Received Register', 'slug' => 'goods-received', 'headers' => ['Number', 'Date', 'Supplier', 'Branch', 'Currency', 'Subtotal', 'Tax', 'Total', 'Status']],
            'returns' => ['title' => 'Purchase Returns Register', 'slug' => 'purchase-returns', 'headers' => ['Number', 'Date', 'Supplier', 'Branch', 'Currency', 'Subtotal', 'Tax', 'Total', 'Status']],
            'invoices' => ['title' => 'Supplier Invoices Register', 'slug' => 'supplier-invoices', 'headers' => ['Number', 'Date', 'Supplier', 'Branch', 'Currency', 'Subtotal', 'Tax', 'Total', 'Status']],
            'payments' => ['title' => 'Supplier Payments Register', 'slug' => 'supplier-payments', 'headers' => ['Number', 'Date', 'Supplier', 'Branch', 'Currency', 'Amount', 'Status', 'Notes']],
            default => abort(404, 'Purchase register type not found.'),
        };
    }

    private function purchaseReportScopeRows(Request $request): array
    {
        return [
            ['Date From', $request->input('date_from', 'All')],
            ['Date To', $request->input('date_to', 'All')],
            ['Branch', $request->filled('branch_id') ? $this->purchaseLookupName('company_branches', $request->branch_id, ['branch_name', 'branch_code']) : 'All accessible'],
            ['Supplier', $request->filled('supplier_id') ? $this->purchaseLookupName('suppliers', $request->supplier_id, ['name', 'supplier_name']) : 'All'],
            ['Status', $request->input('status', 'All') ?: 'All'],
            ['Search', $request->input('q', '-') ?: '-'],
            ['Generated At', now()->toDateTimeString()],
        ];
    }

    private function purchaseLookupName(string $table, $id, array $columns): string
    {
        if (!$id) return '-';
        $row = DB::table($table)->where('company_id', app('company')->id)->where('id', $id)->first();
        foreach ($columns as $column) {
            $value = trim((string) data_get($row, $column, ''));
            if ($value !== '') return $value;
        }
        return '-';
    }

    private function purchaseMoney($value, string $currency): string
    {
        return trim($currency . ' ' . $this->purchaseAmount($value));
    }

    private function purchaseAmount($value, int $decimals = 2): string
    {
        return number_format((float) ($value ?? 0), $decimals, '.', '');
    }

    private function purchaseDateText($value): string
    {
        if (!$value) return '-';
        try { return \Carbon\Carbon::parse($value)->format('Y-m-d'); }
        catch (\Throwable) { return (string) $value; }
    }
}
