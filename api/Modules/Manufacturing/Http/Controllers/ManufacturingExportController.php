<?php

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Reports\EnterpriseReportDownloadService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Models\ProductionPlan;

class ManufacturingExportController extends Controller
{
    public function __construct(
        private ManufacturingReportController $reports,
        private EnterpriseReportDownloadService $downloads,
        private BusinessModeBranchScopeService $branchScope
    ) {}

    public function download(Request $request, string $report, string $format)
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        abort_unless(array_key_exists($report, $this->definitions()) || $report === 'enterprise-activity', 404);

        [$title, $summary, $datasets] = $report === 'enterprise-activity'
            ? $this->enterpriseActivity($request)
            : $this->standardReport($request, $report);

        $summaryRows = collect($summary)
            ->filter(fn ($value) => is_scalar($value) || $value === null)
            ->map(fn ($value, $key) => [$this->label($key), $this->display($value)])
            ->values()
            ->all();

        $sheets = [];
        if ($summaryRows !== []) {
            $sheets[] = [
                'title' => 'Executive Summary',
                'headers' => ['Metric', 'Value'],
                'rows' => $summaryRows,
            ];
        }

        foreach ($datasets as $dataset) {
            $sheets[] = [
                'title' => $dataset['title'],
                'headers' => array_column($dataset['columns'], 'label'),
                'rows' => $this->tableRows($dataset['rows'], $dataset['columns'], $request->string('q')->toString()),
            ];
        }

        if ($sheets === []) {
            $sheets[] = ['title' => 'Report', 'headers' => ['Message'], 'rows' => [['No records found.']]];
        }

        $filename = $this->filename($title, $format);

        if ($format === 'xlsx') {
            return $this->downloads->downloadSpreadsheet($title, $filename, $sheets, true);
        }

        return $this->downloads->downloadPdf($title, $filename, $sheets, null, 'a4', 'landscape', true);
    }

    private function standardReport(Request $request, string $report): array
    {
        if ($report === 'plan-history') {
            $history = $this->consolidatedHistory($request);
            $datasets = [];

            foreach ($this->historyDefinitions() as $key => $definition) {
                $datasets[] = [
                    'title' => $definition['title'],
                    'columns' => $definition['columns'],
                    'rows' => $history[$key] ?? [],
                ];
            }

            return ['Production Plan History', $history['summary'] ?? [], $datasets];
        }

        if ($report === 'plan-comparison') {
            $history = $this->consolidatedHistory($request);
            $rows = $this->comparisonRows($history);
            $definition = $this->definitions()[$report];

            return [$definition['title'], $history['summary'] ?? [], [[
                'title' => $definition['title'],
                'columns' => $definition['columns'],
                'rows' => $rows,
            ]]];
        }

        if ($report === 'accounting-ledger') {
            $history = $this->consolidatedHistory($request);
            $definition = $this->definitions()[$report];

            return [$definition['title'], $history['summary'] ?? [], [[
                'title' => $definition['title'],
                'columns' => $definition['columns'],
                'rows' => $history['accounting_ledger'] ?? [],
            ]]];
        }

        $definition = $this->definitions()[$report];
        $response = $this->reports->{$definition['method']}($request);
        $data = $this->responseData($response);
        $summary = $data['summary'] ?? [];
        $rows = $this->rowsAt($data, $definition['rows']);

        if ($report === 'production-summary' && $summary === []) {
            $summary = collect($data)->except(['recent_production'])->all();
        }

        return [$definition['title'], $summary, [[
            'title' => $definition['title'],
            'columns' => $definition['columns'],
            'rows' => $rows,
        ]]];
    }

    private function enterpriseActivity(Request $request): array
    {
        $costs = $this->responseData($this->reports->productionCosts($request));
        $materials = $this->responseData($this->reports->materialUsage($request));
        $wastage = $this->responseData($this->reports->wastage($request));
        $finished = $this->responseData($this->reports->finishedGoodsReceived($request));

        $rows = collect();
        foreach ($costs['rows'] ?? [] as $row) {
            $rows->push($this->activityRow('Costing', $row));
        }
        foreach ($materials['rows'] ?? [] as $row) {
            $rows->push($this->activityRow('Material Movement', $row));
        }
        foreach ($wastage['rows'] ?? [] as $row) {
            $rows->push($this->activityRow('Wastage / Loss', $row));
        }
        foreach ($finished['rows'] ?? [] as $row) {
            $rows->push($this->activityRow('Finished Goods Received', $row));
        }

        $rows = $rows->sortByDesc('date')->values();
        $summary = [
            'Activities' => $rows->count(),
            'Direct Cost' => $rows->sum('direct_cost'),
            'Indirect Cost' => $rows->sum('indirect_cost'),
            'Total Cost' => $rows->sum('total_cost'),
        ];

        return ['Enterprise Manufacturing Activity Report', $summary, [[
            'title' => 'Manufacturing Activities',
            'columns' => [
                $this->column('Type', 'type'),
                $this->column('Date', 'date'),
                $this->column('Production No', 'production_number'),
                $this->column('Product / Material', 'product'),
                $this->column('Status', 'status'),
                $this->column('Quantity', 'quantity'),
                $this->column('Direct Cost', 'direct_cost'),
                $this->column('Indirect Cost', 'indirect_cost'),
                $this->column('Total Cost', 'total_cost'),
                $this->column('Currency', 'currency'),
                $this->column('Staff', 'staff'),
            ],
            'rows' => $rows,
        ]]];
    }

    private function activityRow(string $type, $source): array
    {
        $row = (array) $source;
        $materialCost = (float) ($row['direct_material_cost'] ?? $row['actual_raw_material_cost'] ?? $row['total_cost'] ?? $row['waste_cost'] ?? 0);
        $labourCost = (float) ($row['direct_labour_cost'] ?? $row['actual_labour_cost'] ?? 0);
        $indirectCost = (float) ($row['indirect_overhead_cost'] ?? $row['actual_overhead_cost'] ?? 0);
        $total = (float) ($row['actual_total_cost'] ?? $row['total_cost'] ?? $row['waste_cost'] ?? ($materialCost + $labourCost + $indirectCost));

        return [
            'type' => $type,
            'date' => $row['completed_at'] ?? $row['created_at'] ?? $row['transaction_date'] ?? null,
            'production_number' => $row['production_number'] ?? $row['production_order_code'] ?? null,
            'product' => $row['finished_product'] ?? $row['product_name'] ?? $row['material'] ?? null,
            'status' => $row['order_status'] ?? $row['status'] ?? null,
            'quantity' => $row['quantity_received'] ?? $row['actual_good_quantity'] ?? $row['net_consumed_quantity'] ?? $row['wasted_quantity'] ?? $row['planned_quantity'] ?? 0,
            'direct_cost' => $materialCost + $labourCost,
            'indirect_cost' => $indirectCost,
            'total_cost' => $total,
            'currency' => $row['currency'] ?? 'USD',
            'staff' => $row['staff_name'] ?? $row['created_by_name'] ?? null,
        ];
    }

    private function consolidatedHistory(Request $request): array
    {
        $output = [
            'summary' => [], 'plans' => [], 'orders' => [], 'material_usage' => [],
            'expenses' => [], 'finished_goods' => [], 'stock_movements' => [], 'accounting_ledger' => [],
        ];

        foreach ($this->planIds($request) as $planId) {
            $history = $this->responseData($this->reports->productionPlanHistory($request, $planId));
            $plan = (array) ($history['plan'] ?? []);
            $label = trim(($plan['plan_number'] ?? '') . ' - ' . ($plan['name'] ?? ''), " -");
            $output['plans'][] = $plan;

            foreach ((array) ($history['summary'] ?? []) as $key => $value) {
                if (is_numeric($value)) {
                    $output['summary'][$key] = (float) ($output['summary'][$key] ?? 0) + (float) $value;
                }
            }

            foreach (array_keys($this->historyDefinitions()) as $section) {
                foreach ((array) ($history[$section] ?? []) as $row) {
                    $output[$section][] = ['plan_label' => $label] + (array) $row;
                }
            }
        }

        return $output;
    }

    private function planIds(Request $request): array
    {
        $requested = $request->input('production_plan_ids', $request->input('production_plan_id'));
        $ids = collect(is_array($requested) ? $requested : explode(',', (string) $requested))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $companyId = (string) app('company')->id;
        $requestedBranchIds = $request->input('branch_ids', []);
        if (is_string($requestedBranchIds)) $requestedBranchIds = explode(',', $requestedBranchIds);
        if (!is_array($requestedBranchIds)) $requestedBranchIds = [];
        if ($request->filled('branch_id')) $requestedBranchIds[] = $request->input('branch_id');

        $scope = $this->branchScope->resolve($request, $companyId, $requestedBranchIds);
        if ($scope['business_mode'] !== 'retail') {
            throw new HttpResponseException(response()->json([
                'message' => 'Manufacturing reports are only available in a Retail workspace.',
                'code' => 'BUSINESS_MODE_MISMATCH',
                'required_modes' => ['retail'],
                'current_mode' => $scope['business_mode'],
                'branch_id' => $scope['context_branch_id'],
            ], 409));
        }

        $query = ProductionPlan::query()
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $scope['branch_ids']);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->date_to);
        if ($ids->isNotEmpty()) $query->whereIn('id', $ids);

        return $query->latest()->limit(100)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function comparisonRows(array $history): array
    {
        return collect($history['plans'] ?? [])->map(function ($plan) use ($history) {
            $plan = (array) $plan;
            $id = (string) ($plan['id'] ?? '');
            $orders = collect($history['orders'] ?? [])->where('production_plan_id', $id);
            if ($orders->isEmpty()) $orders = collect($history['orders'] ?? [])->where('plan_id', $id);
            $materials = collect($history['material_usage'] ?? [])->where('plan_id', $id);
            $expenses = collect($history['expenses'] ?? [])->where('plan_id', $id);
            $planned = (float) $orders->sum(fn ($row) => (float) ($row['planned_quantity'] ?? $row['planned_output_quantity'] ?? 0));
            $good = (float) $orders->sum('actual_good_quantity');
            $labour = (float) $expenses->filter(fn ($row) => str_contains(strtoupper((string) ($row['accounting_class'] ?? $row['cost_type'] ?? '')), 'LABOUR'))->sum('amount');
            $expenseTotal = (float) $expenses->sum('amount');

            return [
                'plan' => trim(($plan['plan_number'] ?? '') . ' - ' . ($plan['name'] ?? ''), " -"),
                'status' => $plan['status'] ?? null,
                'orders' => $orders->count(),
                'planned_quantity' => $planned,
                'actual_good_quantity' => $good,
                'actual_reject_quantity' => (float) $orders->sum('actual_reject_quantity'),
                'actual_waste_quantity' => (float) $orders->sum('actual_waste_quantity') + (float) $materials->sum('wasted_quantity'),
                'yield_percent' => $planned > 0 ? round(($good / $planned) * 100, 2) : 0,
                'direct_material_cost' => (float) $materials->sum('total_cost'),
                'direct_labour_cost' => $labour,
                'indirect_overhead_cost' => max(0, $expenseTotal - $labour),
                'actual_total_cost' => (float) $materials->sum('total_cost') + $expenseTotal,
            ];
        })->values()->all();
    }

    private function responseData($response): array
    {
        if (method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 400) {
            throw new HttpResponseException($response);
        }

        $payload = method_exists($response, 'getData') ? $response->getData(true) : (array) $response;
        return (array) ($payload['data'] ?? []);
    }

    private function rowsAt(array $data, string $path): array
    {
        $rows = $path === '.' ? $data : data_get($data, $path, []);
        if ($rows instanceof Collection) return $rows->values()->all();
        return is_array($rows) ? array_values($rows) : [];
    }

    private function tableRows(iterable $rows, array $columns, string $search = ''): array
    {
        $needle = strtolower(trim($search));

        return collect($rows)
            ->map(fn ($row) => (array) $row)
            ->filter(fn ($row) => $needle === '' || str_contains(strtolower(json_encode($row) ?: ''), $needle))
            ->map(fn ($row) => collect($columns)->map(fn ($column) => $this->display($this->value($row, $column['keys'])))->all())
            ->values()
            ->all();
    }

    private function value(array $row, array $keys)
    {
        foreach ($keys as $key) {
            $value = data_get($row, $key);
            if ($value !== null && $value !== '') return $value;
        }
        return null;
    }

    private function display($value)
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if (is_array($value) || is_object($value)) {
            $array = (array) $value;
            return $array['name'] ?? $array['code'] ?? json_encode($array, JSON_UNESCAPED_SLASHES);
        }
        return $value ?? '-';
    }

    private function column(string $label, string|array $keys): array
    {
        return ['label' => $label, 'keys' => (array) $keys];
    }

    private function definitions(): array
    {
        $production = [
            $this->column('Production No', ['production_number', 'production_order_code']),
            $this->column('Product', ['finished_product', 'product_name']),
            $this->column('Status', ['order_status', 'status']),
            $this->column('Planned Qty', ['planned_quantity', 'planned_output_quantity']),
            $this->column('Good Qty', ['actual_good_quantity', 'completed_quantity', 'quantity_received']),
            $this->column('Reject Qty', ['actual_reject_quantity', 'reject_quantity', 'quantity_rejected']),
            $this->column('Waste Qty', ['actual_waste_quantity', 'waste_quantity', 'quantity_wasted']),
            $this->column('Planned Cost', 'planned_total_cost'),
            $this->column('Actual Cost', ['actual_total_cost', 'total_cost']),
            $this->column('Unit Cost', ['actual_unit_cost', 'unit_cost']),
            $this->column('Currency', 'currency'),
            $this->column('Created', 'created_at'),
            $this->column('Completed', 'completed_at'),
        ];

        return [
            'production-summary' => ['title' => 'Production Summary', 'method' => 'productionSummary', 'rows' => 'recent_production', 'columns' => $production],
            'production-costs' => ['title' => 'Production Costs', 'method' => 'productionCosts', 'rows' => 'rows', 'columns' => array_merge(array_slice($production, 0, 5), [
                $this->column('BOM Cost', 'bom_standard_cost'), $this->column('Direct Material', 'direct_material_cost'),
                $this->column('Direct Labour', 'direct_labour_cost'), $this->column('Overhead', 'indirect_overhead_cost'),
                $this->column('Actual Total', 'actual_total_cost'), $this->column('Variance', 'cost_variance_amount'),
                $this->column('Currency', 'currency'),
            ])],
            'material-usage' => ['title' => 'Material Usage', 'method' => 'materialUsage', 'rows' => 'rows', 'columns' => $this->historyDefinitions()['material_usage']['columns']],
            'material-requirements' => ['title' => 'Material Requirements', 'method' => 'materialRequirements', 'rows' => 'data', 'columns' => [
                $this->column('Plan', ['plan_number', 'production_plan_id']), $this->column('Material', ['product.name', 'product_name']),
                $this->column('Code', ['product.code', 'product_code']), $this->column('Required', 'required_quantity'),
                $this->column('Available', 'available_quantity'), $this->column('Reserved', 'reserved_quantity'),
                $this->column('Shortage', 'shortage_quantity'), $this->column('Status', 'status'),
            ]],
            'shortage-alerts' => ['title' => 'Material Shortage Alerts', 'method' => 'materialShortageAlerts', 'rows' => '.', 'columns' => [
                $this->column('Plan', ['plan_number', 'production_plan_id']), $this->column('Material', ['product', 'product_name']),
                $this->column('Code', 'product_code'), $this->column('Required', 'required_quantity'),
                $this->column('Available', 'available_quantity'), $this->column('Shortage', 'shortage_quantity'),
                $this->column('Status', 'status'),
            ]],
            'finished-goods' => ['title' => 'Finished Goods Received', 'method' => 'finishedGoodsReceived', 'rows' => 'rows', 'columns' => $production],
            'work-in-progress' => ['title' => 'Work In Progress', 'method' => 'workInProgress', 'rows' => 'rows', 'columns' => $production],
            'wastage' => ['title' => 'Wastage and Rejects', 'method' => 'wastage', 'rows' => 'rows', 'columns' => $production],
            'variance' => ['title' => 'Production Variance', 'method' => 'variance', 'rows' => 'rows', 'columns' => array_merge(array_slice($production, 0, 7), [
                $this->column('Quantity Variance', 'quantity_variance'), $this->column('Planned Cost', 'planned_total_cost'),
                $this->column('Actual Cost', 'actual_total_cost'), $this->column('Cost Variance', 'cost_variance_amount'),
                $this->column('Unit Cost Variance', 'unit_cost_variance'), $this->column('Completed', 'completed_at'),
            ])],
            'accounting-ledger' => ['title' => 'Accounting Ledger', 'columns' => $this->historyDefinitions()['accounting_ledger']['columns']],
            'plan-history' => ['title' => 'Production Plan History', 'columns' => []],
            'plan-comparison' => ['title' => 'Production Plan Comparison', 'columns' => [
                $this->column('Plan', 'plan'), $this->column('Status', 'status'), $this->column('Orders', 'orders'),
                $this->column('Planned Qty', 'planned_quantity'), $this->column('Good Output', 'actual_good_quantity'),
                $this->column('Reject', 'actual_reject_quantity'), $this->column('Waste', 'actual_waste_quantity'),
                $this->column('Yield %', 'yield_percent'), $this->column('Material Cost', 'direct_material_cost'),
                $this->column('Labour Cost', 'direct_labour_cost'), $this->column('Overhead', 'indirect_overhead_cost'),
                $this->column('Total Cost', 'actual_total_cost'),
            ]],
        ];
    }

    private function historyDefinitions(): array
    {
        return [
            'orders' => ['title' => 'Orders', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Production No', 'production_number'),
                $this->column('Product', ['finished_product', 'product_name']), $this->column('Status', 'status'),
                $this->column('Planned Qty', ['planned_quantity', 'planned_output_quantity']),
                $this->column('Good Qty', 'actual_good_quantity'), $this->column('Reject Qty', 'actual_reject_quantity'),
                $this->column('Waste Qty', 'actual_waste_quantity'), $this->column('Actual Cost', 'actual_total_cost'),
                $this->column('Unit Cost', 'actual_unit_cost'), $this->column('Created', 'created_at'), $this->column('Completed', 'completed_at'),
            ]],
            'material_usage' => ['title' => 'Material Usage', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Production No', 'production_number'),
                $this->column('Material', ['material', 'product_name']), $this->column('Code', ['material_code', 'product_code']),
                $this->column('Planned', 'planned_quantity'), $this->column('Reserved', 'reserved_quantity'),
                $this->column('Issued', 'issued_quantity'), $this->column('Returned', 'returned_quantity'),
                $this->column('Wasted', 'wasted_quantity'), $this->column('Net Consumed', 'net_consumed_quantity'),
                $this->column('Unit Cost', 'unit_cost'), $this->column('Total Cost', 'total_cost'),
                $this->column('Currency', 'currency'), $this->column('Status', ['order_status', 'status']),
            ]],
            'expenses' => ['title' => 'Expenses and Labour', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Production No', 'production_number'),
                $this->column('Cost Type', 'cost_type'), $this->column('Class', 'accounting_class'),
                $this->column('Staff', 'staff_name'), $this->column('Description', 'description'),
                $this->column('Amount', 'amount'), $this->column('Currency', 'currency'), $this->column('Date', 'created_at'),
            ]],
            'finished_goods' => ['title' => 'Finished Goods', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Production No', 'production_number'),
                $this->column('Finished Product', ['finished_product', 'product_name']), $this->column('Received', 'quantity_received'),
                $this->column('Rejected', 'quantity_rejected'), $this->column('Wasted', 'quantity_wasted'),
                $this->column('Unit Cost', 'unit_cost'), $this->column('Total Cost', 'total_cost'),
                $this->column('Currency', 'currency'), $this->column('Date', 'created_at'),
            ]],
            'stock_movements' => ['title' => 'Stock Movements', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Date', 'created_at'),
                $this->column('Production No', 'production_number'), $this->column('Product', 'product_name'),
                $this->column('Code', 'product_code'), $this->column('Movement', 'movement_type'),
                $this->column('Qty In', 'quantity_in'), $this->column('Qty Out', 'quantity_out'),
                $this->column('Unit Cost', 'unit_cost'), $this->column('Total Cost', 'total_cost'),
                $this->column('Currency', 'currency'), $this->column('Notes', 'notes'),
            ]],
            'accounting_ledger' => ['title' => 'Accounting Ledger', 'columns' => [
                $this->column('Plan', 'plan_label'), $this->column('Date', ['date', 'created_at']),
                $this->column('Production No', 'production_number'), $this->column('Account', ['account', 'account_name']),
                $this->column('Description', 'description'), $this->column('Source', ['source', 'entry_type', 'type']),
                $this->column('Debit', 'debit'), $this->column('Credit', 'credit'), $this->column('Currency', 'currency'),
            ]],
        ];
    }

    private function label(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function filename(string $title, string $format): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        return $slug . '-' . now()->format('Ymd-His') . '.' . $format;
    }
}
