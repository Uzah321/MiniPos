<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryVarianceAgentController extends Controller
{
    public function overview(
        Request $request,
        BusinessModeBranchScopeService $branchScope,
        InventoryVarianceAgentService $agent
    ): JsonResponse {
        $companyId = (string) app('company')->id;
        $scope = $this->scope($request, $companyId, $branchScope);
        $settings = $agent->settings($companyId);
        if ($settings['enabled']) {
            $agent->queueScan($companyId, $scope['branch_ids'], $request->user()?->id, 'overview');
        }

        $summary = DB::table('inventory_variances')
            ->where('company_id', $companyId)->whereIn('branch_id', $scope['branch_ids'])
            ->where('status', 'open')
            ->selectRaw("COUNT(*) as open, SUM(CASE WHEN direction = 'shortage' THEN 1 ELSE 0 END) as shortages, SUM(CASE WHEN direction = 'surplus' THEN 1 ELSE 0 END) as surpluses, SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high, SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium, SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low, COALESCE(SUM(exposure_value), 0) as exposure_value, MIN(started_at) as oldest_started_at")
            ->first();
        $exposureByCurrency = DB::table('inventory_variances')
            ->where('company_id', $companyId)->whereIn('branch_id', $scope['branch_ids'])
            ->where('status', 'open')
            ->selectRaw("COALESCE(currency_code, 'UNSPECIFIED') as currency_code, COALESCE(SUM(exposure_value), 0) as exposure_value")
            ->groupByRaw("COALESCE(currency_code, 'UNSPECIFIED')")->get()
            ->map(fn ($row) => ['currency_code' => $row->currency_code,
                'exposure_value' => round((float) $row->exposure_value, 4)])->all();

        $lastScan = $agent->lastScan($companyId, $scope['branch_ids']);
        return response()->json(['data' => [
            'enabled' => $settings['enabled'],
            'settings' => $settings,
            'business_mode' => $scope['business_mode'],
            'branch_ids' => $scope['branch_ids'],
            'summary' => [
                'open' => (int) ($summary->open ?? 0),
                'shortages' => (int) ($summary->shortages ?? 0),
                'surpluses' => (int) ($summary->surpluses ?? 0),
                'high' => (int) ($summary->high ?? 0),
                'medium' => (int) ($summary->medium ?? 0),
                'low' => (int) ($summary->low ?? 0),
                'exposure_value' => count($exposureByCurrency) <= 1
                    ? round((float) ($summary->exposure_value ?? 0), 4) : null,
                'exposure_by_currency' => $exposureByCurrency,
                'mixed_currency' => count($exposureByCurrency) > 1,
                'oldest_started_at' => $summary->oldest_started_at ?? null,
            ],
            'variances' => $this->varianceQuery($companyId, $scope['branch_ids'])
                ->where('v.status', 'open')->orderByDesc('v.started_at')->limit(20)->get()
                ->map(fn ($row) => $this->present($row))->all(),
            'last_scan' => $lastScan,
        ]]);
    }

    public function updateSettings(
        Request $request,
        InventoryVarianceAgentService $agent,
        BusinessModeBranchScopeService $branchScope
    ): JsonResponse
    {
        $values = $request->validate([
            'enabled' => ['sometimes', 'required', 'boolean'],
            'quantity_threshold' => ['sometimes', 'required', 'numeric', 'min:0.000001', 'max:999999999'],
            'value_threshold' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999'],
            'scan_interval_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
        ]);
        if ($values === []) {
            return response()->json(['message' => 'Provide at least one setting to update.'], 422);
        }
        $companyId = (string) app('company')->id;
        $scope = $this->scope($request, $companyId, $branchScope);
        $before = $agent->settings($companyId);
        $settings = $agent->updateSettings($companyId, $values, $request->user()?->id);
        if (!$before['enabled'] && $settings['enabled']) {
            $agent->queueScan($companyId, $scope['branch_ids'], $request->user()?->id, 'enabled');
        }

        return response()->json(['message' => 'Inventory Variance Agent settings saved.', 'data' => $settings]);
    }

    public function scan(
        Request $request,
        BusinessModeBranchScopeService $branchScope,
        InventoryVarianceAgentService $agent
    ): JsonResponse {
        $companyId = (string) app('company')->id;
        $scope = $this->scope($request, $companyId, $branchScope);
        $queued = $agent->queueScan($companyId, $scope['branch_ids'], $request->user()?->id, 'manual');

        return response()->json(['data' => ['queued' => $queued]], $queued ? 202 : 200);
    }

    public function variances(
        Request $request,
        BusinessModeBranchScopeService $branchScope
    ): JsonResponse {
        $companyId = (string) app('company')->id;
        $scope = $this->scope($request, $companyId, $branchScope);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,resolved,all'],
            'direction' => ['nullable', 'in:shortage,surplus'],
            'severity' => ['nullable', 'in:low,medium,high'],
            'q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->varianceQuery($companyId, $scope['branch_ids']);
        if (($filters['status'] ?? 'open') !== 'all') {
            $query->where('v.status', $filters['status'] ?? 'open');
        }
        if (isset($filters['direction'])) {
            $query->where('v.direction', $filters['direction']);
        }
        if (isset($filters['severity'])) {
            $query->where('v.severity', $filters['severity']);
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.str_replace(['%', '_'], '', trim($filters['q'])).'%';
            $query->where(function ($search) use ($needle) {
                $search->where('p.name', 'like', $needle)
                    ->orWhere('p.code', 'like', $needle)
                    ->orWhere('p.sku', 'like', $needle)
                    ->orWhere('b.branch_name', 'like', $needle)
                    ->orWhere('b.branch_code', 'like', $needle);
            });
        }
        if (isset($filters['product_id'])) {
            $query->where('v.product_id', $filters['product_id']);
        }
        $page = $query->orderByDesc('v.started_at')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(fn ($row) => $this->present($row));

        return response()->json(['data' => $page]);
    }

    public function show(
        Request $request,
        string $varianceId,
        BusinessModeBranchScopeService $branchScope
    ): JsonResponse {
        $companyId = (string) app('company')->id;
        $scope = $this->scope($request, $companyId, $branchScope);
        $variance = $this->varianceQuery($companyId, $scope['branch_ids'])
            ->where('v.id', $varianceId)->first();
        if (!$variance) {
            return response()->json(['message' => 'Variance case not found in this workspace.'], 404);
        }

        $events = DB::table('inventory_variance_events')
            ->where('company_id', $companyId)->where('branch_id', $variance->branch_id)
            ->where('cycle_id', $variance->cycle_id)
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function ($event) {
                $event->payload = json_decode($event->payload ?: '{}', true);
                return $event;
            });

        return response()->json(['data' => [
            'variance' => $this->present($variance),
            'events' => $events,
        ]]);
    }

    private function scope(Request $request, string $companyId, BusinessModeBranchScopeService $branchScope): array
    {
        $requested = $request->input('branch_ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        if (!is_array($requested)) {
            $requested = [];
        }
        if ($request->filled('branch_id')) {
            $requested = [(string) $request->input('branch_id')];
        }
        return $branchScope->resolve($request, $companyId, $requested);
    }

    /** @param array<int, string> $branchIds */
    private function varianceQuery(string $companyId, array $branchIds)
    {
        return DB::table('inventory_variances as v')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.id', '=', 'v.product_id')->on('p.company_id', '=', 'v.company_id');
            })
            ->leftJoin('company_branches as b', function ($join) {
                $join->on('b.id', '=', 'v.branch_id')->on('b.company_id', '=', 'v.company_id');
            })
            ->where('v.company_id', $companyId)->whereIn('v.branch_id', $branchIds)
            ->select('v.*', 'p.name as product_name', 'p.code as product_code',
                'p.sku as product_sku', 'b.branch_name', 'b.branch_code');
    }

    private function present(object $variance): object
    {
        $variance->evidence = json_decode($variance->evidence ?: '{}', true);
        return $variance;
    }
}
