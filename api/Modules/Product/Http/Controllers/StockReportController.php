<?php

namespace Modules\Product\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;




class StockReportController extends Controller
{
    public function dashboard(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        return response()->json([
            'message' => 'Stock dashboard fetched successfully.',
            'data' => [
                'summary' => $this->dashboardSummary($company->id, $filters),
                'stock_by_branch' => $this->stockByBranch($company->id, $filters)->get(),
                'stock_by_category' => $this->stockByCategory($company->id, $filters)->limit(15)->get(),
                'low_stock' => $this->lowStockBase($company->id, $filters)->limit(15)->get(),
                'negative_stock' => $this->negativeStockBase($company->id, $filters)->limit(15)->get(),
                'recent_movements' => $this->movementQuery($company->id, $filters)->limit(25)->get(),
            ],
        ]);
    }

    public function companyBalance(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $rows = $this->currentStockBase($company->id, $filters)
            ->selectRaw('
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                CASE
                    WHEN SUM(product_stocks.quantity_on_hand) = 0 THEN 0
                    ELSE SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ')
                        / SUM(product_stocks.quantity_on_hand)
                END as average_cost,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy(
                'product_stocks.company_id',
                'product_stocks.product_id',
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                'pc.name',
                'sup.name',
                DB::raw($this->costCurrencyExpression())
            )
            ->orderBy('products.name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Company stock balance fetched successfully.',
            'summary' => $this->currentStockTotals($company->id, $filters),
            'data' => $rows,
        ]);
    }

    public function branchBalance(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $rows = $this->currentStockBase($company->id, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'product_stocks.branch_id')
            ->selectRaw('
                product_stocks.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                CASE
                    WHEN SUM(product_stocks.quantity_on_hand) = 0 THEN 0
                    ELSE SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ')
                        / SUM(product_stocks.quantity_on_hand)
                END as average_cost,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy(
                'product_stocks.company_id',
                'product_stocks.product_id',
                'product_stocks.branch_id',
                DB::raw($this->branchNameExpression()),
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                'pc.name',
                'sup.name',
                DB::raw($this->costCurrencyExpression())
            )
            ->orderBy('branch_name')
            ->orderBy('products.name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Branch stock balance fetched successfully.',
            'summary' => $this->currentStockTotals($company->id, $filters),
            'data' => $rows,
        ]);
    }

    public function balance(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $rows = $this->movementBalanceBase($company->id, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'stock_movements.branch_id')
            ->selectRaw('
                stock_movements.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                SUM(stock_movements.quantity_in) as total_in,
                SUM(stock_movements.quantity_out) as total_out,
                SUM(stock_movements.quantity_in - stock_movements.quantity_out) as quantity_on_hand,
                MAX(stock_movements.created_at) as last_movement_at,
                CASE
                    WHEN SUM(stock_movements.quantity_in - stock_movements.quantity_out) = 0 THEN 0
                    ELSE SUM((stock_movements.quantity_in - stock_movements.quantity_out) * ' . $this->costColumn('average_cost') . ')
                        / SUM(stock_movements.quantity_in - stock_movements.quantity_out)
                END as average_cost,
                SUM((stock_movements.quantity_in - stock_movements.quantity_out) * ' . $this->costColumn('average_cost') . ') as stock_value,
                ' . $this->costCurrencyExpression() . ' as currency_code,
                ' . $this->costCurrencyExpression() . ' as currency
            ')
            ->groupBy(
                'stock_movements.company_id',
                'stock_movements.product_id',
                'stock_movements.branch_id',
                DB::raw($this->branchNameExpression()),
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                'pc.name',
                'sup.name',
                DB::raw($this->costCurrencyExpression())
            )
            ->orderBy('products.name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Stock balance fetched successfully.',
            'summary' => $this->movementStockTotals($company->id, $filters),
            'data' => $rows,
        ]);
    }

    public function asOfBalance(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $asOf = $request->as_of ?: now()->toDateString();
        $filters['date_from'] = null;
        $filters['date_to'] = $asOf;

        $rows = $this->movementBalanceBase($company->id, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'stock_movements.branch_id')
            ->selectRaw('
                stock_movements.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                SUM(stock_movements.quantity_in - stock_movements.quantity_out) as quantity_as_of,
                MAX(stock_movements.created_at) as last_movement_at,
                CASE
                    WHEN SUM(stock_movements.quantity_in - stock_movements.quantity_out) = 0 THEN 0
                    ELSE SUM((stock_movements.quantity_in - stock_movements.quantity_out) * ' . $this->costColumn('average_cost') . ')
                        / SUM(stock_movements.quantity_in - stock_movements.quantity_out)
                END as average_cost,
                SUM((stock_movements.quantity_in - stock_movements.quantity_out) * ' . $this->costColumn('average_cost') . ') as stock_value,
                ' . $this->costCurrencyExpression() . ' as currency_code,
                ' . $this->costCurrencyExpression() . ' as currency,
                ? as as_of_date
            ', [$asOf])
            ->groupBy(
                'stock_movements.company_id',
                'stock_movements.product_id',
                'stock_movements.branch_id',
                DB::raw($this->branchNameExpression()),
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                'pc.name',
                'sup.name',
                DB::raw($this->costCurrencyExpression())
            )
            ->orderBy('products.name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Stock balance as-of fetched successfully.',
            'summary' => $this->movementStockTotals($company->id, $filters),
            'data' => $rows,
        ]);
    }

    public function movements(Request $request)
    {
        $company = app('company');

        return response()->json([
            'message' => 'Stock movements fetched successfully.',
            'data' => $this->movementQuery($company->id, $this->filters($request))
                ->paginate($request->per_page ?? 50),
        ]);
    }

    public function productLedger(Request $request, string $productId)
    {
        $company = app('company');
        $filters = $this->filters($request);
        $filters['product_id'] = $productId;

        return response()->json([
            'message' => 'Product stock ledger fetched successfully.',
            'data' => $this->movementQuery($company->id, $filters)
                ->orderBy('stock_movements.created_at')
                ->paginate($request->per_page ?? 100),
        ]);
    }

    public function received(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $query = $this->movementQuery($company->id, $filters)
            ->where('stock_movements.quantity_in', '>', 0)
            ->whereIn('stock_movements.movement_type', [
                'receive',
                'purchase',
                'opening',
                'adjustment_in',
                'stock_take_gain',
                'break_child_in',
                'return',
                'sale_void',
            ]);

        return response()->json([
            'message' => 'Stock received tracking fetched successfully.',
            'summary' => $this->stockReceivedTotals($company->id, $filters),
            'data' => $query->paginate($request->per_page ?? 50),
        ]);
    }

    public function adjustments(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $query = $this->movementQuery($company->id, $filters)
            ->whereIn('stock_movements.movement_type', [
                'adjustment',
                'adjustment_in',
                'adjustment_out',
                'stock_take_gain',
                'stock_take_loss',
                'wastage',
            ])
            ->addSelect(DB::raw('(
                CASE
                    WHEN stock_movements.quantity_in > stock_movements.quantity_out THEN stock_movements.quantity_in
                    ELSE stock_movements.quantity_out
                END * ' . $this->costColumn('average_cost') . '
            ) as cost_value'));

        return response()->json([
            'message' => 'Stock adjustment tracking fetched successfully.',
            'summary' => $this->adjustmentTotals($company->id, $filters),
            'data' => $query->paginate($request->per_page ?? 50),
        ]);
    }

    public function valuation(Request $request)
    {
        $company = app('company');
        $filters = $this->filters($request);

        $rows = $this->currentStockBase($company->id, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'product_stocks.branch_id')
            ->selectRaw('
                product_stocks.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                product_stocks.quantity_on_hand,
                product_stocks.reserved_quantity,
                product_stocks.available_quantity,
                ' . $this->costColumn('average_cost') . ' as average_cost,
                product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ' as stock_value,
                ' . $this->costCurrencyExpression() . ' as currency_code,
                ' . $this->costCurrencyExpression() . ' as currency
            ')
            ->orderByDesc('stock_value')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Stock valuation fetched successfully.',
            'summary' => $this->currentStockTotals($company->id, $filters),
            'data' => $rows,
        ]);
    }

    public function lowStock(Request $request)
    {
        $company = app('company');

        return response()->json([
            'message' => 'Low stock report fetched successfully.',
            'data' => $this->lowStockBase($company->id, $this->filters($request))
                ->paginate($request->per_page ?? 50),
        ]);
    }

    public function outOfStock(Request $request)
    {
        $company = app('company');

        return response()->json([
            'message' => 'Out of stock report fetched successfully.',
            'data' => $this->currentStockRows($company->id, $this->filters($request))
                ->havingRaw('quantity_on_hand <= 0')
                ->orderBy('products.name')
                ->paginate($request->per_page ?? 50),
        ]);
    }

    public function negativeStock(Request $request)
    {
        $company = app('company');

        return response()->json([
            'message' => 'Negative stock report fetched successfully.',
            'data' => $this->negativeStockBase($company->id, $this->filters($request))
                ->paginate($request->per_page ?? 50),
        ]);
    }

    public function categorySummary(Request $request)
    {
        $company = app('company');

        return response()->json([
            'message' => 'Stock category summary fetched successfully.',
            'data' => $this->stockByCategory($company->id, $this->filters($request))
                ->paginate($request->per_page ?? 50),
        ]);
    }

    public function supplierSummary(Request $request)
    {
        $company = app('company');

        $rows = $this->currentStockBase($company->id, $this->filters($request))
            ->selectRaw('
                sup.id as supplier_id,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                COUNT(DISTINCT products.id) as product_count,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy('sup.id', 'sup.name', DB::raw($this->costCurrencyExpression()))
            ->orderBy('supplier_name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'message' => 'Stock supplier summary fetched successfully.',
            'data' => $rows,
        ]);
    }

    private function filters(Request $request): array
    {
        $branchIds = $request->input('branch_ids', []);
        if (is_string($branchIds)) {
            $branchIds = array_filter(array_map('trim', explode(',', $branchIds)));
        }
        if (!is_array($branchIds)) {
            $branchIds = [];
        }

        $branchId = trim((string) $request->input('branch_id', '')) ?: null;
        if ($branchId && $branchIds === []) {
            $branchIds = [$branchId];
        }

        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) app('company')->id,
            $branchIds
        );

        return [
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'branch_id' => $branchId,
            'branch_ids' => $scope['branch_ids'],
            'mode_context_branch_id' => $scope['context_branch_id'],
            'business_mode' => $scope['business_mode'],
            'warehouse_id' => $request->warehouse_id,
            'category_id' => $request->category_id,
            'supplier_id' => $request->supplier_id,
            'product_id' => $request->product_id,
            'movement_type' => $request->movement_type,
            'q' => $request->q,
        ];
    }

    private function currentStockBase(string $companyId, array $filters)
    {
        if ($filters['warehouse_id']) {
            $warehouseStock = DB::table('inventory_location_balances')
                ->selectRaw('
                    company_id,
                    branch_id,
                    product_id,
                    SUM(quantity_on_hand) as quantity_on_hand,
                    SUM(reserved_quantity) as reserved_quantity,
                    SUM(available_quantity) as available_quantity
                ')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $filters['warehouse_id'])
                ->groupBy('company_id', 'branch_id', 'product_id');

            $query = DB::query()->fromSub($warehouseStock, 'product_stocks');
        } else {
            $query = DB::table('product_stocks');
        }

        $query
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->where('product_stocks.company_id', $companyId)
            ->whereIn('product_stocks.branch_id', $filters['branch_ids'])
            ->when($filters['category_id'], fn ($q) => $q->where('products.category_id', $filters['category_id']))
            ->when($filters['supplier_id'], fn ($q) => $q->where('products.supplier_id', $filters['supplier_id']))
            ->when($filters['product_id'], fn ($q) => $q->where('product_stocks.product_id', $filters['product_id']))
            ->when($filters['q'], function ($q) use ($filters) {
                $term = $filters['q'];

                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', "%{$term}%")
                        ->orWhere('products.sku', 'like', "%{$term}%")
                        ->orWhere('products.code', 'like', "%{$term}%")
                        ->orWhere('products.barcode', 'like', "%{$term}%");
                });
            });

        $this->joinProductCosts($query, 'product_stocks');

        return $query;
    }

    private function currentStockRows(string $companyId, array $filters)
    {
        return $this->currentStockBase($companyId, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'product_stocks.branch_id')
            ->selectRaw('
                product_stocks.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                products.id as product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                ' . $this->reorderLevelExpression() . ' as reorder_level,
                CASE
                    WHEN SUM(product_stocks.quantity_on_hand) = 0 THEN 0
                    ELSE SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ')
                        / SUM(product_stocks.quantity_on_hand)
                END as average_cost,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy(
                'product_stocks.company_id',
                'product_stocks.product_id',
                'product_stocks.branch_id',
                DB::raw($this->branchNameExpression()),
                'products.id',
                'products.name',
                'products.code',
                'products.sku',
                'products.barcode',
                'pc.name',
                'sup.name',
                DB::raw($this->reorderLevelExpression()),
                DB::raw($this->costCurrencyExpression())
            );
    }

    private function movementBalanceBase(string $companyId, array $filters)
    {
        $query = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->where('stock_movements.company_id', $companyId)
            ->whereIn('stock_movements.branch_id', $filters['branch_ids'])
            ->when($filters['warehouse_id'], fn ($q) => $q->where('stock_movements.warehouse_id', $filters['warehouse_id']))
            ->when($filters['category_id'], fn ($q) => $q->where('products.category_id', $filters['category_id']))
            ->when($filters['supplier_id'], fn ($q) => $q->where('products.supplier_id', $filters['supplier_id']))
            ->when($filters['product_id'], fn ($q) => $q->where('stock_movements.product_id', $filters['product_id']))
            ->when($filters['movement_type'], fn ($q) => $q->where('stock_movements.movement_type', $filters['movement_type']))
            ->when($filters['date_from'], fn ($q) => $q->whereDate('stock_movements.created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'], fn ($q) => $q->whereDate('stock_movements.created_at', '<=', $filters['date_to']))
            ->when($filters['q'], function ($q) use ($filters) {
                $term = $filters['q'];

                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', "%{$term}%")
                        ->orWhere('products.sku', 'like', "%{$term}%")
                        ->orWhere('products.code', 'like', "%{$term}%")
                        ->orWhere('products.barcode', 'like', "%{$term}%")
                        ->orWhere('stock_movements.movement_type', 'like', "%{$term}%")
                        ->orWhere('stock_movements.notes', 'like', "%{$term}%");
                });
            });

        $this->joinProductCosts($query, 'stock_movements');

        return $query;
    }

    private function movementQuery(string $companyId, array $filters)
    {
        $query = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'stock_movements.branch_id')
            ->leftJoin('inventory_warehouses as iw', 'iw.id', '=', 'stock_movements.warehouse_id')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'stock_movements.created_by')
            ->where('stock_movements.company_id', $companyId)
            ->whereIn('stock_movements.branch_id', $filters['branch_ids'])
            ->when($filters['warehouse_id'], fn ($q) => $q->where('stock_movements.warehouse_id', $filters['warehouse_id']))
            ->when($filters['category_id'], fn ($q) => $q->where('products.category_id', $filters['category_id']))
            ->when($filters['supplier_id'], fn ($q) => $q->where('products.supplier_id', $filters['supplier_id']))
            ->when($filters['product_id'], fn ($q) => $q->where('stock_movements.product_id', $filters['product_id']))
            ->when($filters['movement_type'], fn ($q) => $q->where('stock_movements.movement_type', $filters['movement_type']))
            ->when($filters['date_from'], fn ($q) => $q->whereDate('stock_movements.created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'], fn ($q) => $q->whereDate('stock_movements.created_at', '<=', $filters['date_to']))
            ->when($filters['q'], function ($q) use ($filters) {
                $term = $filters['q'];

                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', "%{$term}%")
                        ->orWhere('products.sku', 'like', "%{$term}%")
                        ->orWhere('products.code', 'like', "%{$term}%")
                        ->orWhere('products.barcode', 'like', "%{$term}%")
                        ->orWhere('stock_movements.movement_type', 'like', "%{$term}%")
                        ->orWhere('stock_movements.notes', 'like', "%{$term}%");
                });
            });

        $this->joinProductCosts($query, 'stock_movements');

        return $query
            ->selectRaw('
                stock_movements.id,
                stock_movements.company_id,
                stock_movements.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                stock_movements.warehouse_id,
                COALESCE(iw.name, "Unassigned") as warehouse_name,
                stock_movements.till_id,
                stock_movements.shift_session_id,
                stock_movements.product_id,
                products.name as product_name,
                products.code,
                products.sku,
                products.barcode,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COALESCE(sup.name, "No Supplier") as supplier_name,
                stock_movements.movement_type,
                stock_movements.quantity_in,
                stock_movements.quantity_out,
                stock_movements.balance_after,
                COALESCE(stock_movements.unit_cost, ' . $this->costColumn('average_cost') . ', 0) as unit_cost,
                COALESCE(stock_movements.total_cost, 0) as total_cost,
                COALESCE(stock_movements.currency_code, stock_movements.currency, ' . $this->costCurrencyExpression() . ') as currency_code,
                COALESCE(stock_movements.currency, stock_movements.currency_code, ' . $this->costCurrencyExpression() . ') as currency,
                ' . $this->costColumn('average_cost') . ' as average_cost,
                ' . $this->costCurrencyExpression() . ' as cost_currency_code,
                stock_movements.quantity_in as increased_quantity,
                stock_movements.quantity_out as decreased_quantity,
                stock_movements.reference_type,
                stock_movements.reference_id,
                stock_movements.notes,
                stock_movements.metadata,
                stock_movements.created_by,
                COALESCE(pos_users.name, pos_users.username, "Unknown") as created_by_name,
                stock_movements.created_at as movement_date
            ')
            ->orderByDesc('stock_movements.created_at');
    }

    private function lowStockBase(string $companyId, array $filters)
    {
        return $this->currentStockRows($companyId, $filters)
            ->havingRaw('quantity_on_hand > 0')
            ->havingRaw('quantity_on_hand <= reorder_level')
            ->orderBy('quantity_on_hand');
    }

    private function negativeStockBase(string $companyId, array $filters)
    {
        return $this->currentStockRows($companyId, $filters)
            ->havingRaw('quantity_on_hand < 0')
            ->orderBy('quantity_on_hand');
    }

    private function stockByBranch(string $companyId, array $filters)
    {
        return $this->currentStockBase($companyId, $filters)
            ->leftJoin($this->branchTable() . ' as br', 'br.id', '=', 'product_stocks.branch_id')
            ->selectRaw('
                product_stocks.branch_id,
                ' . $this->branchNameExpression() . ' as branch_name,
                COUNT(DISTINCT products.id) as product_count,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy(
                'product_stocks.branch_id',
                DB::raw($this->branchNameExpression()),
                DB::raw($this->costCurrencyExpression())
            )
            ->orderBy('branch_name');
    }

    private function stockByCategory(string $companyId, array $filters)
    {
        return $this->currentStockBase($companyId, $filters)
            ->selectRaw('
                pc.id as category_id,
                COALESCE(pc.name, "Uncategorised") as category_name,
                COUNT(DISTINCT products.id) as product_count,
                SUM(product_stocks.quantity_on_hand) as quantity_on_hand,
                SUM(product_stocks.reserved_quantity) as reserved_quantity,
                SUM(product_stocks.available_quantity) as available_quantity,
                SUM(product_stocks.quantity_on_hand * ' . $this->costColumn('average_cost') . ') as stock_value,
                MAX(' . $this->costCurrencyExpression() . ') as currency_code,
                MAX(' . $this->costCurrencyExpression() . ') as currency
            ')
            ->groupBy('pc.id', 'pc.name', DB::raw($this->costCurrencyExpression()))
            ->orderByDesc('stock_value');
    }

    private function dashboardSummary(string $companyId, array $filters): array
    {
        $stock = $this->currentStockTotals($companyId, $filters);
        $movement = $this->movementBalanceBase($companyId, $filters);

        return array_merge($stock, [
            'movement_count' => (clone $movement)->count(),
            'received_count' => (clone $movement)->whereIn('stock_movements.movement_type', ['receive', 'purchase', 'opening'])->count(),
            'sales_issue_count' => (clone $movement)->where('stock_movements.movement_type', 'sale')->count(),
            'adjustment_count' => (clone $movement)->whereIn('stock_movements.movement_type', ['adjustment', 'adjustment_in', 'adjustment_out'])->count(),
            'break_count' => (clone $movement)->whereIn('stock_movements.movement_type', ['break_parent', 'create_child', 'break_parent_out', 'break_child_in'])->count(),
        ]);
    }

    private function joinProductCosts($query, string $stockAlias): void
    {
        if (!Schema::hasTable('product_costs')) {
            return;
        }

        $query->leftJoin('product_costs as costs', function ($join) use ($stockAlias) {
            $join->on('costs.product_id', '=', $stockAlias . '.product_id');

            if (Schema::hasColumn('product_costs', 'company_id')) {
                $join->on('costs.company_id', '=', $stockAlias . '.company_id');
            }

            if (Schema::hasColumn('product_costs', 'branch_id')) {
                $join->on('costs.branch_id', '=', $stockAlias . '.branch_id');
            }
        });
    }

    private function branchTable(): string
    {
        if (Schema::hasTable('company_branches')) {
            return 'company_branches';
        }

        return 'branches';
    }

    private function branchNameExpression(): string
    {
        $table = $this->branchTable();
        $columns = [];

        if (Schema::hasColumn($table, 'branch_name')) {
            $columns[] = 'br.branch_name';
        }

        if (Schema::hasColumn($table, 'name')) {
            $columns[] = 'br.name';
        }

        if (Schema::hasColumn($table, 'branch_code')) {
            $columns[] = 'br.branch_code';
        }

        if (Schema::hasColumn($table, 'code')) {
            $columns[] = 'br.code';
        }

        $columns = array_values(array_unique($columns));

        if (count($columns) === 0) {
            return '"Unknown Branch"';
        }

        return 'COALESCE(' . implode(', ', $columns) . ', "Unknown Branch")';
    }

    /**
     * Deprecated helper kept only so old calls do not break.
     * For grouped queries use DB::raw($this->branchNameExpression()).
     */
    private function branchGroupColumn(): string
    {
        return $this->branchNameExpression();
    }

    private function costColumn(string $preferred): string
    {
        if (!Schema::hasTable('product_costs')) {
            return '0';
        }

        if (Schema::hasColumn('product_costs', $preferred)) {
            return 'COALESCE(costs.' . $preferred . ', 0)';
        }

        foreach (['average_cost', 'last_cost', 'unit_cost'] as $legacyColumn) {
            if (Schema::hasColumn('product_costs', $legacyColumn)) {
                return 'COALESCE(costs.' . $legacyColumn . ', 0)';
            }
        }

        return '0';
    }

    private function costCurrencyExpression(): string
    {
        if (!Schema::hasTable('product_costs')) {
            return 'NULL';
        }

        if (
            Schema::hasColumn('product_costs', 'currency_code') &&
            Schema::hasColumn('product_costs', 'currency')
        ) {
            return 'COALESCE(costs.currency_code, costs.currency)';
        }

        if (Schema::hasColumn('product_costs', 'currency_code')) {
            return 'costs.currency_code';
        }

        if (Schema::hasColumn('product_costs', 'currency')) {
            return 'costs.currency';
        }

        return 'NULL';
    }


    private function currentStockTotals(string $companyId, array $filters): array
    {
        $base = $this->currentStockBase($companyId, $filters)
            ->selectRaw('
                product_stocks.company_id,
                product_stocks.branch_id,
                product_stocks.product_id,
                product_stocks.quantity_on_hand,
                product_stocks.reserved_quantity,
                product_stocks.available_quantity,
                ' . $this->costColumn('average_cost') . ' as average_cost,
                UPPER(NULLIF(TRIM(' . $this->costCurrencyExpression() . '), "")) as currency_code
            ');

        $row = DB::query()
            ->fromSub(clone $base, 'stock_total_rows')
            ->selectRaw('
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_on_hand) as quantity_on_hand,
                SUM(reserved_quantity) as reserved_quantity,
                SUM(available_quantity) as available_quantity
            ')
            ->first();

        $currencyTotals = DB::query()
            ->fromSub(clone $base, 'stock_currency_rows')
            ->selectRaw('
                currency_code,
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_on_hand) as quantity_on_hand,
                SUM(reserved_quantity) as reserved_quantity,
                SUM(available_quantity) as available_quantity,
                SUM(quantity_on_hand * average_cost) as stock_value
            ')
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($currencyRow) => [
                'currency_code' => $this->normaliseCurrencyCode($currencyRow->currency_code ?? null),
                'product_count' => (int) ($currencyRow->product_count ?? 0),
                'quantity_on_hand' => round((float) ($currencyRow->quantity_on_hand ?? 0), 4),
                'reserved_quantity' => round((float) ($currencyRow->reserved_quantity ?? 0), 4),
                'available_quantity' => round((float) ($currencyRow->available_quantity ?? 0), 4),
                'stock_value' => round((float) ($currencyRow->stock_value ?? 0), 4),
            ])
            ->values()
            ->all();

        return array_merge([
            'product_count' => (int) ($row->product_count ?? 0),
            'quantity_on_hand' => round((float) ($row->quantity_on_hand ?? 0), 4),
            'reserved_quantity' => round((float) ($row->reserved_quantity ?? 0), 4),
            'available_quantity' => round((float) ($row->available_quantity ?? 0), 4),
            'currency_totals' => $currencyTotals,
        ], $this->singleCurrencyScalars($currencyTotals, ['stock_value']));
    }

    private function movementStockTotals(string $companyId, array $filters): array
    {
        $base = $this->movementBalanceBase($companyId, $filters)
            ->selectRaw('
                stock_movements.company_id,
                stock_movements.branch_id,
                stock_movements.product_id,
                stock_movements.quantity_in,
                stock_movements.quantity_out,
                ' . $this->costColumn('average_cost') . ' as average_cost,
                UPPER(NULLIF(TRIM(' . $this->costCurrencyExpression() . '), "")) as currency_code
            ');

        $row = DB::query()
            ->fromSub(clone $base, 'movement_total_rows')
            ->selectRaw('
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_in) as total_in,
                SUM(quantity_out) as total_out,
                SUM(quantity_in - quantity_out) as quantity_on_hand
            ')
            ->first();

        $currencyTotals = DB::query()
            ->fromSub(clone $base, 'movement_currency_rows')
            ->selectRaw('
                currency_code,
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_in) as total_in,
                SUM(quantity_out) as total_out,
                SUM(quantity_in - quantity_out) as quantity_on_hand,
                SUM((quantity_in - quantity_out) * average_cost) as stock_value
            ')
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($currencyRow) => [
                'currency_code' => $this->normaliseCurrencyCode($currencyRow->currency_code ?? null),
                'product_count' => (int) ($currencyRow->product_count ?? 0),
                'total_in' => round((float) ($currencyRow->total_in ?? 0), 4),
                'total_out' => round((float) ($currencyRow->total_out ?? 0), 4),
                'quantity_on_hand' => round((float) ($currencyRow->quantity_on_hand ?? 0), 4),
                'stock_value' => round((float) ($currencyRow->stock_value ?? 0), 4),
            ])
            ->values()
            ->all();

        return array_merge([
            'product_count' => (int) ($row->product_count ?? 0),
            'total_in' => round((float) ($row->total_in ?? 0), 4),
            'total_out' => round((float) ($row->total_out ?? 0), 4),
            'quantity_on_hand' => round((float) ($row->quantity_on_hand ?? 0), 4),
            'currency_totals' => $currencyTotals,
        ], $this->singleCurrencyScalars($currencyTotals, ['stock_value']));
    }

    private function stockReceivedTotals(string $companyId, array $filters): array
    {
        $base = $this->movementBalanceBase($companyId, $filters)
            ->where('stock_movements.quantity_in', '>', 0)
            ->whereIn('stock_movements.movement_type', [
                'receive',
                'purchase',
                'opening',
                'adjustment_in',
                'stock_take_gain',
                'break_child_in',
                'return',
                'sale_void',
            ])
            ->selectRaw('
                stock_movements.company_id,
                stock_movements.branch_id,
                stock_movements.product_id,
                stock_movements.quantity_in,
                COALESCE(stock_movements.total_cost, 0) as total_cost,
                UPPER(NULLIF(TRIM(COALESCE(
                    NULLIF(TRIM(stock_movements.currency_code), ""),
                    NULLIF(TRIM(stock_movements.currency), ""),
                    ' . $this->costCurrencyExpression() . '
                )), "")) as currency_code
            ');

        $row = DB::query()
            ->fromSub(clone $base, 'received_total_rows')
            ->selectRaw('
                COUNT(*) as received_count,
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_in) as quantity_on_hand,
                SUM(quantity_in) as total_in
            ')
            ->first();

        $currencyTotals = DB::query()
            ->fromSub(clone $base, 'received_currency_rows')
            ->selectRaw('
                currency_code,
                COUNT(*) as received_count,
                COUNT(DISTINCT product_id) as product_count,
                SUM(quantity_in) as quantity_on_hand,
                SUM(quantity_in) as total_in,
                SUM(total_cost) as cost_value
            ')
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($currencyRow) => [
                'currency_code' => $this->normaliseCurrencyCode($currencyRow->currency_code ?? null),
                'received_count' => (int) ($currencyRow->received_count ?? 0),
                'product_count' => (int) ($currencyRow->product_count ?? 0),
                'quantity_on_hand' => round((float) ($currencyRow->quantity_on_hand ?? 0), 4),
                'total_in' => round((float) ($currencyRow->total_in ?? 0), 4),
                'cost_value' => round((float) ($currencyRow->cost_value ?? 0), 4),
            ])
            ->values()
            ->all();

        return array_merge([
            'received_count' => (int) ($row->received_count ?? 0),
            'product_count' => (int) ($row->product_count ?? 0),
            'quantity_on_hand' => round((float) ($row->quantity_on_hand ?? 0), 4),
            'total_in' => round((float) ($row->total_in ?? 0), 4),
            'currency_totals' => $currencyTotals,
        ], $this->singleCurrencyScalars($currencyTotals, ['cost_value']));
    }

    private function adjustmentTotals(string $companyId, array $filters): array
    {
        $base = $this->movementBalanceBase($companyId, $filters)
            ->whereIn('stock_movements.movement_type', [
                'adjustment',
                'adjustment_in',
                'adjustment_out',
                'stock_take_gain',
                'stock_take_loss',
                'wastage',
            ])
            ->selectRaw('
                stock_movements.company_id,
                stock_movements.branch_id,
                stock_movements.product_id,
                stock_movements.quantity_in,
                stock_movements.quantity_out,
                ' . $this->costColumn('average_cost') . ' as average_cost,
                UPPER(NULLIF(TRIM(' . $this->costCurrencyExpression() . '), "")) as currency_code
            ');

        $row = DB::query()
            ->fromSub(clone $base, 'adjustment_total_rows')
            ->selectRaw('
                COUNT(*) as adjustment_count,
                SUM(quantity_in) as increased_quantity,
                SUM(quantity_out) as decreased_quantity
            ')
            ->first();

        $currencyTotals = DB::query()
            ->fromSub(clone $base, 'adjustment_currency_rows')
            ->selectRaw('
                currency_code,
                COUNT(*) as adjustment_count,
                SUM(quantity_in) as increased_quantity,
                SUM(quantity_out) as decreased_quantity,
                SUM((CASE WHEN quantity_in > quantity_out THEN quantity_in ELSE quantity_out END) * average_cost) as cost_value,
                SUM(CASE WHEN quantity_in > 0 THEN quantity_in * average_cost ELSE 0 END) as increased_cost_value,
                SUM(CASE WHEN quantity_out > 0 THEN quantity_out * average_cost ELSE 0 END) as decreased_cost_value
            ')
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($currencyRow) => [
                'currency_code' => $this->normaliseCurrencyCode($currencyRow->currency_code ?? null),
                'cost_currency_code' => $this->normaliseCurrencyCode($currencyRow->currency_code ?? null),
                'adjustment_count' => (int) ($currencyRow->adjustment_count ?? 0),
                'increased_quantity' => round((float) ($currencyRow->increased_quantity ?? 0), 4),
                'decreased_quantity' => round((float) ($currencyRow->decreased_quantity ?? 0), 4),
                'cost_value' => round((float) ($currencyRow->cost_value ?? 0), 4),
                'increased_cost_value' => round((float) ($currencyRow->increased_cost_value ?? 0), 4),
                'decreased_cost_value' => round((float) ($currencyRow->decreased_cost_value ?? 0), 4),
            ])
            ->values()
            ->all();

        return array_merge([
            'adjustment_count' => (int) ($row->adjustment_count ?? 0),
            'increased_quantity' => round((float) ($row->increased_quantity ?? 0), 4),
            'decreased_quantity' => round((float) ($row->decreased_quantity ?? 0), 4),
            'currency_totals' => $currencyTotals,
        ], $this->singleCurrencyScalars($currencyTotals, [
            'cost_value',
            'increased_cost_value',
            'decreased_cost_value',
        ]));
    }

    /**
     * Keep legacy scalar monetary fields only when their currency is unambiguous.
     * Every summary always exposes the complete per-currency breakdown.
     *
     * @param  array<int, array<string, mixed>>  $currencyTotals
     * @param  array<int, string>  $monetaryFields
     * @return array<string, mixed>
     */
    private function singleCurrencyScalars(array $currencyTotals, array $monetaryFields): array
    {
        $single = count($currencyTotals) === 1
            && ($currencyTotals[0]['currency_code'] ?? null) !== null;
        $values = [
            'currency_code' => $single ? $currencyTotals[0]['currency_code'] : null,
        ];

        foreach ($monetaryFields as $field) {
            $values[$field] = $single ? ($currencyTotals[0][$field] ?? 0.0) : null;
        }

        return $values;
    }

    private function normaliseCurrencyCode($currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));

        return $currency !== '' ? $currency : null;
    }

    private function reorderLevelExpression(): string
    {
        if (Schema::hasColumn('products', 'reorder_level')) {
            return 'COALESCE(products.reorder_level, 0)';
        }

        if (Schema::hasColumn('products', 'minimum_stock')) {
            return 'COALESCE(products.minimum_stock, 0)';
        }

        if (Schema::hasColumn('products', 'minimum_stock_level')) {
            return 'COALESCE(products.minimum_stock_level, 0)';
        }

        return '0';
    }
}
