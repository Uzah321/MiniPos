<?php

namespace App\Http\Middleware;

use App\Services\BusinessModeBranchScopeService;
use App\Services\CompanySettingService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Support\HospitalityTradingDatePolicy;

class BlockHospitalityInventoryWhenDayEndRunning
{
    public function handle(Request $request, Closure $next, string $branchSource = 'request')
    {
        $company = app()->bound('company')
            ? app('company')
            : ($request->attributes->get('company') ?? null);

        if (!$company?->id) {
            return response()->json([
                'message' => 'Company context could not be resolved.',
                'code' => 'COMPANY_CONTEXT_REQUIRED',
            ], 422);
        }

        $branchId = $this->resolveBranchId($request, (string) $company->id, $branchSource);
        if (!$branchId) {
            return response()->json([
                'message' => 'Select the branch whose Hospitality inventory will be changed.',
                'code' => 'WORKSPACE_BRANCH_REQUIRED',
            ], 422);
        }

        if (!app(CompanySettingService::class)->isHospitalityMode((string) $company->id, $branchId)) {
            return $next($request);
        }

        $startedDay = $this->activeTradingDaySnapshot((string) $company->id, $branchId);

        if (($startedDay?->status ?? null) === 'day_end_running'
            || $this->runtimeLock((string) $company->id, $branchId)) {
            return $this->lockedResponse($branchId);
        }

        /*
         * The transaction intentionally wraps the downstream inventory write.
         * Merely checking the file lock leaves a request-start race before Day
         * End publishes that lock. Serialising both operations on the active
         * branch trading-day row means either:
         * - the inventory write commits first and Day End reconciles it; or
         * - Day End commits first and the stale write is rejected for retry.
         * No other branch is locked.
         */
        return $this->withinTransaction(function () use ($request, $next, $company, $branchId, $startedDay) {
            $day = $this->lockActiveTradingDay((string) $company->id, $branchId);

            if (($day?->status ?? null) === 'day_end_running'
                || $this->runtimeLock((string) $company->id, $branchId)) {
                return $this->lockedResponse($branchId, $day?->trading_date ?? null);
            }

            if (!$this->isSameOpenTradingDay($startedDay, $day)) {
                return $this->tradingDayChangedResponse($branchId, $startedDay, $day);
            }

            return $next($request);
        });
    }

    protected function resolveBranchId(Request $request, string $companyId, string $branchSource): ?string
    {
        if (in_array($branchSource, ['transfer_from', 'transfer_to'], true)) {
            $column = $branchSource === 'transfer_from' ? 'from_branch_id' : 'to_branch_id';
            $transferId = $this->normalize($request->route('id'));

            if (!$transferId) {
                return null;
            }

            $branchId = $this->normalize(DB::table('stock_transfer_headers')
                ->where('company_id', $companyId)
                ->where('id', $transferId)
                ->value($column));

            if (!$branchId) {
                return null;
            }

            // Authorisation and business-mode boundary remain identical to the
            // controller's active workspace scope.
            app(BusinessModeBranchScopeService::class)->resolve($request, $companyId, [$branchId]);

            return $branchId;
        }

        $bodyBranchIds = collect([
            $request->input('branch_id'),
            $request->input('branchId'),
            $request->input('selected_branch_id'),
        ])->map(fn ($value) => $this->normalize($value))->filter()->unique()->values();
        $headerBranchId = $this->normalize($request->header('X-Branch-ID'));

        if ($bodyBranchIds->count() > 1
            || ($headerBranchId && $bodyBranchIds->isNotEmpty() && !$bodyBranchIds->contains($headerBranchId))) {
            throw new HttpResponseException(response()->json([
                'message' => 'The inventory branch does not match the active workspace branch.',
                'code' => 'BRANCH_CONTEXT_CONFLICT',
            ], 409));
        }

        $requestedBranchId = $headerBranchId ?: $bodyBranchIds->first();
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            $companyId,
            $requestedBranchId ? [$requestedBranchId] : []
        );

        return $this->normalize($scope['context_branch_id'] ?? null);
    }

    protected function activeTradingDaySnapshot(string $companyId, string $branchId): ?object
    {
        if (!Schema::hasTable('trading_days')) {
            return null;
        }

        return DB::table('trading_days')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
            ->orderByDesc('trading_date')
            ->first(['id', 'trading_date', 'status']);
    }

    protected function lockActiveTradingDay(string $companyId, string $branchId): ?object
    {
        if (!Schema::hasTable('trading_days')) {
            return null;
        }

        return DB::table('trading_days')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
            ->orderByDesc('trading_date')
            ->lockForUpdate()
            ->first(['id', 'trading_date', 'status']);
    }

    protected function withinTransaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    protected function runtimeLock(string $companyId, string $branchId): ?array
    {
        $lock = Cache::store('file')->get(
            HospitalityTradingDatePolicy::runtimeLockCacheKey($companyId, $branchId)
        );

        return is_array($lock) && ($lock['status'] ?? null) === 'running' ? $lock : null;
    }

    private function lockedResponse(string $branchId, mixed $tradingDate = null)
    {
        return response()->json([
            'message' => 'Hospitality inventory is locked while Day End reconciles this branch. Retry after Day End completes.',
            'code' => 'HOSPITALITY_DAY_END_INVENTORY_LOCKED',
            'data' => [
                'branch_id' => $branchId,
                'trading_date' => HospitalityTradingDatePolicy::normalize($tradingDate),
            ],
        ], 423);
    }

    private function isSameOpenTradingDay(?object $startedDay, ?object $lockedDay): bool
    {
        if (!$startedDay || !$lockedDay || ($lockedDay->status ?? null) !== 'open') {
            return false;
        }

        return $this->normalize($startedDay->id ?? null) === $this->normalize($lockedDay->id ?? null)
            && HospitalityTradingDatePolicy::normalize($startedDay->trading_date ?? null)
                === HospitalityTradingDatePolicy::normalize($lockedDay->trading_date ?? null);
    }

    private function tradingDayChangedResponse(
        string $branchId,
        ?object $startedDay,
        ?object $lockedDay
    ) {
        return response()->json([
            'message' => 'The branch trading day changed while this inventory request was waiting. Refresh the trading date and retry the request.',
            'code' => 'HOSPITALITY_TRADING_DAY_CHANGED_RETRY',
            'data' => [
                'branch_id' => $branchId,
                'request_trading_day_id' => $this->normalize($startedDay?->id ?? null),
                'request_trading_date' => HospitalityTradingDatePolicy::normalize($startedDay?->trading_date ?? null),
                'current_trading_day_id' => $this->normalize($lockedDay?->id ?? null),
                'current_trading_date' => HospitalityTradingDatePolicy::normalize($lockedDay?->trading_date ?? null),
            ],
        ], 409);
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
