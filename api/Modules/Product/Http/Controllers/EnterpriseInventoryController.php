<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Inventory\InventoryBatchSynchronizer;
use App\Services\Inventory\StockAreaOverviewService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Product\Support\HospitalityMultiBranchTradingDayGuard;

class EnterpriseInventoryController extends Controller
{
    public function dashboard(
        Request $request,
        StockAreaOverviewService $stockAreaOverview,
        BusinessModeBranchScopeService $branchScope
    ): JsonResponse
    {
        $company = app('company');
        $scope = $this->dashboardBranchScope($request, (string) $company->id, $branchScope);
        $branchIds = $scope['branch_ids'];

        $stock = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.company_id', '=', 'ps.company_id')
                    ->on('pc.branch_id', '=', 'ps.branch_id')
                    ->on('pc.product_id', '=', 'ps.product_id');
            })
            ->where('ps.company_id', $company->id)
            ->whereIn('ps.branch_id', $branchIds);

        $summary = (clone $stock)->selectRaw('
            COUNT(DISTINCT ps.product_id) as stocked_products,
            COALESCE(SUM(ps.quantity_on_hand), 0) as quantity_on_hand,
            COALESCE(SUM(ps.reserved_quantity), 0) as reserved_quantity,
            COALESCE(SUM(ps.available_quantity), 0) as available_quantity,
            COALESCE(SUM(ps.quantity_on_hand * COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)), 0) as stock_value,
            COUNT(DISTINCT CASE WHEN ps.available_quantity <= p.reorder_level THEN ps.product_id END) as low_stock_products,
            COUNT(DISTINCT CASE WHEN ps.available_quantity < 0 THEN ps.product_id END) as negative_stock_products
        ')->first();

        $lowStockProducts = DB::query()->fromSub(
            DB::table('product_stocks as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->where('ps.company_id', $company->id)
                ->whereIn('ps.branch_id', $branchIds)
                ->selectRaw('ps.branch_id, ps.product_id, MAX(p.reorder_level) as reorder_level, SUM(ps.available_quantity) as available_quantity')
                ->groupBy('ps.branch_id', 'ps.product_id'),
            'stock_totals'
        )->whereColumn('available_quantity', '<=', 'reorder_level')->count();

        $branchSummary = (clone $stock)
            ->leftJoin('company_branches as cb', 'cb.id', '=', 'ps.branch_id')
            ->selectRaw('
                ps.branch_id,
                COALESCE(cb.branch_name, cb.branch_code, "Unassigned") as branch_name,
                COUNT(DISTINCT ps.product_id) as product_count,
                COALESCE(SUM(ps.quantity_on_hand), 0) as quantity_on_hand,
                COALESCE(SUM(ps.available_quantity), 0) as available_quantity,
                COALESCE(SUM(ps.quantity_on_hand * COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)), 0) as stock_value
            ')
            ->groupBy('ps.branch_id', 'cb.branch_name', 'cb.branch_code')
            ->orderBy('branch_name')
            ->get();

        $batchScope = DB::table('inventory_batches')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds);
        $today = now()->toDateString();
        $expiryCutoff = now()->addDays(30)->toDateString();

        $openPurchaseOrders = DB::table('purchase_orders')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received'])
            ->count();

        $recentMovements = $this->movementQuery($company->id, $request, $branchIds)
            ->limit(12)
            ->get();
        $isHospitality = $scope['business_mode'] === 'hospitality';
        $stockAreaData = $isHospitality
            ? $stockAreaOverview->forCompany((string) $company->id, null, $branchIds)
            : null;

        $dashboard = [
            'summary' => [
                'stocked_products' => (int) ($summary->stocked_products ?? 0),
                'quantity_on_hand' => round((float) ($summary->quantity_on_hand ?? 0), 4),
                'reserved_quantity' => round((float) ($summary->reserved_quantity ?? 0), 4),
                'available_quantity' => round((float) ($summary->available_quantity ?? 0), 4),
                'stock_value' => round((float) ($summary->stock_value ?? 0), 4),
                'low_stock_products' => $lowStockProducts,
                'negative_stock_products' => (int) ($summary->negative_stock_products ?? 0),
                'warehouses' => DB::table('inventory_warehouses')
                    ->where('company_id', $company->id)
                    ->where('is_active', true)
                    ->where(function ($branchQuery) use ($branchIds) {
                        $branchQuery->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
                    })
                    ->count(),
                'open_purchase_orders' => $openPurchaseOrders,
                'open_alerts' => DB::table('inventory_alerts')
                    ->where('company_id', $company->id)
                    ->where('status', 'open')
                    ->whereIn('branch_id', $branchIds)
                    ->count(),
                'expired_batches' => (clone $batchScope)->where('quantity_on_hand', '>', 0)->whereDate('expires_at', '<', $today)->count(),
                'expiring_batches' => (clone $batchScope)->where('quantity_on_hand', '>', 0)->whereBetween('expires_at', [$today, $expiryCutoff])->count(),
            ],
            'branches' => $branchSummary,
            'recent_movements' => $recentMovements,
            'visuals' => $this->inventoryVisuals($company->id, $branchIds),
            'intelligence' => $this->inventoryIntelligence($company->id, $branchIds),
        ];

        return response()->json([
            'message' => 'Enterprise inventory dashboard loaded successfully.',
            'data' => $this->withHospitalityStockAreaData($dashboard, $isHospitality, $stockAreaData),
        ]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $locationCounts = DB::table('inventory_locations')
            ->selectRaw('warehouse_id, COUNT(*) as location_count')
            ->where('company_id', $company->id)
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
            })
            ->whereNull('deleted_at')
            ->groupBy('warehouse_id');
        $stockSummary = DB::table('inventory_location_balances as ilb')
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.company_id', '=', 'ilb.company_id')
                    ->on('pc.branch_id', '=', 'ilb.branch_id')
                    ->on('pc.product_id', '=', 'ilb.product_id');
            })
            ->where('ilb.company_id', $company->id)
            ->whereIn('ilb.branch_id', $branchIds)
            ->selectRaw('ilb.warehouse_id, COUNT(DISTINCT ilb.product_id) as product_count, COALESCE(SUM(ilb.quantity_on_hand), 0) as quantity_on_hand, COALESCE(SUM(ilb.available_quantity), 0) as available_quantity, COALESCE(SUM(ilb.quantity_on_hand * COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)), 0) as stock_value')
            ->groupBy('ilb.warehouse_id');
        $batchSummary = DB::table('inventory_batches')
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('warehouse_id, COUNT(*) as batch_count, SUM(CASE WHEN quantity_on_hand > 0 THEN 1 ELSE 0 END) as active_batch_count')
            ->groupBy('warehouse_id');

        $rows = DB::table('inventory_warehouses as w')
            ->leftJoin('company_branches as b', 'b.id', '=', 'w.branch_id')
            ->leftJoinSub($locationCounts, 'lc', fn ($join) => $join->on('lc.warehouse_id', '=', 'w.id'))
            ->leftJoinSub($stockSummary, 'ss', fn ($join) => $join->on('ss.warehouse_id', '=', 'w.id'))
            ->leftJoinSub($batchSummary, 'bs', fn ($join) => $join->on('bs.warehouse_id', '=', 'w.id'))
            ->where('w.company_id', $company->id)
            ->whereNull('w.deleted_at')
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('w.branch_id', $branchIds)->orWhereNull('w.branch_id');
            })
            ->selectRaw('w.*, COALESCE(b.branch_name, b.branch_code) as branch_name, COALESCE(lc.location_count, 0) as location_count, COALESCE(ss.product_count, 0) as product_count, COALESCE(ss.quantity_on_hand, 0) as quantity_on_hand, COALESCE(ss.available_quantity, 0) as available_quantity, COALESCE(ss.stock_value, 0) as stock_value, COALESCE(bs.batch_count, 0) as batch_count, COALESCE(bs.active_batch_count, 0) as active_batch_count')
            ->orderBy('w.name')
            ->get()
            ->map(function ($warehouse) use ($company, $branchIds) {
                $warehouse->locations = DB::table('inventory_locations')
                    ->where('company_id', $company->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where(function ($query) use ($branchIds) {
                        $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
                    })
                    ->whereNull('deleted_at')
                    ->orderBy('pick_sequence')
                    ->orderBy('name')
                    ->get();
                return $warehouse;
            });

        return response()->json(['data' => $rows]);
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $company = app('company');
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'code' => ['required', 'string', 'max:40', Rule::unique('inventory_warehouses')->withoutTrashed()->where('company_id', $company->id)],
            'name' => ['required', 'string', 'max:160'],
            'warehouse_type' => ['nullable', Rule::in(['general', 'receiving', 'dispatch', 'returns', 'production', 'cold_storage'])],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        if ($request->filled('branch_id')) {
            $this->inventoryBranchScope($request, (string) $company->id, [(string) $request->branch_id]);
        } else {
            $this->inventoryBranchScope($request, (string) $company->id);
        }

        $id = (string) Str::uuid();
        DB::table('inventory_warehouses')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'branch_id' => $request->branch_id,
            'code' => strtoupper(trim($request->code)),
            'name' => trim($request->name),
            'warehouse_type' => $request->input('warehouse_type', 'general'),
            'address' => $request->address,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Warehouse created successfully.', 'data' => DB::table('inventory_warehouses')->where('company_id', $company->id)->find($id)], 201);
    }

    public function updateWarehouse(Request $request, string $warehouseId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $warehouse = $this->scopedWarehouseQuery((string) $company->id, $branchIds)->find($warehouseId);
        if (!$warehouse) return response()->json(['message' => 'Warehouse not found for this company.'], 404);
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'warehouse_type' => ['sometimes', Rule::in(['general', 'receiving', 'dispatch', 'returns', 'production', 'cold_storage'])],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        DB::table('inventory_warehouses')->where('id', $warehouseId)->update(array_merge($validator->validated(), ['updated_at' => now()]));
        return response()->json(['message' => 'Warehouse updated successfully.']);
    }

    public function destroyWarehouse(Request $request, string $warehouseId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $warehouse = $this->scopedWarehouseQuery((string) $company->id, $branchIds)->find($warehouseId);
        if (!$warehouse) return response()->json(['message' => 'Warehouse not found for this company.'], 404);

        $hasStock = DB::table('inventory_location_balances')->where('company_id', $company->id)->where('warehouse_id', $warehouseId)
            ->where(fn ($query) => $query->where('quantity_on_hand', '!=', 0)->orWhere('reserved_quantity', '!=', 0))->exists();
        $hasOpenTransfers = DB::table('stock_transfer_headers')->where('company_id', $company->id)
            ->where(fn ($query) => $query->where('from_warehouse_id', $warehouseId)->orWhere('to_warehouse_id', $warehouseId))
            ->whereNotIn('status', ['received', 'cancelled', 'rejected'])->exists();
        if ($hasStock || $hasOpenTransfers) {
            return response()->json(['message' => 'This warehouse cannot be deleted while it has stock or an open transfer. Move or clear the stock first.'], 422);
        }

        DB::transaction(function () use ($company, $warehouseId) {
            DB::table('inventory_locations')->where('company_id', $company->id)->where('warehouse_id', $warehouseId)->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_warehouses')->where('company_id', $company->id)->where('id', $warehouseId)->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['message' => 'Warehouse deleted successfully.']);
    }

    public function warehouseProducts(Request $request, string $warehouseId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $warehouse = DB::table('inventory_warehouses as w')->leftJoin('company_branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.company_id', $company->id)->whereNull('w.deleted_at')->where('w.id', $warehouseId)
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('w.branch_id', $branchIds)->orWhereNull('w.branch_id');
            })
            ->select('w.*', 'b.branch_name')->first();
        if (!$warehouse) return response()->json(['message' => 'Warehouse not found for this company.'], 404);

        $batchStats = DB::table('inventory_batches')
            ->where('company_id', $company->id)->where('warehouse_id', $warehouseId)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('product_id, COUNT(*) as batch_count, MIN(CASE WHEN quantity_on_hand > 0 THEN expires_at END) as nearest_expiry')
            ->groupBy('product_id');
        $movementStats = DB::table('stock_movements')
            ->where('company_id', $company->id)->where('warehouse_id', $warehouseId)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('product_id, COUNT(*) as movement_count, MAX(created_at) as last_movement_at')
            ->groupBy('product_id');

        $rows = DB::table('inventory_location_balances as ilb')
            ->join('products as p', 'p.id', '=', 'ilb.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'p.supplier_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.company_id', '=', 'ilb.company_id')->on('pc.branch_id', '=', 'ilb.branch_id')->on('pc.product_id', '=', 'ilb.product_id');
            })
            ->leftJoinSub($batchStats, 'bs', fn ($join) => $join->on('bs.product_id', '=', 'ilb.product_id'))
            ->leftJoinSub($movementStats, 'ms', fn ($join) => $join->on('ms.product_id', '=', 'ilb.product_id'))
            ->where('ilb.company_id', $company->id)->where('ilb.warehouse_id', $warehouseId)
            ->whereIn('ilb.branch_id', $branchIds)
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->q);
                $query->where(fn ($sub) => $sub->where('p.name', 'like', "%{$term}%")->orWhere('p.code', 'like', "%{$term}%")->orWhere('p.sku', 'like', "%{$term}%")->orWhere('p.barcode', 'like', "%{$term}%"));
            })
            ->selectRaw('p.id as product_id, p.name as product_name, p.code, p.sku, p.barcode, p.product_type, p.stock_tracking_enabled, p.batch_tracking_enabled, p.expiry_tracking_enabled, p.reorder_level, p.minimum_stock, p.maximum_stock, p.is_active, COALESCE(cat.name, "-") as category_name, COALESCE(sup.name, "-") as supplier_name, COALESCE(u.code, u.name, "-") as uom, SUM(ilb.quantity_on_hand) as quantity_on_hand, SUM(ilb.reserved_quantity) as reserved_quantity, SUM(ilb.available_quantity) as available_quantity, MAX(COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)) as unit_cost, SUM(ilb.quantity_on_hand * COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)) as stock_value, MAX(COALESCE(bs.batch_count, 0)) as batch_count, MAX(bs.nearest_expiry) as nearest_expiry, MAX(COALESCE(ms.movement_count, 0)) as movement_count, MAX(ms.last_movement_at) as last_movement_at')
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.barcode', 'p.product_type', 'p.stock_tracking_enabled', 'p.batch_tracking_enabled', 'p.expiry_tracking_enabled', 'p.reorder_level', 'p.minimum_stock', 'p.maximum_stock', 'p.is_active', 'cat.name', 'sup.name', 'u.code', 'u.name')
            ->orderBy('p.name')->get();

        return response()->json(['data' => [
            'warehouse' => $warehouse,
            'summary' => [
                'product_count' => $rows->count(), 'quantity_on_hand' => round((float) $rows->sum('quantity_on_hand'), 4),
                'available_quantity' => round((float) $rows->sum('available_quantity'), 4), 'stock_value' => round((float) $rows->sum('stock_value'), 4),
            ],
            'products' => $rows,
        ]]);
    }

    public function reconcileBatches(Request $request, InventoryBatchSynchronizer $synchronizer): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $created = DB::transaction(fn () => $synchronizer->syncCompany($company->id, auth()->id(), $branchIds));
        return response()->json(['message' => 'Existing tracked stock was reconciled into released system-opening batches.', 'data' => ['batches_created' => $created]]);
    }

    public function storeLocation(Request $request, string $warehouseId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $warehouse = $this->scopedWarehouseQuery((string) $company->id, $branchIds)->find($warehouseId);
        if (!$warehouse) return response()->json(['message' => 'Warehouse not found for this company.'], 404);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:60', Rule::unique('inventory_locations')->withoutTrashed()->where(fn ($query) => $query->where('company_id', $company->id)->where('warehouse_id', $warehouseId))],
            'name' => ['required', 'string', 'max:160'],
            'location_type' => ['nullable', Rule::in(['receiving', 'storage', 'picking', 'dispatch', 'returns', 'quarantine', 'damaged'])],
            'pick_sequence' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);

        $id = (string) Str::uuid();
        DB::table('inventory_locations')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouseId,
            'code' => strtoupper(trim($request->code)),
            'name' => trim($request->name),
            'location_type' => $request->input('location_type', 'storage'),
            'pick_sequence' => (int) $request->input('pick_sequence', 0),
            'is_quarantine' => $request->input('location_type') === 'quarantine',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Warehouse location created successfully.', 'data' => DB::table('inventory_locations')->where('company_id', $company->id)->find($id)], 201);
    }

    public function updateLocation(Request $request, string $locationId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $location = DB::table('inventory_locations')
            ->where('company_id', $company->id)
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
            })
            ->whereNull('deleted_at')
            ->find($locationId);
        if (!$location) return response()->json(['message' => 'Location not found for this company.'], 404);
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'location_type' => ['sometimes', Rule::in(['receiving', 'storage', 'picking', 'dispatch', 'returns', 'quarantine', 'damaged'])],
            'pick_sequence' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        $data = $validator->validated();
        if (isset($data['location_type'])) $data['is_quarantine'] = $data['location_type'] === 'quarantine';
        DB::table('inventory_locations')->where('id', $locationId)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['message' => 'Location updated successfully.']);
    }

    public function recalls(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $batchTotals = DB::table('inventory_recall_batches as scope_rb')
            ->join('inventory_batches as scope_ib', 'scope_ib.id', '=', 'scope_rb.batch_id')
            ->where('scope_rb.company_id', $company->id)
            ->whereIn('scope_ib.branch_id', $branchIds)
            ->selectRaw('scope_rb.recall_id, COUNT(*) as batch_count, COALESCE(SUM(scope_rb.quantity_quarantined), 0) as quantity_quarantined')
            ->groupBy('scope_rb.recall_id');

        $rows = DB::table('inventory_recalls as r')
            ->leftJoinSub($batchTotals, 'rb', fn ($join) => $join->on('rb.recall_id', '=', 'r.id'))
            ->where('r.company_id', $company->id)
            ->whereExists(function ($query) use ($company, $branchIds) {
                $query->selectRaw('1')
                    ->from('inventory_recall_batches as allowed_rb')
                    ->join('inventory_batches as allowed_ib', 'allowed_ib.id', '=', 'allowed_rb.batch_id')
                    ->whereColumn('allowed_rb.recall_id', 'r.id')
                    ->where('allowed_rb.company_id', $company->id)
                    ->whereIn('allowed_ib.branch_id', $branchIds);
            })
            ->whereNotExists(function ($query) use ($company, $branchIds) {
                $query->selectRaw('1')
                    ->from('inventory_recall_batches as blocked_rb')
                    ->join('inventory_batches as blocked_ib', 'blocked_ib.id', '=', 'blocked_rb.batch_id')
                    ->whereColumn('blocked_rb.recall_id', 'r.id')
                    ->where('blocked_rb.company_id', $company->id)
                    ->whereNotIn('blocked_ib.branch_id', $branchIds);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('r.status', $request->status))
            ->selectRaw('r.*, COALESCE(rb.batch_count, 0) as batch_count, COALESCE(rb.quantity_quarantined, 0) as quantity_quarantined')
            ->orderByDesc('r.initiated_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));
        return response()->json(['data' => $rows]);
    }

    public function storeRecall(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:180'], 'reason' => ['required', 'string'],
            'severity' => ['nullable', Rule::in(['medium', 'high', 'critical'])],
            'batch_ids' => ['required', 'array', 'min:1'], 'batch_ids.*' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);

        $recallId = DB::transaction(function () use ($request, $company, $branchIds) {
            $batches = DB::table('inventory_batches')->where('company_id', $company->id)
                ->whereIn('branch_id', $branchIds)
                ->whereIn('id', array_unique($request->batch_ids))->lockForUpdate()->get();
            if ($batches->count() !== count(array_unique($request->batch_ids))) throw new \RuntimeException('One or more batches are not available in the active business-mode workspace.');
            $id = (string) Str::uuid();
            DB::table('inventory_recalls')->insert([
                'id' => $id, 'company_id' => $company->id,
                'recall_number' => 'RCL-' . now()->format('ymdHis') . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 4)),
                'title' => $request->title, 'reason' => $request->reason, 'severity' => $request->input('severity', 'high'),
                'status' => 'active', 'initiated_at' => now(), 'initiated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($batches as $batch) {
                $available = max(0, (float) $batch->quantity_on_hand - (float) $batch->reserved_quantity);
                DB::table('inventory_recall_batches')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id, 'recall_id' => $id, 'batch_id' => $batch->id,
                    'product_id' => $batch->product_id, 'quantity_at_recall' => $batch->quantity_on_hand,
                    'quantity_quarantined' => $available, 'status' => 'recalled', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('inventory_batches')->where('id', $batch->id)->update([
                    'quarantined_quantity' => $available, 'status' => 'recalled', 'quality_status' => 'recalled', 'updated_at' => now(),
                ]);
                $locationBalance = DB::table('inventory_location_balances')->where('company_id', $company->id)->where('batch_id', $batch->id)->lockForUpdate()->first();
                if ($locationBalance) DB::table('inventory_location_balances')->where('id', $locationBalance->id)->update(['available_quantity' => max(0, (float) $locationBalance->available_quantity - $available), 'updated_at' => now()]);
                $stockQuery = DB::table('product_stocks')->where('company_id', $company->id)->where('branch_id', $batch->branch_id)->where('product_id', $batch->product_id);
                if (DB::getSchemaBuilder()->hasColumn('product_stocks', 'batch_id')) $stockQuery->where('batch_id', $batch->id);
                $stock = $stockQuery->lockForUpdate()->first();
                if ($stock) DB::table('product_stocks')->where('id', $stock->id)->update(['available_quantity' => (float) $stock->available_quantity - $available, 'updated_at' => now()]);
            }
            return $id;
        });
        return response()->json(['message' => 'Recall activated. Selected batches are blocked from sale.', 'data' => ['id' => $recallId]], 201);
    }

    public function showRecall(Request $request, string $recallId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $recall = $this->scopedRecallQuery((string) $company->id, $branchIds)->where('r.id', $recallId)->first();
        if (!$recall) return response()->json(['message' => 'Recall not found.'], 404);
        $batches = DB::table('inventory_recall_batches as rb')->join('inventory_batches as b', 'b.id', '=', 'rb.batch_id')
            ->join('products as p', 'p.id', '=', 'rb.product_id')->where('rb.company_id', $company->id)->where('rb.recall_id', $recallId)
            ->whereIn('b.branch_id', $branchIds)
            ->select(['rb.*', 'b.lot_number', 'b.expires_at', 'p.name as product_name'])->get();
        $batchIds = $batches->pluck('batch_id');
        $affectedSales = DB::table('stock_movements as sm')->leftJoin('sales_transactions as st', 'st.id', '=', 'sm.reference_id')
            ->where('sm.company_id', $company->id)->whereIn('sm.batch_id', $batchIds)->where('sm.movement_type', 'sale')
            ->selectRaw('sm.reference_id as sale_id, st.invoice_number, st.transaction_date, sm.product_id, sm.batch_id, SUM(sm.quantity_out) as quantity_sold')
            ->groupBy('sm.reference_id', 'st.invoice_number', 'st.transaction_date', 'sm.product_id', 'sm.batch_id')->orderByDesc('st.transaction_date')->get();
        return response()->json(['data' => ['recall' => $recall, 'batches' => $batches, 'affected_sales' => $affectedSales]]);
    }

    public function closeRecall(
        Request $request,
        string $recallId,
        HospitalityMultiBranchTradingDayGuard $tradingDayGuard
    ): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $validator = Validator::make($request->all(), [
            'resolution' => ['required', Rule::in(['release', 'destroy'])], 'resolution_notes' => ['required', 'string'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);

        $scopedRecallExists = $this->scopedRecallQuery((string) $company->id, $branchIds)
            ->where('r.id', $recallId)
            ->where('r.status', 'active')
            ->exists();
        if (!$scopedRecallExists) {
            return response()->json(['message' => 'Active recall not found.'], 404);
        }

        $affectedBranchIds = DB::table('inventory_recall_batches as rb')
            ->join('inventory_batches as ib', 'ib.id', '=', 'rb.batch_id')
            ->where('rb.company_id', $company->id)
            ->where('rb.recall_id', $recallId)
            ->pluck('ib.branch_id')
            ->map(fn ($branchId) => (string) $branchId)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $tradingDaySnapshot = $tradingDayGuard->snapshot(
            (string) $company->id,
            $affectedBranchIds
        );

        DB::transaction(function () use ($request, $company, $recallId, $branchIds, $affectedBranchIds, $tradingDayGuard, $tradingDaySnapshot) {
            $tradingDayGuard->lockAndValidate($tradingDaySnapshot);

            $recall = $this->scopedRecallQuery((string) $company->id, $branchIds)
                ->where('r.id', $recallId)
                ->lockForUpdate()
                ->first();
            if (!$recall || $recall->status !== 'active') throw new \RuntimeException('Active recall not found.');
            $links = DB::table('inventory_recall_batches as rb')
                ->join('inventory_batches as ib', 'ib.id', '=', 'rb.batch_id')
                ->where('rb.company_id', $company->id)
                ->where('rb.recall_id', $recallId)
                ->whereIn('ib.branch_id', $branchIds)
                ->select(['rb.*', 'ib.branch_id as inventory_branch_id'])
                ->lockForUpdate()
                ->get();
            $currentAffectedBranchIds = $links
                ->pluck('inventory_branch_id')
                ->map(fn ($branchId) => (string) $branchId)
                ->filter()
                ->unique()
                ->sort(SORT_STRING)
                ->values()
                ->all();
            $expectedAffectedBranchIds = collect($affectedBranchIds)
                ->sort(SORT_STRING)
                ->values()
                ->all();
            if ($currentAffectedBranchIds !== $expectedAffectedBranchIds) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The recalled batch branch scope changed while this request was waiting. Refresh and retry the recall closure.',
                    'code' => 'INVENTORY_RECALL_BRANCH_SCOPE_CHANGED_RETRY',
                    'data' => [
                        'request_branch_ids' => $expectedAffectedBranchIds,
                        'current_branch_ids' => $currentAffectedBranchIds,
                    ],
                ], 409));
            }
            foreach ($links as $link) {
                $batch = DB::table('inventory_batches')->where('company_id', $company->id)->where('id', $link->batch_id)->lockForUpdate()->first();
                if (!$batch) continue;
                if ($request->resolution === 'release') {
                    DB::table('inventory_batches')->where('id', $batch->id)->update(['quarantined_quantity' => 0, 'status' => (float) $batch->quantity_on_hand > 0 ? 'available' : 'depleted', 'quality_status' => 'released', 'updated_at' => now()]);
                    $restored = (float) $link->quantity_quarantined;
                    $locationBalance = DB::table('inventory_location_balances')->where('company_id', $company->id)->where('batch_id', $batch->id)->lockForUpdate()->first();
                    if ($locationBalance) DB::table('inventory_location_balances')->where('id', $locationBalance->id)->update(['available_quantity' => (float) $locationBalance->available_quantity + $restored, 'updated_at' => now()]);
                    $stockQuery = DB::table('product_stocks')->where('company_id', $company->id)->where('branch_id', $batch->branch_id)->where('product_id', $batch->product_id);
                    if (DB::getSchemaBuilder()->hasColumn('product_stocks', 'batch_id')) $stockQuery->where('batch_id', $batch->id);
                    $stock = $stockQuery->lockForUpdate()->first();
                    if ($stock) DB::table('product_stocks')->where('id', $stock->id)->update(['available_quantity' => (float) $stock->available_quantity + $restored, 'updated_at' => now()]);
                } else {
                    $qty = (float) $batch->quantity_on_hand;
                    DB::table('inventory_batches')->where('id', $batch->id)->update(['quantity_on_hand' => 0, 'quarantined_quantity' => 0, 'status' => 'destroyed', 'quality_status' => 'rejected', 'updated_at' => now()]);
                    if ($batch->location_id) {
                        $balance = DB::table('inventory_location_balances')->where('company_id', $company->id)->where('location_id', $batch->location_id)->where('product_id', $batch->product_id)->where('batch_id', $batch->id)->lockForUpdate()->first();
                        if ($balance) DB::table('inventory_location_balances')->where('id', $balance->id)->update(['quantity_on_hand' => max(0, (float) $balance->quantity_on_hand - $qty), 'updated_at' => now()]);
                    }
                    $stockQuery = DB::table('product_stocks')->where('company_id', $company->id)->where('branch_id', $batch->branch_id)->where('product_id', $batch->product_id);
                    if (DB::getSchemaBuilder()->hasColumn('product_stocks', 'batch_id')) $stockQuery->where('batch_id', $batch->id);
                    $stock = $stockQuery->lockForUpdate()->first();
                    if ($stock) DB::table('product_stocks')->where('id', $stock->id)->update(['quantity_on_hand' => (float) $stock->quantity_on_hand - $qty, 'updated_at' => now()]);
                    $cost = DB::table('product_costs')->where('company_id', $company->id)->where('branch_id', $batch->branch_id)->where('product_id', $batch->product_id)->lockForUpdate()->first();
                    if ($cost) {
                        $newCostQty = max(0, (float) $cost->total_quantity - $qty);
                        $newCostValue = max(0, (float) $cost->total_value - ($qty * (float) $batch->base_unit_cost));
                        DB::table('product_costs')->where('id', $cost->id)->update(['total_quantity' => $newCostQty, 'total_value' => $newCostValue, 'average_cost' => $newCostQty > 0 ? $newCostValue / $newCostQty : 0, 'updated_at' => now()]);
                    }
                    if ($qty > 0) DB::table('stock_movements')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $batch->branch_id,
                        'warehouse_id' => $batch->warehouse_id, 'location_id' => $batch->location_id, 'batch_id' => $batch->id,
                        'product_id' => $batch->product_id, 'movement_type' => 'adjustment', 'quantity_in' => 0, 'quantity_out' => $qty,
                        'unit_cost' => $batch->base_unit_cost, 'total_cost' => $qty * (float) $batch->base_unit_cost,
                        'currency' => $batch->currency_code, 'currency_code' => $batch->currency_code, 'reference_type' => 'inventory_recall', 'reference_id' => $recallId,
                        'notes' => 'Recalled batch destroyed.', 'metadata' => json_encode(['resolution' => 'destroy']), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('inventory_recall_batches')->where('id', $link->id)->update(['status' => $request->resolution === 'release' ? 'released' : 'destroyed', 'updated_at' => now()]);
            }
            DB::table('inventory_recalls')->where('id', $recallId)->update(['status' => 'closed', 'resolution_notes' => $request->resolution_notes, 'closed_at' => now(), 'closed_by' => auth()->id(), 'updated_at' => now()]);
        });
        return response()->json(['message' => 'Recall closed and batch disposition recorded.']);
    }

    public function batches(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $rows = DB::table('inventory_batches as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->leftJoin('company_branches as b', 'b.id', '=', 'ib.branch_id')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'ib.warehouse_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'ib.location_id')
            ->where('ib.company_id', $company->id)
            ->whereIn('ib.branch_id', $branchIds)
            ->when($request->filled('product_id'), fn ($query) => $query->where('ib.product_id', $request->product_id))
            ->when($request->boolean('available_only'), fn ($query) => $query->where('ib.quantity_on_hand', '>', 0)->where('ib.status', 'available'))
            ->select(['ib.*', 'p.name as product_name', 'p.code as product_code', 'b.branch_name', 'w.name as warehouse_name', 'l.name as location_name'])
            ->orderByRaw('ib.expires_at IS NULL, ib.expires_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        return response()->json(['data' => $rows]);
    }

    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $batch = DB::table('inventory_batches as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('company_branches as br', 'br.id', '=', 'ib.branch_id')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'ib.warehouse_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'ib.location_id')
            ->leftJoin('purchase_receipt_lines as prl', 'prl.id', '=', 'ib.purchase_receipt_line_id')
            ->leftJoin('purchase_receipts as pr', 'pr.id', '=', 'prl.purchase_receipt_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'pr.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'prl.supplier_id')
            ->where('ib.company_id', $company->id)->whereIn('ib.branch_id', $branchIds)->where('ib.id', $batchId)
            ->selectRaw('ib.*, p.name as product_name, p.code as product_code, p.sku, p.barcode, p.product_type, p.batch_tracking_enabled, p.expiry_tracking_enabled, cat.name as category_name, COALESCE(u.code, u.name) as uom, br.branch_name, w.code as warehouse_code, w.name as warehouse_name, l.code as location_code, l.name as location_name, pr.id as receipt_id, pr.grn_number, pr.received_date, pr.status as receipt_status, pr.notes as receipt_notes, prl.quantity as receipt_line_quantity, prl.unit_cost as receipt_line_unit_cost, prl.currency_code as receipt_currency, prl.discount_amount as receipt_discount, prl.tax_amount as receipt_tax, prl.line_total as receipt_line_total, po.id as purchase_order_id, po.po_number, po.order_date, s.id as supplier_id, s.name as supplier_name')
            ->first();
        if (!$batch) return response()->json(['message' => 'Batch not found for this company.'], 404);

        $movements = DB::table('stock_movements as sm')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'sm.location_id')
            ->where('sm.company_id', $company->id)->where('sm.batch_id', $batchId)
            ->select(['sm.*', 'w.name as warehouse_name', 'l.name as location_name'])
            ->orderByDesc('sm.created_at')->get();
        $recalls = DB::table('inventory_recall_batches as rb')->join('inventory_recalls as r', 'r.id', '=', 'rb.recall_id')
            ->where('rb.company_id', $company->id)->where('rb.batch_id', $batchId)
            ->select(['rb.*', 'r.recall_number', 'r.title', 'r.severity', 'r.status as recall_status', 'r.initiated_at'])->orderByDesc('r.initiated_at')->get();
        $transfers = DB::table('inventory_transfer_allocations as a')->join('stock_transfer_headers as t', 't.id', '=', 'a.stock_transfer_id')
            ->leftJoin('inventory_warehouses as fw', 'fw.id', '=', 'a.source_warehouse_id')->leftJoin('inventory_warehouses as tw', 'tw.id', '=', 'a.destination_warehouse_id')
            ->where('a.company_id', $company->id)->where('a.batch_id', $batchId)
            ->select(['a.*', 't.transfer_no', 't.transfer_date', 't.status as transfer_status', 'fw.name as source_warehouse_name', 'tw.name as destination_warehouse_name'])
            ->orderByDesc('a.created_at')->get();
        $balance = DB::table('inventory_location_balances')->where('company_id', $company->id)->where('batch_id', $batchId)->first();

        return response()->json(['data' => compact('batch', 'movements', 'recalls', 'transfers', 'balance')]);
    }

    public function updateBatch(Request $request, string $batchId): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $batch = DB::table('inventory_batches')->where('company_id', $company->id)->whereIn('branch_id', $branchIds)->where('id', $batchId)->first();
        if (!$batch) return response()->json(['message' => 'Batch not found for this company.'], 404);

        $validator = Validator::make($request->all(), [
            'lot_number' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('inventory_batches')->where(fn ($query) => $query
                    ->where('company_id', $company->id)->where('branch_id', $batch->branch_id)
                    ->where('product_id', $batch->product_id))->ignore($batchId),
            ],
            'manufactured_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $validator->after(function ($validator) use ($request) {
            if ($request->filled('manufactured_at') && $request->filled('expires_at') && Carbon::parse($request->expires_at)->lt(Carbon::parse($request->manufactured_at))) {
                $validator->errors()->add('expires_at', 'The expiry date must be on or after the manufactured date.');
            }
        });
        if ($validator->fails()) return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);

        $data = $validator->validated();
        if (isset($data['lot_number'])) $data['lot_number'] = strtoupper(trim($data['lot_number']));
        DB::table('inventory_batches')->where('id', $batchId)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['message' => 'Batch details updated successfully.']);
    }

    public function movements(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        return response()->json(['data' => $this->movementQuery($company->id, $request, $branchIds)->paginate(min(max((int) $request->input('per_page', 50), 1), 200))]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $rows = DB::table('inventory_alerts as ia')
            ->leftJoin('products as p', 'p.id', '=', 'ia.product_id')
            ->leftJoin('company_branches as b', 'b.id', '=', 'ia.branch_id')
            ->where('ia.company_id', $company->id)
            ->whereIn('ia.branch_id', $branchIds)
            ->when($request->filled('status'), fn ($query) => $query->where('ia.status', $request->status))
            ->when($request->filled('severity'), fn ($query) => $query->where('ia.severity', $request->severity))
            ->select(['ia.*', 'p.name as product_name', 'b.branch_name'])
            ->orderByRaw("FIELD(ia.severity, 'critical', 'warning', 'info')")
            ->orderByDesc('ia.created_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        return response()->json(['data' => $rows]);
    }

    public function refreshAlerts(Request $request): JsonResponse
    {
        $company = app('company');
        $branchIds = $this->inventoryBranchScope($request, (string) $company->id)['branch_ids'];
        $now = now();
        $generated = 0;

        DB::transaction(function () use ($company, $branchIds, $now, &$generated) {
            DB::table('inventory_alerts')
                ->where('company_id', $company->id)
                ->whereIn('branch_id', $branchIds)
                ->where('status', 'open')
                ->whereIn('alert_type', ['low_stock', 'negative_stock', 'expiring_batch', 'expired_batch'])
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);

            $stockRows = DB::table('product_stocks as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->where('ps.company_id', $company->id)
                ->whereIn('ps.branch_id', $branchIds)
                ->selectRaw('ps.branch_id, ps.product_id, p.name, p.reorder_level, SUM(ps.available_quantity) as available_quantity')
                ->groupBy('ps.branch_id', 'ps.product_id', 'p.name', 'p.reorder_level')
                ->havingRaw('SUM(ps.available_quantity) <= p.reorder_level')
                ->get();

            foreach ($stockRows as $row) {
                $negative = (float) $row->available_quantity < 0;
                $this->upsertAlert($company->id, [
                    'branch_id' => $row->branch_id,
                    'product_id' => $row->product_id,
                    'fingerprint' => ($negative ? 'negative-stock:' : 'low-stock:') . $row->branch_id . ':' . $row->product_id,
                    'alert_type' => $negative ? 'negative_stock' : 'low_stock',
                    'severity' => $negative ? 'critical' : 'warning',
                    'title' => ($negative ? 'Negative stock: ' : 'Reorder required: ') . $row->name,
                    'message' => 'Available quantity is ' . round((float) $row->available_quantity, 4) . '; reorder level is ' . round((float) $row->reorder_level, 4) . '.',
                    'evidence' => json_encode(['available_quantity' => (float) $row->available_quantity, 'reorder_level' => (float) $row->reorder_level]),
                ], $now);
                $generated++;
            }

            $batches = DB::table('inventory_batches as ib')
                ->join('products as p', 'p.id', '=', 'ib.product_id')
                ->where('ib.company_id', $company->id)
                ->whereIn('ib.branch_id', $branchIds)
                ->where('ib.quantity_on_hand', '>', 0)
                ->whereNotNull('ib.expires_at')
                ->whereDate('ib.expires_at', '<=', now()->addDays(30))
                ->select(['ib.*', 'p.name as product_name'])
                ->get();

            foreach ($batches as $batch) {
                $expired = Carbon::parse($batch->expires_at)->isBefore(today());
                $this->upsertAlert($company->id, [
                    'branch_id' => $batch->branch_id,
                    'product_id' => $batch->product_id,
                    'batch_id' => $batch->id,
                    'fingerprint' => ($expired ? 'expired-batch:' : 'expiring-batch:') . $batch->id,
                    'alert_type' => $expired ? 'expired_batch' : 'expiring_batch',
                    'severity' => $expired ? 'critical' : 'warning',
                    'title' => ($expired ? 'Expired batch: ' : 'Batch expiring soon: ') . $batch->product_name,
                    'message' => 'Lot ' . $batch->lot_number . ' has ' . round((float) $batch->quantity_on_hand, 4) . ' units and expires on ' . $batch->expires_at . '.',
                    'evidence' => json_encode(['lot_number' => $batch->lot_number, 'quantity_on_hand' => (float) $batch->quantity_on_hand, 'expires_at' => $batch->expires_at]),
                ], $now);
                $generated++;
            }
        });

        return response()->json(['message' => 'Inventory alerts refreshed successfully.', 'data' => ['generated' => $generated]]);
    }

    private function movementQuery(string $companyId, Request $request, array $branchIds = [])
    {
        return DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('company_branches as b', 'b.id', '=', 'sm.branch_id')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'sm.location_id')
            ->leftJoin('inventory_batches as ib', 'ib.id', '=', 'sm.batch_id')
            ->where('sm.company_id', $companyId)
            ->when($branchIds !== [], fn ($query) => $query->whereIn('sm.branch_id', $branchIds))
            ->when($branchIds === [] && $request->filled('branch_id'), fn ($query) => $query->where('sm.branch_id', $request->branch_id))
            ->when($request->filled('product_id'), fn ($query) => $query->where('sm.product_id', $request->product_id))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('sm.warehouse_id', $request->warehouse_id))
            ->when($request->filled('movement_type'), fn ($query) => $query->where('sm.movement_type', $request->movement_type))
            ->select(['sm.*', 'p.name as product_name', 'p.code as product_code', 'b.branch_name', 'w.name as warehouse_name', 'l.name as location_name', 'ib.lot_number'])
            ->orderByDesc('sm.created_at');
    }

    private function inventoryIntelligence(string $companyId, array $branchIds): array
    {
        $usage = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('movement_type', ['sale', 'recipe_sale_consumption', 'modifier_recipe_consumption', 'production_issue', 'boh_issue_to_front', 'boh_issue_to_expense'])
            ->selectRaw('company_id, branch_id, product_id, SUM(quantity_out) / 30 as average_daily_usage')
            ->groupBy('company_id', 'branch_id', 'product_id');

        return DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoinSub($usage, 'usage30', function ($join) {
                $join->on('usage30.company_id', '=', 'ps.company_id')
                    ->on('usage30.branch_id', '=', 'ps.branch_id')
                    ->on('usage30.product_id', '=', 'ps.product_id');
            })
            ->leftJoin('company_branches as b', 'b.id', '=', 'ps.branch_id')
            ->where('ps.company_id', $companyId)
            ->whereIn('ps.branch_id', $branchIds)
            ->selectRaw('
                ps.branch_id,
                COALESCE(b.branch_name, b.branch_code) as branch_name,
                ps.product_id,
                p.name as product_name,
                p.reorder_level,
                SUM(ps.available_quantity) as available_quantity,
                MAX(COALESCE(usage30.average_daily_usage, 0)) as average_daily_usage
            ')
            ->groupBy('ps.branch_id', 'b.branch_name', 'b.branch_code', 'ps.product_id', 'p.name', 'p.reorder_level')
            ->get()
            ->map(function ($row) {
                $available = (float) $row->available_quantity;
                $daily = (float) $row->average_daily_usage;
                $daysCover = $daily > 0 ? max($available, 0) / $daily : null;
                $recommended = max((float) $row->reorder_level * 2 - $available, 0);
                return [
                    'branch_id' => $row->branch_id,
                    'branch_name' => $row->branch_name,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'available_quantity' => round($available, 4),
                    'average_daily_usage_30d' => round($daily, 4),
                    'estimated_days_cover' => $daysCover === null ? null : round($daysCover, 1),
                    'recommended_order_quantity' => round($recommended, 4),
                    'risk' => $available < 0 ? 'critical' : (($daysCover !== null && $daysCover <= 7) || $available <= (float) $row->reorder_level ? 'high' : 'normal'),
                    'confidence' => $daily > 0 ? 'evidence_based_30_day_velocity' : 'insufficient_movement_history',
                ];
            })
            ->sortBy(fn ($row) => ['critical' => 0, 'high' => 1, 'normal' => 2][$row['risk']])
            ->take(25)
            ->values()
            ->all();
    }

    private function inventoryVisuals(string $companyId, array $branchIds): array
    {
        $status = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.company_id', $companyId)
            ->whereIn('ps.branch_id', $branchIds)
            ->selectRaw("CASE
                WHEN ps.available_quantity < 0 THEN 'negative'
                WHEN ps.available_quantity <= p.reorder_level THEN 'low'
                WHEN p.maximum_stock > 0 AND ps.available_quantity > p.maximum_stock THEN 'overstock'
                ELSE 'healthy' END as stock_status, COUNT(DISTINCT ps.product_id) as product_count")
            ->groupBy('stock_status')->get();

        $movementTrend = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->whereDate('created_at', '>=', now()->subDays(13)->toDateString())
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('DATE(created_at) as movement_date, SUM(quantity_in) as quantity_in, SUM(quantity_out) as quantity_out, COUNT(*) as movement_count')
            ->groupByRaw('DATE(created_at)')->orderBy('movement_date')->get();

        $categoryValue = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.company_id', '=', 'ps.company_id')->on('pc.branch_id', '=', 'ps.branch_id')->on('pc.product_id', '=', 'ps.product_id');
            })
            ->where('ps.company_id', $companyId)
            ->whereIn('ps.branch_id', $branchIds)
            ->selectRaw("COALESCE(c.name, 'Uncategorised') as category_name, SUM(ps.quantity_on_hand * COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)) as stock_value")
            ->groupBy('c.id', 'c.name')->orderByDesc('stock_value')->limit(10)->get();

        $receipts = DB::table('purchase_receipts')
            ->where('company_id', $companyId)->where('status', 'posted')
            ->whereDate('received_date', '>=', now()->subDays(13)->toDateString())
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('received_date, COUNT(*) as voucher_count, SUM(grand_total) as received_value')
            ->groupBy('received_date')->orderBy('received_date')->get();

        return [
            'stock_health' => $status,
            'movement_trend_14d' => $movementTrend,
            'stock_value_by_category' => $categoryValue,
            'goods_received_14d' => $receipts,
        ];
    }

    private function upsertAlert(string $companyId, array $data, Carbon $now): void
    {
        $existingId = DB::table('inventory_alerts')
            ->where('company_id', $companyId)
            ->where('fingerprint', $data['fingerprint'])
            ->value('id');

        DB::table('inventory_alerts')->updateOrInsert(
            ['company_id' => $companyId, 'fingerprint' => $data['fingerprint']],
            array_merge($data, [
                'id' => $existingId ?: (string) Str::uuid(),
                'company_id' => $companyId,
                'status' => 'open',
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );
    }

    /**
     * @param  array<int, string>  $requestedBranchIds
     * @return array{business_mode: string, context_branch_id: string, branch_ids: array<int, string>, accessible_branch_ids: array<int, string>}
     */
    private function inventoryBranchScope(
        Request $request,
        string $companyId,
        array $requestedBranchIds = []
    ): array {
        if ($requestedBranchIds === [] && $request->filled('branch_id')) {
            $requestedBranchIds = [(string) $request->input('branch_id')];
        }

        return app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            $companyId,
            $requestedBranchIds
        );
    }

    /** @param array<int, string> $branchIds */
    private function scopedWarehouseQuery(string $companyId, array $branchIds)
    {
        return DB::table('inventory_warehouses')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
            })
            ->whereNull('deleted_at');
    }

    /** @param array<int, string> $branchIds */
    private function scopedRecallQuery(string $companyId, array $branchIds)
    {
        return DB::table('inventory_recalls as r')
            ->where('r.company_id', $companyId)
            ->whereExists(function ($query) use ($companyId, $branchIds) {
                $query->selectRaw('1')
                    ->from('inventory_recall_batches as allowed_rb')
                    ->join('inventory_batches as allowed_ib', 'allowed_ib.id', '=', 'allowed_rb.batch_id')
                    ->whereColumn('allowed_rb.recall_id', 'r.id')
                    ->where('allowed_rb.company_id', $companyId)
                    ->whereIn('allowed_ib.branch_id', $branchIds);
            })
            ->whereNotExists(function ($query) use ($companyId, $branchIds) {
                $query->selectRaw('1')
                    ->from('inventory_recall_batches as blocked_rb')
                    ->join('inventory_batches as blocked_ib', 'blocked_ib.id', '=', 'blocked_rb.batch_id')
                    ->whereColumn('blocked_rb.recall_id', 'r.id')
                    ->where('blocked_rb.company_id', $companyId)
                    ->whereNotIn('blocked_ib.branch_id', $branchIds);
            });
    }

    private function dashboardBranchScope(
        Request $request,
        string $companyId,
        BusinessModeBranchScopeService $branchScope
    ): array {
        $requestedBranchIds = $request->filled('branch_id')
            ? [$request->input('branch_id')]
            : [];

        return $branchScope->resolve($request, $companyId, $requestedBranchIds);
    }

    private function withHospitalityStockAreaData(
        array $dashboard,
        bool $isHospitality,
        ?array $stockAreaData
    ): array {
        if (!$isHospitality || !$stockAreaData) {
            return $dashboard;
        }

        $dashboard['summary'] = [
            ...($dashboard['summary'] ?? []),
            ...($stockAreaData['summary'] ?? []),
        ];
        $dashboard['stock_areas'] = $stockAreaData['stock_areas'] ?? [];
        $dashboard['non_turnover_stock'] = $stockAreaData['non_turnover_stock'] ?? [];

        return $dashboard;
    }
}
