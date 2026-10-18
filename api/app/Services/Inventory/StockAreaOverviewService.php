<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockAreaOverviewService
{
    /** @param array<int, string> $branchIds */
    public function forCompany(string $companyId, ?string $branchId = null, array $branchIds = []): array
    {
        $usage = $this->usageExpression();
        $area = "LOWER(COALESCE(NULLIF(ps.stock_area, ''), 'front'))";
        $cost = 'COALESCE(NULLIF(pc.average_cost, 0), pc.last_cost, 0)';

        $base = DB::table('product_stocks as ps')
            ->join('products as p', function ($join) {
                $join->on('p.id', '=', 'ps.product_id')
                    ->on('p.company_id', '=', 'ps.company_id');
            })
            ->leftJoin('product_costs as pc', function ($join) {
                $join->on('pc.company_id', '=', 'ps.company_id')
                    ->on('pc.branch_id', '=', 'ps.branch_id')
                    ->on('pc.product_id', '=', 'ps.product_id');
            })
            ->where('ps.company_id', $companyId)
            ->when($branchId, fn ($query) => $query->where('ps.branch_id', $branchId))
            ->when(!$branchId && $branchIds !== [], fn ($query) => $query->whereIn('ps.branch_id', $branchIds));

        $areas = (clone $base)
            ->selectRaw("{$area} as normalized_stock_area,
                COUNT(DISTINCT ps.product_id) as product_count,
                COALESCE(SUM(ps.quantity_on_hand), 0) as quantity_on_hand,
                COALESCE(SUM(ps.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(ps.available_quantity), 0) as available_quantity,
                COALESCE(SUM(ps.quantity_on_hand * {$cost}), 0) as stock_value,
                COUNT(DISTINCT CASE WHEN {$usage} = 'turnover' THEN ps.product_id END) as turnover_product_count,
                COALESCE(SUM(CASE WHEN {$usage} = 'turnover' THEN ps.quantity_on_hand ELSE 0 END), 0) as turnover_quantity_on_hand,
                COALESCE(SUM(CASE WHEN {$usage} = 'turnover' THEN ps.quantity_on_hand * {$cost} ELSE 0 END), 0) as turnover_stock_value,
                COUNT(DISTINCT CASE WHEN {$usage} = 'non_turnover' THEN ps.product_id END) as non_turnover_product_count,
                COALESCE(SUM(CASE WHEN {$usage} = 'non_turnover' THEN ps.quantity_on_hand ELSE 0 END), 0) as non_turnover_quantity_on_hand,
                COALESCE(SUM(CASE WHEN {$usage} = 'non_turnover' THEN ps.available_quantity ELSE 0 END), 0) as non_turnover_available_quantity,
                COALESCE(SUM(CASE WHEN {$usage} = 'non_turnover' THEN ps.quantity_on_hand * {$cost} ELSE 0 END), 0) as non_turnover_stock_value")
            ->groupByRaw('normalized_stock_area')
            // Order by the grouped select alias. Repeating ps.stock_area here
            // is rejected by MySQL when ONLY_FULL_GROUP_BY is enabled.
            ->orderByRaw("CASE normalized_stock_area WHEN 'back' THEN 0 WHEN 'front' THEN 1 ELSE 2 END")
            ->get()
            ->map(fn ($row) => $this->castArea($row))
            ->values();

        $nonTurnoverBase = (clone $base)->whereRaw("{$usage} = 'non_turnover'");
        $nonTurnoverSummary = (clone $nonTurnoverBase)
            ->selectRaw("COUNT(DISTINCT ps.product_id) as product_count,
                COALESCE(SUM(ps.quantity_on_hand), 0) as quantity_on_hand,
                COALESCE(SUM(ps.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(ps.available_quantity), 0) as available_quantity,
                COALESCE(SUM(ps.quantity_on_hand * {$cost}), 0) as stock_value")
            ->first();

        $defaultDestination = Schema::hasColumn('products', 'default_issue_destination')
            ? 'p.default_issue_destination'
            : "'expense'";
        $expenseAccount = Schema::hasColumn('products', 'expense_account_code')
            ? 'p.expense_account_code'
            : 'NULL';
        if (Schema::hasColumn('product_costs', 'currency_code') && Schema::hasColumn('product_costs', 'currency')) {
            $currency = "COALESCE(MAX(pc.currency_code), MAX(pc.currency), 'USD')";
        } elseif (Schema::hasColumn('product_costs', 'currency_code')) {
            $currency = "COALESCE(MAX(pc.currency_code), 'USD')";
        } elseif (Schema::hasColumn('product_costs', 'currency')) {
            $currency = "COALESCE(MAX(pc.currency), 'USD')";
        } else {
            $currency = "'USD'";
        }

        $nonTurnoverProducts = (clone $nonTurnoverBase)
            ->leftJoin('company_branches as cb', function ($join) {
                $join->on('cb.id', '=', 'ps.branch_id')
                    ->on('cb.company_id', '=', 'ps.company_id');
            })
            ->selectRaw("p.id as product_id,
                p.name as product_name,
                p.code,
                p.sku,
                p.product_type,
                {$defaultDestination} as default_issue_destination,
                {$expenseAccount} as expense_account_code,
                ps.branch_id,
                COALESCE(cb.branch_name, cb.branch_code, 'Unassigned') as branch_name,
                COALESCE(SUM(CASE WHEN {$area} = 'back' THEN ps.quantity_on_hand ELSE 0 END), 0) as back_quantity_on_hand,
                COALESCE(SUM(CASE WHEN {$area} = 'back' THEN ps.available_quantity ELSE 0 END), 0) as back_available_quantity,
                COALESCE(SUM(CASE WHEN {$area} = 'front' THEN ps.quantity_on_hand ELSE 0 END), 0) as front_quantity_on_hand,
                COALESCE(SUM(CASE WHEN {$area} = 'front' THEN ps.available_quantity ELSE 0 END), 0) as front_available_quantity,
                COALESCE(SUM(ps.quantity_on_hand), 0) as total_quantity_on_hand,
                COALESCE(SUM(ps.available_quantity), 0) as total_available_quantity,
                MAX({$cost}) as unit_cost,
                COALESCE(SUM(ps.quantity_on_hand * {$cost}), 0) as stock_value,
                {$currency} as currency")
            ->groupBy(
                'p.id',
                'p.name',
                'p.code',
                'p.sku',
                'p.product_type',
                DB::raw($defaultDestination),
                DB::raw($expenseAccount),
                'ps.branch_id',
                'cb.branch_name',
                'cb.branch_code'
            )
            ->orderBy('p.name')
            ->get()
            ->map(fn ($row) => $this->castNonTurnoverProduct($row))
            ->values()
            ->all();

        $areaMap = $areas->keyBy('stock_area');
        $back = $areaMap->get('back', $this->blankArea('back'));
        $front = $areaMap->get('front', $this->blankArea('front'));
        $areas = collect([$back, $front])
            ->merge($areas->reject(fn (array $row) => in_array($row['stock_area'], ['back', 'front'], true)))
            ->values();

        return [
            'summary' => [
                'back_stock_product_count' => $back['product_count'],
                'back_stock_quantity_on_hand' => $back['quantity_on_hand'],
                'back_stock_available_quantity' => $back['available_quantity'],
                'back_stock_value' => $back['stock_value'],
                'front_stock_product_count' => $front['product_count'],
                'front_stock_quantity_on_hand' => $front['quantity_on_hand'],
                'front_stock_available_quantity' => $front['available_quantity'],
                'front_stock_value' => $front['stock_value'],
                'non_turnover_product_count' => (int) ($nonTurnoverSummary->product_count ?? 0),
                'non_turnover_quantity_on_hand' => round((float) ($nonTurnoverSummary->quantity_on_hand ?? 0), 4),
                'non_turnover_available_quantity' => round((float) ($nonTurnoverSummary->available_quantity ?? 0), 4),
                'non_turnover_stock_value' => round((float) ($nonTurnoverSummary->stock_value ?? 0), 4),
            ],
            'stock_areas' => $areas->all(),
            'non_turnover_stock' => [
                'summary' => [
                    'product_count' => (int) ($nonTurnoverSummary->product_count ?? 0),
                    'quantity_on_hand' => round((float) ($nonTurnoverSummary->quantity_on_hand ?? 0), 4),
                    'reserved_quantity' => round((float) ($nonTurnoverSummary->reserved_quantity ?? 0), 4),
                    'available_quantity' => round((float) ($nonTurnoverSummary->available_quantity ?? 0), 4),
                    'stock_value' => round((float) ($nonTurnoverSummary->stock_value ?? 0), 4),
                ],
                'products' => $nonTurnoverProducts,
            ],
        ];
    }

    private function usageExpression(): string
    {
        if (! Schema::hasColumn('products', 'stock_usage_type')) {
            return "CASE WHEN LOWER(COALESCE(p.product_type, '')) IN ('consumable', 'consumables') THEN 'non_turnover' ELSE 'turnover' END";
        }

        return "CASE
            WHEN LOWER(REPLACE(REPLACE(COALESCE(p.stock_usage_type, ''), '-', '_'), ' ', '_')) IN ('non_turnover', 'nonturnover', 'non_turn_over') THEN 'non_turnover'
            WHEN COALESCE(p.stock_usage_type, '') = '' AND LOWER(COALESCE(p.product_type, '')) IN ('consumable', 'consumables') THEN 'non_turnover'
            ELSE 'turnover'
        END";
    }

    private function castArea(object $row): array
    {
        return [
            'stock_area' => (string) $row->normalized_stock_area,
            'product_count' => (int) $row->product_count,
            'quantity_on_hand' => round((float) $row->quantity_on_hand, 4),
            'reserved_quantity' => round((float) $row->reserved_quantity, 4),
            'available_quantity' => round((float) $row->available_quantity, 4),
            'stock_value' => round((float) $row->stock_value, 4),
            'turnover_product_count' => (int) $row->turnover_product_count,
            'turnover_quantity_on_hand' => round((float) $row->turnover_quantity_on_hand, 4),
            'turnover_stock_value' => round((float) $row->turnover_stock_value, 4),
            'non_turnover_product_count' => (int) $row->non_turnover_product_count,
            'non_turnover_quantity_on_hand' => round((float) $row->non_turnover_quantity_on_hand, 4),
            'non_turnover_available_quantity' => round((float) $row->non_turnover_available_quantity, 4),
            'non_turnover_stock_value' => round((float) $row->non_turnover_stock_value, 4),
        ];
    }

    private function castNonTurnoverProduct(object $row): array
    {
        return [
            'product_id' => (string) $row->product_id,
            'product_name' => (string) $row->product_name,
            'code' => $row->code,
            'sku' => $row->sku,
            'product_type' => $row->product_type,
            'stock_usage_type' => 'non_turnover',
            'default_issue_destination' => $row->default_issue_destination ?: 'expense',
            'expense_account_code' => $row->expense_account_code,
            'branch_id' => (string) $row->branch_id,
            'branch_name' => (string) $row->branch_name,
            'back_quantity_on_hand' => round((float) $row->back_quantity_on_hand, 4),
            'back_available_quantity' => round((float) $row->back_available_quantity, 4),
            'front_quantity_on_hand' => round((float) $row->front_quantity_on_hand, 4),
            'front_available_quantity' => round((float) $row->front_available_quantity, 4),
            'total_quantity_on_hand' => round((float) $row->total_quantity_on_hand, 4),
            'total_available_quantity' => round((float) $row->total_available_quantity, 4),
            'unit_cost' => round((float) $row->unit_cost, 6),
            'stock_value' => round((float) $row->stock_value, 4),
            'currency' => (string) $row->currency,
        ];
    }

    private function blankArea(string $area): array
    {
        return [
            'stock_area' => $area,
            'product_count' => 0,
            'quantity_on_hand' => 0,
            'reserved_quantity' => 0,
            'available_quantity' => 0,
            'stock_value' => 0,
            'turnover_product_count' => 0,
            'turnover_quantity_on_hand' => 0,
            'turnover_stock_value' => 0,
            'non_turnover_product_count' => 0,
            'non_turnover_quantity_on_hand' => 0,
            'non_turnover_available_quantity' => 0,
            'non_turnover_stock_value' => 0,
        ];
    }
}
