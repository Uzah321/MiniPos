<?php

namespace App\Http\Middleware;

use App\Services\BusinessModeBranchScopeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Product\Support\HospitalityTradingDatePolicy;

class BlockPosWhenDayEndRunning
{
    public function handle(Request $request, Closure $next)
    {
        $company = app()->bound('company') ? app('company') : ($request->attributes->get('company') ?? null);
        $till = app()->bound('till') ? app('till') : ($request->attributes->get('till') ?? null);
        $bodyBranchId = $this->normalizeBranchId($request->input('branch_id'));
        $headerBranchId = $this->normalizeBranchId($request->header('X-Branch-ID'));
        $requestedBranchId = $headerBranchId ?: $bodyBranchId;
        $tillBranchId = $this->normalizeBranchId($till?->branch_id);

        if ($tillBranchId) {
            foreach (array_filter([$bodyBranchId, $headerBranchId]) as $candidateBranchId) {
                if ($candidateBranchId !== $tillBranchId) {
                    return response()->json([
                        'message' => 'This till is registered to a different branch. The POS request cannot be redirected.',
                        'code' => 'TILL_BRANCH_MISMATCH',
                        'data' => [
                            'till_branch_id' => $tillBranchId,
                            'requested_branch_id' => $candidateBranchId,
                        ],
                    ], 403);
                }
            }

            if (
                $company
                && isset($till->company_id)
                && (string) $till->company_id !== (string) $company->id
            ) {
                return response()->json([
                    'message' => 'This till is not registered to the active company.',
                    'code' => 'TILL_COMPANY_MISMATCH',
                ], 403);
            }

            // The registered till is the physical Front Office branch anchor.
            $branchId = $tillBranchId;
        } elseif ($company) {
            $scope = app(BusinessModeBranchScopeService::class)->resolve(
                $request,
                (string) $company->id,
                $requestedBranchId ? [$requestedBranchId] : []
            );
            $branchId = $this->normalizeBranchId($scope['context_branch_id'] ?? null);
        } else {
            $branchId = null;
        }

        // A signed Android command may describe a sale completed before Day
        // End started. The controller checks its acknowledged snapshot and
        // historical day/shift, staging conflicts instead of posting into a
        // running or finalized day. Web/new sales remain blocked here.
        if ($company && $branchId && ! $request->attributes->get('mobile_sale_snapshot')) {
            $runtimeLock = Cache::store('file')->get(
                HospitalityTradingDatePolicy::runtimeLockCacheKey((string) $company->id, (string) $branchId)
            );

            if (($runtimeLock['status'] ?? null) === 'running') {
                return response()->json([
                    'message' => 'POS is blocked because Day End is running for this branch. Please wait until it is completed.',
                    'code' => 'DAY_END_RUNNING',
                    'data' => [
                        'run_key' => $runtimeLock['run_key'] ?? null,
                        'trading_date' => $runtimeLock['trading_date'] ?? null,
                    ],
                ], 423);
            }

            $locked = DB::table('trading_days')
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('status', 'day_end_running')
                ->exists();

            if ($locked) {
                return response()->json([
                    'message' => 'POS is blocked because Day End is running for this branch. Please wait until it is completed.',
                    'code' => 'DAY_END_RUNNING',
                ], 423);
            }
        }

        return $next($request);
    }

    private function normalizeBranchId(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
