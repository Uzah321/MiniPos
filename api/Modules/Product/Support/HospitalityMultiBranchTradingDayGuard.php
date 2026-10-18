<?php

namespace Modules\Product\Support;

use App\Services\CompanySettingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serialises a single inventory mutation that can affect several branches
 * against Hospitality Day End for every affected branch.
 *
 * Call snapshot() before opening the mutation transaction, then call
 * lockAndValidate() as the first operation inside that transaction. Retail
 * branches are deliberately omitted so their existing behaviour is unchanged.
 */
class HospitalityMultiBranchTradingDayGuard
{
    public function __construct(private readonly CompanySettingService $companySettings) {}

    /**
     * @param  iterable<int, mixed>  $branchIds
     * @return array{
     *     company_id:string,
     *     branches:array<string, array{id:?string,trading_date:?string,status:?string}>
     * }
     */
    public function snapshot(string $companyId, iterable $branchIds): array
    {
        $hospitalityBranchIds = collect($branchIds)
            ->filter(fn ($branchId) => is_scalar($branchId) && trim((string) $branchId) !== '')
            ->map(fn ($branchId) => trim((string) $branchId))
            ->unique()
            ->filter(fn (string $branchId) => $this->companySettings->isHospitalityMode($companyId, $branchId))
            ->sort(SORT_STRING)
            ->values();

        $branches = [];
        foreach ($hospitalityBranchIds as $branchId) {
            $day = $this->activeTradingDaySnapshot($companyId, $branchId);
            if (($day?->status ?? null) === 'day_end_running' || $this->runtimeLock($companyId, $branchId)) {
                $this->throwLocked($branchId, $hospitalityBranchIds->all(), $day?->trading_date ?? null);
            }

            $branches[$branchId] = [
                'id' => $this->normalize($day?->id ?? null),
                'trading_date' => HospitalityTradingDatePolicy::normalize($day?->trading_date ?? null),
                'status' => $this->normalize($day?->status ?? null),
            ];
        }

        return [
            'company_id' => $companyId,
            'branches' => $branches,
        ];
    }

    /**
     * Must be called inside the same database transaction as the inventory
     * mutation. Expected rows are locked in branch-id order to avoid two
     * multi-branch requests acquiring the same rows in opposite orders.
     *
     * @param  array{
     *     company_id:string,
     *     branches:array<string, array{id:?string,trading_date:?string,status:?string}>
     * }  $snapshot
     */
    public function lockAndValidate(array $snapshot): void
    {
        $companyId = (string) ($snapshot['company_id'] ?? '');
        $expectedByBranch = $snapshot['branches'] ?? [];
        if ($companyId === '' || $expectedByBranch === []) {
            return;
        }

        ksort($expectedByBranch, SORT_STRING);
        $lockedByBranch = $this->lockExpectedTradingDays($companyId, $expectedByBranch);
        $allBranchIds = array_keys($expectedByBranch);

        foreach ($expectedByBranch as $branchId => $expected) {
            $locked = $lockedByBranch[$branchId] ?? null;

            if (($locked?->status ?? null) === 'day_end_running' || $this->runtimeLock($companyId, $branchId)) {
                $this->throwLocked($branchId, $allBranchIds, $locked?->trading_date ?? null);
            }

            $sameOpenDay = $locked
                && ($locked->status ?? null) === 'open'
                && $this->normalize($locked->id ?? null) === ($expected['id'] ?? null)
                && HospitalityTradingDatePolicy::normalize($locked->trading_date ?? null)
                    === ($expected['trading_date'] ?? null);

            if (!$sameOpenDay) {
                $this->throwTradingDayChanged($branchId, $allBranchIds, $expected, $locked);
            }
        }
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
            ->first(['id', 'branch_id', 'trading_date', 'status']);
    }

    /**
     * @param  array<string, array{id:?string,trading_date:?string,status:?string}>  $expectedByBranch
     * @return array<string, object>
     */
    protected function lockExpectedTradingDays(string $companyId, array $expectedByBranch): array
    {
        if (!Schema::hasTable('trading_days')) {
            return [];
        }

        $locked = [];
        foreach ($expectedByBranch as $branchId => $expected) {
            $query = DB::table('trading_days')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId);

            if (!empty($expected['id'])) {
                // Lock the exact row observed at request start. If Day End won
                // the race, this row is now closed and validation rejects the
                // stale request instead of silently writing into the new day.
                $query->where('id', $expected['id']);
            } else {
                $query->whereIn('status', ['open', 'day_end_running'])
                    ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
                    ->orderByDesc('trading_date');
            }

            $row = $query->lockForUpdate()
                ->first(['id', 'branch_id', 'trading_date', 'status']);
            if ($row) {
                $locked[$branchId] = $row;
            }
        }

        return $locked;
    }

    protected function runtimeLock(string $companyId, string $branchId): ?array
    {
        $lock = Cache::store('file')->get(
            HospitalityTradingDatePolicy::runtimeLockCacheKey($companyId, $branchId)
        );

        return is_array($lock) && ($lock['status'] ?? null) === 'running' ? $lock : null;
    }

    /** @param array<int, string> $allBranchIds */
    private function throwLocked(
        string $branchId,
        array $allBranchIds,
        mixed $tradingDate = null
    ): never {
        throw new HttpResponseException(response()->json([
            'message' => 'Hospitality inventory is locked while Day End reconciles an affected branch. Retry after Day End completes.',
            'code' => 'HOSPITALITY_DAY_END_INVENTORY_LOCKED',
            'data' => [
                'branch_id' => $branchId,
                'branch_ids' => $allBranchIds,
                'trading_date' => HospitalityTradingDatePolicy::normalize($tradingDate),
            ],
        ], 423));
    }

    /**
     * @param  array<int, string>  $allBranchIds
     * @param  array{id:?string,trading_date:?string,status:?string}  $expected
     */
    private function throwTradingDayChanged(
        string $branchId,
        array $allBranchIds,
        array $expected,
        ?object $locked
    ): never {
        throw new HttpResponseException(response()->json([
            'message' => 'An affected branch trading day changed while this inventory request was waiting. Refresh the trading dates and retry the request.',
            'code' => 'HOSPITALITY_TRADING_DAY_CHANGED_RETRY',
            'data' => [
                'branch_id' => $branchId,
                'branch_ids' => $allBranchIds,
                'request_trading_day_id' => $expected['id'] ?? null,
                'request_trading_date' => $expected['trading_date'] ?? null,
                'current_trading_day_id' => $this->normalize($locked?->id ?? null),
                'current_trading_date' => HospitalityTradingDatePolicy::normalize($locked?->trading_date ?? null),
            ],
        ], 409));
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
