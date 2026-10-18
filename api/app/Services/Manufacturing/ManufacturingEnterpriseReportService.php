<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Facades\DB;

class ManufacturingEnterpriseReportService
{
    public function consolidatedPlanHistory(array $filters = []): array
    {
        $planIds = $this->planIds($filters);
        $reports = [];

        foreach ($planIds as $planId) {
            $reports[] = $this->productionPlanHistory($planId, $filters);
        }

        return $this->mergeHistories($reports);
    }

    public function productionPlanComparison(array $filters = []): array
    {
        $history = $this->consolidatedPlanHistory($filters);
        $rows = [];

        foreach ($history['plans'] as $plan) {
            $planId = $plan['id'] ?? null;
            $orders = collect($history['orders'])->where('plan_id', $planId)->values();
            $materials = collect($history['material_usage'])->where('plan_id', $planId)->values();
            $expenses = collect($history['expenses'])->where('plan_id', $planId)->values();

            $planned = (float) $orders->sum('planned_quantity');
            $good = (float) $orders->sum('actual_good_quantity');
            $reject = (float) $orders->sum('actual_reject_quantity');
            $finishedWaste = (float) $orders->sum('actual_waste_quantity');
            $materialWaste = (float) $materials->sum('wasted_quantity');
            $materialCost = (float) $materials->sum('total_cost');
            $labour = (float) $expenses->filter(function ($row) {
                return str_contains((string) ($row['accounting_class'] ?? ''), 'LABOUR')
                    || in_array($row['cost_type'] ?? '', ['labour', 'salary'], true);
            })->sum('amount');
            $overhead = max(0, (float) $expenses->sum('amount') - $labour);

            $rows[] = [
                'plan_id' => $planId,
                'plan' => trim(($plan['plan_number'] ?? '') . ' - ' . ($plan['name'] ?? '')),
                'status' => $plan['status'] ?? null,
                'orders' => $orders->count(),
                'planned_quantity' => $planned,
                'actual_good_quantity' => $good,
                'actual_reject_quantity' => $reject,
                'actual_waste_quantity' => $finishedWaste + $materialWaste,
                'yield_percent' => $planned > 0 ? ($good / $planned) * 100 : 0,
                'direct_material_cost' => $materialCost,
                'direct_labour_cost' => $labour,
                'indirect_overhead_cost' => $overhead,
                'finished_goods_value' => $materialCost + $labour + $overhead,
            ];
        }

        return [
            'summary' => $history['summary'],
            'rows' => $rows,
            'history' => $history,
        ];
    }

    public function enterpriseDashboard(array $filters = []): array
    {
        $history = $this->consolidatedPlanHistory($filters);
        return [
            'summary' => $history['summary'],
            'rows' => $history['orders'],
            'material_usage' => $history['material_usage'],
            'expenses' => $history['expenses'],
            'finished_goods' => $history['finished_goods'],
        ];
    }

    public function directIndirectCosts(array $filters = []): array
    {
        $history = $this->consolidatedPlanHistory($filters);
        $rows = [];
        foreach ($history['expenses'] as $line) {
            $rows[] = [
                'plan' => $line['plan_label'] ?? null,
                'production_number' => $line['production_number'] ?? null,
                'cost_type' => $line['cost_type'] ?? null,
                'accounting_class' => $line['accounting_class'] ?? null,
                'description' => $line['description'] ?? null,
                'amount' => (float) ($line['amount'] ?? 0),
                'currency' => $line['currency'] ?? 'USD',
                'classification' => str_contains((string) ($line['accounting_class'] ?? ''), 'LABOUR') ? 'DIRECT' : 'INDIRECT',
            ];
        }
        return ['summary' => $history['summary'], 'rows' => $rows];
    }

    public function staffCosts(array $filters = []): array
    {
        $history = $this->consolidatedPlanHistory($filters);
        $rows = collect($history['expenses'])->map(function ($line) {
            return [
                'plan' => $line['plan_label'] ?? null,
                'production_number' => $line['production_number'] ?? null,
                'staff_name' => $line['staff_name'] ?? $line['description'] ?? 'Unassigned',
                'staff_id' => $line['staff_id'] ?? null,
                'cost_type' => $line['cost_type'] ?? null,
                'accounting_class' => $line['accounting_class'] ?? null,
                'amount' => (float) ($line['amount'] ?? 0),
                'currency' => $line['currency'] ?? 'USD',
                'date' => $line['created_at'] ?? null,
            ];
        })->values()->all();
        return ['summary' => $history['summary'], 'rows' => $rows];
    }

    public function salaryAllocation(array $filters = []): array
    {
        return $this->staffCosts($filters);
    }

    public function productionActivity(array $filters = []): array
    {
        $history = $this->consolidatedPlanHistory($filters);
        $rows = [];
        foreach ($history['material_usage'] as $row) {
            $rows[] = ['activity_type' => 'Material Usage'] + $row;
        }
        foreach ($history['expenses'] as $row) {
            $rows[] = ['activity_type' => 'Cost / Labour'] + $row;
        }
        foreach ($history['finished_goods'] as $row) {
            $rows[] = ['activity_type' => 'Finished Goods'] + $row;
        }
        foreach ($history['stock_movements'] as $row) {
            $rows[] = ['activity_type' => 'Stock Movement'] + $row;
        }
        return ['summary' => $history['summary'], 'rows' => $rows];
    }

    public function productionOrderActivity(string $orderId, array $filters = []): array
    {
        $filters['production_order_id'] = $orderId;
        $history = $this->consolidatedPlanHistory($filters);
        return ['summary' => $history['summary'], 'rows' => collect($this->productionActivity($filters)['rows'])->where('production_order_id', $orderId)->values()->all()];
    }

    public function productionPlanHistory(string $planId, array $filters = []): array
    {
        $planQuery = DB::table('production_plans')->where('id', $planId);
        $this->applyTenantBranchScope($planQuery, $filters, 'production_plans');
        $plan = $planQuery->first();
        if (!$plan) {
            return ['plan' => ['id' => $planId, 'plan_number' => $planId], 'summary' => [], 'orders' => [], 'material_usage' => [], 'expenses' => [], 'finished_goods' => [], 'stock_movements' => [], 'accounting_ledger' => []];
        }

        $ordersQuery = DB::table('production_orders')->where('production_plan_id', $planId);
        $this->applyTenantBranchScope($ordersQuery, $filters, 'production_orders');
        $orders = $ordersQuery->get()->map(fn ($o) => (array) $o)->values()->all();
        $orderIds = collect($orders)->pluck('id')->filter()->values()->all();

        $materialUsageQuery = DB::table('production_order_inputs as i')
            ->leftJoin('production_orders as o', 'o.id', '=', 'i.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.production_order_id', $orderIds ?: ['__none__'])
            ->selectRaw('i.production_order_id,o.production_number,p.name as material,p.code as material_code,i.planned_quantity,i.reserved_quantity,i.issued_quantity,i.returned_quantity,i.wasted_quantity,(COALESCE(i.issued_quantity,0)-COALESCE(i.returned_quantity,0)-COALESCE(i.wasted_quantity,0)) as net_consumed_quantity,i.unit_cost,i.total_cost,COALESCE(i.currency,"USD") as currency,o.status');
        $this->applyTenantBranchScope($materialUsageQuery, $filters, 'i');
        $this->applyTenantBranchScope($materialUsageQuery, $filters, 'o');
        $materialUsage = $materialUsageQuery->get()->map(fn ($r) => (array) $r)->values()->all();

        $expensesQuery = DB::table('production_order_overheads as c')
            ->leftJoin('production_orders as o', 'o.id', '=', 'c.production_order_id')
            ->whereIn('c.production_order_id', $orderIds ?: ['__none__'])
            ->selectRaw('c.production_order_id,o.production_number,c.cost_type,c.accounting_class,c.description,c.staff_name,c.staff_id,c.amount,COALESCE(c.currency,"USD") as currency,c.created_at');
        $this->applyTenantBranchScope($expensesQuery, $filters, 'c');
        $this->applyTenantBranchScope($expensesQuery, $filters, 'o');
        $expenses = $expensesQuery->get()->map(fn ($r) => (array) $r)->values()->all();

        $finishedGoodsQuery = DB::table('production_order_outputs as out')
            ->leftJoin('production_orders as o', 'o.id', '=', 'out.production_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'out.product_id')
            ->whereIn('out.production_order_id', $orderIds ?: ['__none__'])
            ->selectRaw('out.production_order_id,o.production_number,p.name as finished_product,out.quantity_produced as quantity_received,out.quantity_rejected,out.quantity_wasted,out.unit_cost,out.total_cost,COALESCE(out.currency,"USD") as currency,out.created_at');
        $this->applyTenantBranchScope($finishedGoodsQuery, $filters, 'out');
        $this->applyTenantBranchScope($finishedGoodsQuery, $filters, 'o');
        $finishedGoods = $finishedGoodsQuery->get()->map(fn ($r) => (array) $r)->values()->all();

        $stockMovementsQuery = DB::table('stock_movements as s')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->whereIn('s.production_order_id', $orderIds ?: ['__none__'])
            ->selectRaw('s.*,p.name as product_name,p.code as product_code');
        $this->applyTenantBranchScope($stockMovementsQuery, $filters, 's');
        $stockMovements = $stockMovementsQuery->get()->map(fn ($r) => (array) $r)->values()->all();

        $summary = [
            'orders' => count($orders),
            'planned_quantity' => collect($orders)->sum('planned_quantity'),
            'actual_good_quantity' => collect($orders)->sum('actual_good_quantity'),
            'actual_reject_quantity' => collect($orders)->sum('actual_reject_quantity'),
            'actual_waste_quantity' => collect($orders)->sum('actual_waste_quantity'),
            'direct_material_cost' => collect($materialUsage)->sum('total_cost'),
            'direct_labour_cost' => collect($expenses)->filter(fn ($e) => str_contains((string) ($e['accounting_class'] ?? ''), 'LABOUR') || in_array($e['cost_type'] ?? '', ['labour', 'salary'], true))->sum('amount'),
            'indirect_overhead_cost' => collect($expenses)->reject(fn ($e) => str_contains((string) ($e['accounting_class'] ?? ''), 'LABOUR') || in_array($e['cost_type'] ?? '', ['labour', 'salary'], true))->sum('amount'),
            'finished_goods_value' => collect($finishedGoods)->sum('total_cost'),
        ];

        return [
            'plan' => (array) $plan,
            'summary' => $summary,
            'orders' => $orders,
            'material_usage' => $materialUsage,
            'expenses' => $expenses,
            'finished_goods' => $finishedGoods,
            'stock_movements' => $stockMovements,
            'accounting_ledger' => [],
        ];
    }

    protected function planIds(array $filters): array
    {
        $requestedIds = [];
        if (!empty($filters['production_plan_ids'])) {
            $requestedIds = $this->normalizeIds($filters['production_plan_ids']);
        } elseif (!empty($filters['production_plan_id'])) {
            $requestedIds = $this->normalizeIds($filters['production_plan_id']);
        }

        $query = DB::table('production_plans')->orderByDesc('created_at');
        $this->applyTenantBranchScope($query, $filters, 'production_plans');
        if ($requestedIds !== []) {
            $allowedIds = $query->whereIn('id', $requestedIds)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            return collect($requestedIds)
                ->filter(fn ($id) => in_array($id, $allowedIds, true))
                ->values()
                ->all();
        }

        if (!empty($filters['production_order_id'])) {
            $orderQuery = DB::table('production_orders')
                ->where('id', (string) $filters['production_order_id']);
            $this->applyTenantBranchScope($orderQuery, $filters, 'production_orders');
            $query->whereIn('id', $orderQuery->pluck('production_plan_id')->filter()->values()->all() ?: ['__none__']);
        } else {
            $query->limit(30);
        }
        if (!empty($filters['date_from'])) $query->whereDate('period_start', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('period_end', '<=', $filters['date_to']);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        return $query->pluck('id')->values()->all();
    }

    private function applyTenantBranchScope($query, array $filters, string $alias)
    {
        $companyId = is_scalar($filters['company_id'] ?? null)
            ? trim((string) $filters['company_id'])
            : '';
        $branchIds = $this->normalizeIds($filters['branch_ids'] ?? ($filters['branch_id'] ?? []));

        if ($companyId === '' || $branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where("{$alias}.company_id", $companyId)
            ->whereIn("{$alias}.branch_id", $branchIds);
    }

    private function normalizeIds(mixed $ids): array
    {
        if (is_scalar($ids)) {
            $ids = explode(',', (string) $ids);
        }

        if (!is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function mergeHistories(array $reports): array
    {
        $out = ['summary' => ['orders' => 0, 'planned_quantity' => 0, 'actual_good_quantity' => 0, 'actual_reject_quantity' => 0, 'actual_waste_quantity' => 0, 'direct_material_cost' => 0, 'direct_labour_cost' => 0, 'indirect_overhead_cost' => 0, 'finished_goods_value' => 0], 'plans' => [], 'orders' => [], 'material_usage' => [], 'expenses' => [], 'finished_goods' => [], 'stock_movements' => [], 'accounting_ledger' => []];
        foreach ($reports as $report) {
            $plan = $report['plan'] ?? [];
            $label = trim(($plan['plan_number'] ?? '') . ' - ' . ($plan['name'] ?? ''));
            $out['plans'][] = $plan;
            foreach ($out['summary'] as $key => $_) $out['summary'][$key] += (float) ($report['summary'][$key] ?? 0);
            foreach (['orders', 'material_usage', 'expenses', 'finished_goods', 'stock_movements', 'accounting_ledger'] as $section) {
                foreach (($report[$section] ?? []) as $row) {
                    $row['plan_label'] = $label;
                    $row['plan_id'] = $plan['id'] ?? null;
                    $row['plan_number'] = $plan['plan_number'] ?? null;
                    $out[$section][] = $row;
                }
            }
        }
        return $out;
    }
}
