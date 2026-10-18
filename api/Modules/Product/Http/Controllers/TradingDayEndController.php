<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SyncLocalState;
use App\Services\BusinessModeBranchScopeService;
use App\Services\Inventory\HospitalityFrontStockReadinessService;
use App\Services\Sales\SalesCostingService;
use App\Services\Sync\AndroidDayEndReadiness;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncOrchestrator;
use App\Services\Sync\SyncRunAlreadyInProgress;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Models\FrontStockDailyClosing;
use Modules\Product\Models\FrontStockDailyClosingLine;
use Modules\Product\Models\DailyStockReconciliationReport;
use Modules\Product\Models\TradingDay;
use Modules\Product\Models\TradingDayEnd;
use Modules\Product\Support\HospitalityTradingDatePolicy;
use Throwable;

class TradingDayEndController extends Controller
{
    private const FRONT_STOCK_AREA = 'front';

    private const ALLOWED_FRONT_STOCK_CLOSING_PRODUCT_TYPES = [
        'raw_material',
        'raw material',
        'semi_finished_good',
        'semi finished good',
        'semi-finished good',
        'semi_finished',
        'semi finished',
        'consumable',
        'consumables',
        'packaging',
    ];

    public function current(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        $day = $this->currentOrCreateOpenTradingDay($company->id, $branchId);

        return response()->json([
            'data' => $day
                ? $this->tradingDayPayload($day)
                : $this->awaitingNextTradingDayPayload($company->id, $branchId),
        ]);
    }

    /**
     * Enterprise preflight endpoint for Day End.
     * It tells the cashier/manager exactly which front stock closing dates are missing.
     */
    public function preflight(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        $day = $this->currentOrCreateOpenTradingDay($company->id, $branchId);

        if (!$day) {
            $catchUp = $this->catchUpDetails(null, HospitalityTradingDatePolicy::systemDate(), null);

            return response()->json([
                'message' => 'No open Hospitality trading date is available yet.',
                'code' => 'AWAITING_NEXT_SYSTEM_DATE',
                'data' => array_merge($this->awaitingNextTradingDayPayload($company->id, $branchId), [
                    'can_run_day_end' => false,
                    'blocking_front_stock_closings' => [],
                    'missing_front_stock_closings' => [],
                    'front_stock_closing_calendar' => [],
                    'expected_trading_date' => null,
                    'operational_readiness' => $this->emptyDayEndOperationalReadiness(),
                    'catch_up' => $catchUp,
                    'catch_up_available' => false,
                    'lag_days' => 0,
                ]),
            ], 409);
        }

        $tradingDate = $this->tradingDateString($day);
        $systemDate = HospitalityTradingDatePolicy::systemDate();
        $dateState = HospitalityTradingDatePolicy::state($tradingDate, $day->status, $systemDate);

        if (!$tradingDate || $dateState['trading_date_is_future']) {
            $catchUp = $this->catchUpDetails($tradingDate, $systemDate, $day->status);

            return response()->json([
                'message' => $dateState['sale_block_reason'] ?: 'The active Hospitality trading date is invalid.',
                'code' => $dateState['trading_date_is_future']
                    ? 'FUTURE_TRADING_DATE'
                    : 'INVALID_TRADING_DATE',
                'data' => array_merge($this->tradingDayPayload($day), [
                    'can_run_day_end' => false,
                    'blocking_front_stock_closings' => [],
                    'missing_front_stock_closings' => [],
                    'front_stock_closing_calendar' => [],
                    'expected_trading_date' => $tradingDate,
                    'operational_readiness' => $this->emptyDayEndOperationalReadiness([
                        $dateState['sale_block_reason'] ?: 'The active Hospitality trading date is invalid.',
                    ]),
                    'catch_up' => $catchUp,
                    'catch_up_available' => false,
                    'lag_days' => $catchUp['lag_days'],
                ]),
            ], 422);
        }

        $dayPayload = $this->tradingDayPayload($day);
        if ($dayPayload['has_duplicate_open_trading_days'] ?? false) {
            $catchUp = array_merge(
                $this->catchUpDetails($tradingDate, $systemDate, $day->status),
                ['available' => false]
            );

            return response()->json([
                'message' => $dayPayload['block_reason'],
                'code' => 'DUPLICATE_OPEN_TRADING_DAYS',
                'data' => array_merge($dayPayload, [
                    'can_run_day_end' => false,
                    'blocking_front_stock_closings' => [],
                    'missing_front_stock_closings' => [],
                    'front_stock_closing_calendar' => [],
                    'expected_trading_date' => $tradingDate,
                    'operational_readiness' => $this->emptyDayEndOperationalReadiness([
                        $dayPayload['block_reason'],
                    ]),
                    'catch_up' => $catchUp,
                    'catch_up_available' => false,
                    'lag_days' => $catchUp['lag_days'],
                ]),
            ], 409);
        }

        $requestedDateTo = HospitalityTradingDatePolicy::normalize($request->input('date_to')) ?: $systemDate;
        $dateTo = min($requestedDateTo, $systemDate);

        if (Carbon::parse($dateTo)->lt(Carbon::parse($tradingDate))) {
            $dateTo = $tradingDate;
        }

        $closingRows = $this->frontStockClosingCalendar(
            companyId: $company->id,
            branchId: $branchId,
            dateFrom: $tradingDate,
            dateTo: $dateTo
        );

        $blockingRows = collect($closingRows)
            ->where('date', $tradingDate)
            ->where('is_closed', false)
            ->values()
            ->all();

        $missingRows = collect($closingRows)
            ->where('status', 'missing')
            ->values()
            ->all();
        $staleRows = collect($closingRows)
            ->where('status', 'stale_reconciliation')
            ->values()
            ->all();
        $operationalReadiness = $this->dayEndOperationalReadiness(
            companyId: $company->id,
            branchId: $branchId,
            day: $day,
            tradingDate: $tradingDate
        );
        $frontStockIssueReadiness = app(HospitalityFrontStockReadinessService::class)->assess(
            (string) $company->id,
            $branchId,
            $tradingDate
        );
        $androidReadiness = app(AndroidDayEndReadiness::class)->assess((string) $company->id, $branchId);
        $canRunDayEnd = count($blockingRows) === 0
            && (bool) $dateState['can_run_day_end']
            && (bool) $operationalReadiness['can_run_day_end']
            && (bool) $frontStockIssueReadiness['can_run_day_end']
            && (bool) $androidReadiness['can_run_day_end'];
        $catchUp = $this->catchUpDetails($tradingDate, $systemDate, $day->status);

        $frontStockBlockCode = collect($blockingRows)->contains(
            fn (array $row): bool => ($row['status'] ?? null) === 'stale_reconciliation'
        ) ? 'FRONT_STOCK_CLOSING_STALE' : 'FRONT_STOCK_CLOSING_REQUIRED';
        $enterpriseMessage = $day->status === 'day_end_running'
            ? 'Day End is already running for this trading date.'
            : (count($blockingRows) > 0
                ? ($frontStockBlockCode === 'FRONT_STOCK_CLOSING_STALE'
                    ? 'Day End is blocked because FRONT stock changed after the saved branch reconciliation. Recount FRONT stock after all selling and issuing has stopped.'
                    : 'Day End is blocked because this branch has no saved FRONT stock reconciliation for the open trading date.')
                : (! $androidReadiness['can_run_day_end']
                    ? 'Day End is blocked by Android devices with pending or unverified offline work. Sync each named device, or review its lifecycle in Device Management.'
                : (!($frontStockIssueReadiness['can_run_day_end'] ?? false)
                    ? implode(' ', $frontStockIssueReadiness['blocking_reasons'] ?? [])
                    : (!($operationalReadiness['can_run_day_end'] ?? false)
                        ? implode(' ', $operationalReadiness['blocking_reasons'] ?? [])
                        : 'Day End can proceed for the open trading day.'))));

        return response()->json([
            'code' => count($blockingRows) > 0
                ? $frontStockBlockCode
                : ($androidReadiness['can_run_day_end'] ? null : 'DAY_END_ANDROID_SYNC_REQUIRED'),
            'data' => [
                'open_trading_day' => $this->tradingDayPayload($day),
                'branch_id' => $branchId,
                'date_from' => $tradingDate,
                'date_to' => $dateTo,
                'system_date' => $systemDate,
                'expected_trading_date' => $tradingDate,
                'can_run_day_end' => $canRunDayEnd,
                'blocking_front_stock_closings' => $blockingRows,
                'missing_front_stock_closings' => $missingRows,
                'stale_front_stock_closings' => $staleRows,
                'front_stock_closing_calendar' => $closingRows,
                'operational_readiness' => $operationalReadiness,
                'android_readiness' => $androidReadiness,
                'blocking_android_devices' => $androidReadiness['blocking_devices'],
                'front_stock_issue_readiness' => $frontStockIssueReadiness,
                'blocking_front_stock_issues' => $frontStockIssueReadiness['blocking_front_stock_issues'],
                'blocking_open_shifts' => $operationalReadiness['blocking_open_shifts'],
                'uncashed_sale_count' => $operationalReadiness['uncashed_sale_count'],
                'catch_up' => $catchUp,
                'catch_up_available' => $catchUp['available'],
                'lag_days' => $catchUp['lag_days'],
                'enterprise_message' => $enterpriseMessage,
            ],
        ]);
    }

    public function catchUpToSystemDate(
        Request $request,
        MenuRecipeDailyStockReconciliationController $reconciliation
    ): JsonResponse {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        if ($authority = $this->edgeDayEndAuthorityResponse((string) $company->id, $branchId)) {
            return $authority;
        }

        $validator = Validator::make($request->all(), [
            'run_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'expected_trading_date' => ['required', 'date_format:Y-m-d'],
            'expected_system_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmation_token' => ['required', 'in:CATCH_UP_TO_SYSTEM_DATE'],
            'allow_without_manual_closing' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $systemDate = HospitalityTradingDatePolicy::systemDate();
        $expectedSystemDate = HospitalityTradingDatePolicy::normalize(
            $request->input('expected_system_date')
        );
        $expectedTradingDate = HospitalityTradingDatePolicy::normalize(
            $request->input('expected_trading_date')
        );
        $runKey = (string) $request->input('run_key');
        $catchUp = $this->catchUpDetails($expectedTradingDate, $systemDate, 'open');

        // A completed idempotent replay is immutable and must still be
        // retrievable after midnight. The original persisted target/date wins;
        // stale system-date validation applies only to a new or incomplete run.
        $existingRun = TradingDayEnd::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('lock_token', $runKey)
            ->first();

        if ($existingRun) {
            if ($this->operationTypeForRun($existingRun) !== 'catch_up') {
                return $this->idempotencyOperationMismatchResponse(
                    $company->id,
                    $branchId,
                    $runKey,
                    'catch_up',
                    $this->operationTypeForRun($existingRun)
                );
            }

            if (HospitalityTradingDatePolicy::normalize($existingRun->trading_date) !== $expectedTradingDate) {
                return $this->staleTradingDateResponse(
                    companyId: $company->id,
                    branchId: $branchId,
                    runKey: $runKey,
                    expectedTradingDate: $expectedTradingDate,
                    observedTradingDate: null,
                    authoritativeTradingDate: HospitalityTradingDatePolicy::normalize($existingRun->trading_date),
                    currentDay: $this->activeOpenTradingDay($company->id, $branchId),
                    message: 'The supplied Catch Up run key belongs to a different trading date.'
                );
            }

            if (in_array($existingRun->status, ['complete', 'completed'], true)) {
                $this->publishCompletedDayEndSnapshot((string) $company->id, $branchId);

                return $this->completedDayEndResponse($existingRun, $runKey, true);
            }
        }

        if ($expectedSystemDate !== $systemDate) {
            $this->writeDayEndProgress(
                (string) $company->id,
                $branchId,
                $runKey,
                'blocked',
                'System date changed; refresh Catch Up',
                100,
                'blocked',
                [],
                $this->catchUpOperationPayload($catchUp)
            );

            return response()->json([
                'message' => 'The Africa/Harare system date changed after confirmation. Refresh Day End and confirm Catch Up again.',
                'code' => 'STALE_SYSTEM_DATE',
                'data' => array_merge($this->catchUpOperationPayload($catchUp), [
                    'expected_system_date' => $expectedSystemDate,
                    'system_date' => $systemDate,
                    'refresh_required' => true,
                    'can_run_day_end' => false,
                ]),
            ], 409);
        }

        if (!$catchUp['available']) {
            return response()->json([
                'message' => 'Catch Up is available only when the confirmed Hospitality trading date is earlier than the Africa/Harare system date.',
                'code' => 'CATCH_UP_NOT_AVAILABLE',
                'data' => array_merge($this->catchUpOperationPayload($catchUp), [
                    'can_run_day_end' => false,
                ]),
            ], 409);
        }

        $request->attributes->set('hospitality_day_end_operation', [
            'operation_type' => 'catch_up',
            'expected_system_date' => $expectedSystemDate,
            'reason' => trim((string) $request->input('reason')),
            'confirmation_token_verified' => true,
        ]);
        // Seed operation metadata before the synchronous run starts. Every
        // later progress write preserves these fields, including blocked and
        // failed terminal states.
        $this->writeDayEndProgress(
            (string) $company->id,
            $branchId,
            $runKey,
            'queued',
            'Preparing Catch Up to system date',
            3,
            'running',
            [],
            $this->catchUpOperationPayload($catchUp)
        );

        return $this->runDayEnd($request, $reconciliation);
    }

    public function runDayEnd(Request $request, MenuRecipeDailyStockReconciliationController $reconciliation): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $userId = auth()->id();

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $requestedRunKey = $request->input('run_key') ?: $request->header('Idempotency-Key');
        $operationRequest = $request->attributes->get('hospitality_day_end_operation');
        $isCatchUp = is_array($operationRequest)
            && ($operationRequest['operation_type'] ?? null) === 'catch_up';
        $validator = Validator::make(array_merge($request->all(), [
            'run_key' => $requestedRunKey,
        ]), [
            'run_key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'expected_trading_date' => ['required', 'date_format:Y-m-d'],
            'allow_without_manual_closing' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $expectedTradingDate = HospitalityTradingDatePolicy::normalize(
            $request->input('expected_trading_date')
        );
        $daySnapshot = $this->activeOpenTradingDay($company->id, $branchId);
        $observedTradingDate = $this->tradingDateString($daySnapshot);
        $runKey = (string) ($requestedRunKey ?: (
            $expectedTradingDate
                ? 'auto:' . sha1($company->id . '|' . $branchId . '|' . $expectedTradingDate)
                : Str::uuid()
        ));
        $failedTradingDate = null;

        if (config('salexa_sync.deployment_mode') === 'edge') {
            return $this->runAuthoritativeEdgeDayEnd(
                $request,
                (string) $company->id,
                $branchId,
                $runKey,
                (string) $expectedTradingDate,
                $userId ? (string) $userId : null
            );
        }

        // Resolve already-committed idempotency records before opening the
        // atomic Day End transaction. This keeps the first read inside that
        // transaction a locking read of the trading-day row, so any sale that
        // committed while Day End was waiting is visible to the readiness
        // recheck below under MySQL REPEATABLE READ.
        $existingRun = TradingDayEnd::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('lock_token', $runKey)
            ->first();

        $requestedOperationType = $isCatchUp ? 'catch_up' : 'standard';
        if ($existingRun && $this->operationTypeForRun($existingRun) !== $requestedOperationType) {
            return $this->idempotencyOperationMismatchResponse(
                $company->id,
                $branchId,
                $runKey,
                $requestedOperationType,
                $this->operationTypeForRun($existingRun)
            );
        }

        if (
            $existingRun
            && HospitalityTradingDatePolicy::normalize($existingRun->trading_date) !== $expectedTradingDate
        ) {
            return $this->staleTradingDateResponse(
                companyId: $company->id,
                branchId: $branchId,
                runKey: $runKey,
                expectedTradingDate: $expectedTradingDate,
                observedTradingDate: $observedTradingDate,
                authoritativeTradingDate: HospitalityTradingDatePolicy::normalize($existingRun->trading_date),
                currentDay: $daySnapshot,
                message: 'The supplied Day End run key belongs to a different trading date.'
            );
        }

        if ($existingRun && in_array($existingRun->status, ['complete', 'completed'], true)) {
            $this->publishCompletedDayEndSnapshot((string) $company->id, $branchId);

            return $this->completedDayEndResponse($existingRun, $runKey, true);
        }

        if ($existingRun && $existingRun->status === 'running') {
            $this->writeDayEndProgress(
                $company->id,
                $branchId,
                $runKey,
                'running',
                'Day End is already running',
                20,
                'running',
                [],
                $this->operationPayloadForRun($existingRun)
            );

            return response()->json([
                'message' => 'Day End is already running for this request.',
                'data' => $this->dayEndRunPayload($existingRun, $runKey, true),
            ], 202);
        }

        $this->writeDayEndProgress(
            $company->id,
            $branchId,
            $runKey,
            'queued',
            'Preparing Day End',
            3
        );

        try {
            $response = DB::transaction(function () use (
                $company,
                $branchId,
                $userId,
                $reconciliation,
                $request,
                $runKey,
                $expectedTradingDate,
                $observedTradingDate,
                $operationRequest,
                $isCatchUp,
                $requestedOperationType,
                &$failedTradingDate
            ) {
                $this->writeDayEndProgress(
                    $company->id,
                    $branchId,
                    $runKey,
                    'preflight',
                    'Checking trading date and stock controls',
                    10
                );

                $day = $this->activeOpenTradingDayForUpdate($company->id, $branchId);

                if (!$day) {
                    $committedRun = TradingDayEnd::query()
                        ->where('company_id', $company->id)
                        ->where('branch_id', $branchId)
                        ->where('lock_token', $runKey)
                        ->lockForUpdate()
                        ->first();

                    if ($committedRun && $this->operationTypeForRun($committedRun) !== $requestedOperationType) {
                        return $this->idempotencyOperationMismatchResponse(
                            $company->id,
                            $branchId,
                            $runKey,
                            $requestedOperationType,
                            $this->operationTypeForRun($committedRun)
                        );
                    }

                    if ($committedRun && in_array($committedRun->status, ['complete', 'completed'], true)) {
                        if (HospitalityTradingDatePolicy::normalize($committedRun->trading_date) === $expectedTradingDate) {
                            return $this->completedDayEndResponse($committedRun, $runKey, true);
                        }
                    }

                    if ($committedRun && $committedRun->status === 'running') {
                        if (HospitalityTradingDatePolicy::normalize($committedRun->trading_date) !== $expectedTradingDate) {
                            return $this->staleTradingDateResponse(
                                companyId: $company->id,
                                branchId: $branchId,
                                runKey: $runKey,
                                expectedTradingDate: $expectedTradingDate,
                                observedTradingDate: $observedTradingDate,
                                authoritativeTradingDate: HospitalityTradingDatePolicy::normalize($committedRun->trading_date),
                                currentDay: null,
                                message: 'The supplied Day End run key belongs to a different trading date.'
                            );
                        }

                        $this->writeDayEndProgress(
                            $company->id,
                            $branchId,
                            $runKey,
                            'running',
                            'Day End is already running',
                            20,
                            'running',
                            [],
                            $this->operationPayloadForRun($committedRun)
                        );

                        return response()->json([
                            'message' => 'Day End is already running for this request.',
                            'data' => $this->dayEndRunPayload($committedRun, $runKey, true),
                        ], 202);
                    }

                    return $this->staleTradingDateResponse(
                        companyId: $company->id,
                        branchId: $branchId,
                        runKey: $runKey,
                        expectedTradingDate: $expectedTradingDate,
                        observedTradingDate: $observedTradingDate,
                        authoritativeTradingDate: null,
                        currentDay: null,
                        message: 'The trading date selected for Day End is no longer open. Refresh Day End before continuing.'
                    );
                }

                // The active day may now be the next lagging day opened by a
                // concurrent request. Re-read the supplied idempotency key
                // after acquiring the day serialization lock so a same-key
                // retry replays D1 instead of being mistaken for a stale D2
                // request.
                $serializedRunByKey = TradingDayEnd::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('lock_token', $runKey)
                    ->first();

                if ($serializedRunByKey) {
                    if ($this->operationTypeForRun($serializedRunByKey) !== $requestedOperationType) {
                        return $this->idempotencyOperationMismatchResponse(
                            $company->id,
                            $branchId,
                            $runKey,
                            $requestedOperationType,
                            $this->operationTypeForRun($serializedRunByKey)
                        );
                    }

                    $serializedRunDate = HospitalityTradingDatePolicy::normalize(
                        $serializedRunByKey->trading_date
                    );

                    if ($serializedRunDate !== $expectedTradingDate) {
                        return $this->staleTradingDateResponse(
                            companyId: $company->id,
                            branchId: $branchId,
                            runKey: $runKey,
                            expectedTradingDate: $expectedTradingDate,
                            observedTradingDate: $observedTradingDate,
                            authoritativeTradingDate: $serializedRunDate,
                            currentDay: $day,
                            message: 'The supplied Day End run key belongs to a different trading date.'
                        );
                    }

                    if (in_array($serializedRunByKey->status, ['complete', 'completed'], true)) {
                        return $this->completedDayEndResponse($serializedRunByKey, $runKey, true);
                    }

                    if ($serializedRunByKey->status === 'running') {
                        return response()->json([
                            'message' => 'Day End is already running for this request.',
                            'data' => $this->dayEndRunPayload($serializedRunByKey, $runKey, true),
                        ], 202);
                    }
                }

                $tradingDate = $this->tradingDateString($day);
                $failedTradingDate = $tradingDate;
                $dateState = HospitalityTradingDatePolicy::state($tradingDate, $day->status);
                $systemDateAtLock = HospitalityTradingDatePolicy::systemDate();
                $catchUp = $this->catchUpDetails($tradingDate, $systemDateAtLock, $day->status);
                $operationPayload = $isCatchUp
                    ? $this->catchUpOperationPayload($catchUp)
                    : ['operation_type' => 'standard'];

                if (
                    $tradingDate !== $expectedTradingDate
                    || $tradingDate !== $observedTradingDate
                ) {
                    return $this->staleTradingDateResponse(
                        companyId: $company->id,
                        branchId: $branchId,
                        runKey: $runKey,
                        expectedTradingDate: $expectedTradingDate,
                        observedTradingDate: $observedTradingDate,
                        authoritativeTradingDate: $tradingDate,
                        currentDay: $day,
                        message: 'The authoritative trading date changed while Day End was waiting. Refresh Day End and confirm the new date.'
                    );
                }

                $dayDiagnostics = $this->openDayDiagnostics($day);
                if ($dayDiagnostics['has_duplicate_open_trading_days']) {
                    $message = 'Multiple open Hospitality trading dates exist for this branch. Resolve the duplicate records before running Day End.';
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        $message,
                        100,
                        'blocked',
                        $dayDiagnostics['open_trading_days']
                    );

                    return response()->json([
                        'message' => $message,
                        'code' => 'DUPLICATE_OPEN_TRADING_DAYS',
                        'data' => array_merge($operationPayload, $this->tradingDayPayload($day), [
                            'run_key' => $runKey,
                            'can_run_day_end' => false,
                        ]),
                    ], 409);
                }

                if (!$tradingDate || $dateState['trading_date_is_future']) {
                    $message = $dateState['sale_block_reason']
                        ?: 'The active Hospitality trading date is missing or invalid.';

                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        $message,
                        100,
                        'blocked'
                    );

                    return response()->json([
                        'message' => $message,
                        'code' => $dateState['trading_date_is_future']
                            ? 'FUTURE_TRADING_DATE'
                            : 'INVALID_TRADING_DATE',
                        'data' => array_merge($operationPayload, $this->tradingDayPayload($day), [
                            'run_key' => $runKey,
                            'can_run_day_end' => false,
                        ]),
                    ], 422);
                }

                if ($isCatchUp) {
                    $confirmedSystemDate = HospitalityTradingDatePolicy::normalize(
                        $operationRequest['expected_system_date'] ?? null
                    );

                    if ($confirmedSystemDate !== $systemDateAtLock) {
                        $message = 'The Africa/Harare system date changed while Catch Up was waiting. Refresh Day End and confirm again.';
                        $this->writeDayEndProgress(
                            $company->id,
                            $branchId,
                            $runKey,
                            'blocked',
                            'System date changed; refresh Catch Up',
                            100,
                            'blocked',
                            [],
                            $operationPayload
                        );

                        return response()->json([
                            'message' => $message,
                            'code' => 'STALE_SYSTEM_DATE',
                            'data' => array_merge($operationPayload, [
                                'expected_system_date' => $confirmedSystemDate,
                                'system_date' => $systemDateAtLock,
                                'refresh_required' => true,
                                'can_run_day_end' => false,
                            ]),
                        ], 409);
                    }

                    if (!$catchUp['available']) {
                        $message = $day->status === 'day_end_running'
                            ? 'Catch Up refused because Day End is already running for this branch.'
                            : 'Catch Up is available only when the open Hospitality trading date is earlier than the Africa/Harare system date.';
                        $this->writeDayEndProgress(
                            $company->id,
                            $branchId,
                            $runKey,
                            'blocked',
                            'Catch Up is not available',
                            100,
                            'blocked',
                            [],
                            $operationPayload
                        );

                        return response()->json([
                            'message' => $message,
                            'code' => 'CATCH_UP_NOT_AVAILABLE',
                            'data' => array_merge($operationPayload, [
                                'can_run_day_end' => false,
                            ]),
                        ], 409);
                    }
                }

                $androidReadiness = app(AndroidDayEndReadiness::class)->assess((string) $company->id, $branchId, true);
                if (! $androidReadiness['can_run_day_end']) {
                    return response()->json([
                        'message' => 'Day End is blocked by Android devices with pending or unverified offline work. Sync each named device, or review its lifecycle in Device Management.',
                        'code' => 'DAY_END_ANDROID_SYNC_REQUIRED',
                        'data' => array_merge($operationPayload, [
                            'can_run_day_end' => false,
                            'expected_trading_date' => $expectedTradingDate,
                            'blocking_android_devices' => $androidReadiness['blocking_devices'],
                        ]),
                    ], 409);
                }

                $run = TradingDayEnd::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->whereDate('trading_date', $tradingDate)
                    ->lockForUpdate()
                    ->first();

                if ($day->status === 'day_end_running' && !$run) {
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        'A legacy Day End lock requires review',
                        100,
                        'blocked'
                    );

                    return response()->json([
                        'message' => 'This trading date has a Day End lock but no matching run record. Review the lock or use the authorised rollback action before retrying.',
                        'code' => 'ORPHANED_DAY_END_LOCK',
                        'data' => array_merge($this->tradingDayPayload($day), [
                            'run_key' => $runKey,
                            'can_run_day_end' => false,
                        ]),
                    ], 409);
                }

                if ($run && in_array($run->status, ['complete', 'completed'], true)) {
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'complete',
                        'Day End already completed',
                        100,
                        'complete'
                    );

                    return $this->completedDayEndResponse($run, $runKey, true);
                }

                if ($run && $run->status === 'running') {
                    $activeRunKey = (string) ($run->lock_token ?: $runKey);
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'running',
                        'Day End is already running',
                        20,
                        'running',
                        [['active_run_key' => $activeRunKey]],
                        $this->operationPayloadForRun($run)
                    );

                    return response()->json([
                        'message' => 'Day End is already running for this trading date.',
                        'data' => $this->dayEndRunPayload($run, $activeRunKey, true),
                    ], 202);
                }

                if (!$run) {
                    $run = TradingDayEnd::create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branchId,
                        'trading_date' => $tradingDate,
                        'status' => 'running',
                        'started_by' => $userId,
                        'started_at' => now(),
                        'lock_token' => $runKey,
                        'lock_started_at' => now(),
                        'metadata' => [
                            'source' => 'hospitality_day_end',
                            ...$this->syncDayEndOriginMetadata($request),
                            'operation_type' => $isCatchUp ? 'catch_up' : 'standard',
                            'expected_trading_date' => $expectedTradingDate,
                            'observed_trading_date' => $observedTradingDate,
                            'system_date_at_start' => HospitalityTradingDatePolicy::systemDate(),
                            'progress_stages' => $this->dayEndProgressStages(),
                            'catch_up' => $isCatchUp ? array_merge($catchUp, [
                                'reason' => $operationRequest['reason'],
                                'confirmation_token_verified' => true,
                            ]) : null,
                        ],
                    ]);
                } else {
                    $run->update([
                        'status' => 'running',
                        'started_by' => $userId,
                        'started_at' => now(),
                        'completed_by' => null,
                        'completed_at' => null,
                        'failed_at' => null,
                        'failure_reason' => null,
                        'lock_token' => $runKey,
                        'lock_started_at' => now(),
                        'lock_released_at' => null,
                        'metadata' => array_merge($this->metadataArray($run->metadata), [
                            'retried_at' => now()->toISOString(),
                            ...$this->syncDayEndOriginMetadata($request),
                            'expected_trading_date' => $expectedTradingDate,
                            'observed_trading_date' => $observedTradingDate,
                            'system_date_at_start' => HospitalityTradingDatePolicy::systemDate(),
                            'operation_type' => $isCatchUp ? 'catch_up' : 'standard',
                            'catch_up' => $isCatchUp ? array_merge($catchUp, [
                                'reason' => $operationRequest['reason'],
                                'confirmation_token_verified' => true,
                            ]) : null,
                        ]),
                    ]);
                }

                // Recheck shifts and cashups only after the authoritative
                // trading-day row is locked. A sale that started first must
                // commit before this point and is therefore included; a sale
                // that starts later waits on this same trading-day lock.
                $operationalReadiness = $this->dayEndOperationalReadiness(
                    companyId: $company->id,
                    branchId: $branchId,
                    day: $day,
                    tradingDate: $tradingDate
                );

                if (!($operationalReadiness['can_run_day_end'] ?? false)) {
                    $blockingReasons = $operationalReadiness['blocking_reasons'] ?? [];
                    $message = $blockingReasons !== []
                        ? implode(' ', $blockingReasons)
                        : 'Day End is blocked until every branch shift is closed and every transaction is cashed up.';

                    $run->update([
                        'status' => 'blocked',
                        'failed_at' => now(),
                        'failure_reason' => $message,
                        'lock_released_at' => now(),
                        'metadata' => array_merge($this->metadataArray($run->metadata), [
                            'operational_readiness' => $operationalReadiness,
                        ]),
                    ]);

                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        'Close shifts and complete cashups',
                        100,
                        'blocked',
                        $blockingReasons
                    );

                    return response()->json([
                        'message' => $message,
                        'code' => 'DAY_END_SHIFT_CASHUP_REQUIRED',
                        'data' => array_merge($operationPayload, [
                            'run_key' => $runKey,
                            'day_end_run_id' => $run->id,
                            'expected_trading_date' => $expectedTradingDate,
                            'open_trading_day' => $this->tradingDayPayload($day),
                            'can_run_day_end' => false,
                            'operational_readiness' => $operationalReadiness,
                            'blocking_open_shifts' => $operationalReadiness['blocking_open_shifts'] ?? [],
                            'uncashed_sale_count' => $operationalReadiness['uncashed_sale_count'] ?? 0,
                            'suggested_action' => 'Cash up and close every branch shift, then refresh Day End and retry for the same trading date.',
                        ]),
                    ], 409);
                }

                $frontStockIssueReadiness = app(HospitalityFrontStockReadinessService::class)->assess(
                    (string) $company->id,
                    $branchId,
                    $tradingDate
                );

                if (!($frontStockIssueReadiness['can_run_day_end'] ?? false)) {
                    $blockingReasons = $frontStockIssueReadiness['blocking_reasons'] ?? [];
                    $message = $blockingReasons !== []
                        ? implode(' ', $blockingReasons)
                        : 'Day End is blocked until negative FRONT stock is issued from BOH and reconciled.';

                    $run->update([
                        'status' => 'blocked',
                        'failed_at' => now(),
                        'failure_reason' => $message,
                        'lock_released_at' => now(),
                        'metadata' => array_merge($this->metadataArray($run->metadata), [
                            'front_stock_issue_readiness' => $frontStockIssueReadiness,
                        ]),
                    ]);

                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        'Issue negative stock from BOH to FRONT',
                        100,
                        'blocked',
                        $frontStockIssueReadiness['blocking_front_stock_issues'] ?? []
                    );

                    return response()->json([
                        'message' => $message,
                        'code' => 'FRONT_STOCK_ISSUE_REQUIRED',
                        'data' => array_merge($operationPayload, [
                            'run_key' => $runKey,
                            'day_end_run_id' => $run->id,
                            'expected_trading_date' => $expectedTradingDate,
                            'open_trading_day' => $this->tradingDayPayload($day),
                            'can_run_day_end' => false,
                            'front_stock_issue_readiness' => $frontStockIssueReadiness,
                            'blocking_front_stock_issues' => $frontStockIssueReadiness['blocking_front_stock_issues'] ?? [],
                            'suggested_action' => 'Issue the listed ingredients from BOH to FRONT, complete Front Stock Reconciliation, then retry Day End.',
                        ]),
                    ], 409);
                }

                $manualClosingExists = $this->frontStockClosingExists($company->id, $branchId, $tradingDate);

                if (!$manualClosingExists) {
                    $calendar = $this->frontStockClosingCalendar(
                        companyId: $company->id,
                        branchId: $branchId,
                        dateFrom: $tradingDate,
                        dateTo: HospitalityTradingDatePolicy::systemDate()
                    );
                    $blocking = collect($calendar)
                        ->where('date', $tradingDate)
                        ->where('is_closed', false)
                        ->values()
                        ->all();
                    $closingBlockCode = collect($blocking)->contains(
                        fn (array $row): bool => ($row['status'] ?? null) === 'stale_reconciliation'
                    ) ? 'FRONT_STOCK_CLOSING_STALE' : 'FRONT_STOCK_CLOSING_REQUIRED';
                    $closingBlockMessage = $closingBlockCode === 'FRONT_STOCK_CLOSING_STALE'
                        ? 'FRONT stock changed after the saved branch reconciliation. Recount FRONT stock before Day End.'
                        : 'Daily branch FRONT stock reconciliation is required before Day End.';

                    $run->update([
                        'status' => 'blocked',
                        'failed_at' => now(),
                        'failure_reason' => $closingBlockMessage,
                        'lock_released_at' => now(),
                        'metadata' => array_merge($this->metadataArray($run->metadata), [
                            'blocking_front_stock_closings' => $blocking,
                        ]),
                    ]);

                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'blocked',
                        'Daily front stock reconciliation is required',
                        100,
                        'blocked',
                        $blocking
                    );

                    return response()->json([
                        'message' => $closingBlockMessage,
                        'code' => $closingBlockCode,
                        'blocking_date' => $tradingDate,
                        'data' => array_merge($operationPayload, [
                            'run_key' => $runKey,
                            'day_end_run_id' => $run->id,
                            'expected_trading_date' => $expectedTradingDate,
                            'open_trading_day' => $this->tradingDayPayload($day),
                            'can_run_day_end' => false,
                            'blocking_front_stock_closings' => $blocking,
                            'missing_front_stock_closings' => collect($calendar)
                                ->where('status', 'missing')
                                ->values()
                                ->all(),
                            'stale_front_stock_closings' => collect($calendar)
                                ->where('status', 'stale_reconciliation')
                                ->values()
                                ->all(),
                            'suggested_action' => $closingBlockCode === 'FRONT_STOCK_CLOSING_STALE'
                                ? 'Stop branch stock activity, recount and replace the FRONT Stock Reconciliation for this trading date, then retry Day End.'
                                : 'Complete and save the branch FRONT Stock Reconciliation for this trading date, wait for both sync indicators to turn green, then retry Day End.',
                        ]),
                    ], 422);
                }

                $this->writeDayEndProgress(
                    $company->id,
                    $branchId,
                    $runKey,
                    'lock',
                    'Locking sales for the trading date',
                    25
                );

                // The normal cache store is database-backed in production and
                // therefore participates in this transaction.  This dedicated
                // file-backed runtime lock is visible to concurrent POS requests
                // immediately, while the atomic Day End transaction is running.
                $this->acquireDayEndRuntimeLock(
                    $company->id,
                    $branchId,
                    $runKey,
                    $tradingDate
                );

                $day->update([
                    'status' => 'day_end_running',
                    'day_end_started_at' => now(),
                    'day_end_started_by' => $userId,
                    'lock_token' => $runKey,
                ]);

                $this->writeDayEndProgress(
                    $company->id,
                    $branchId,
                    $runKey,
                    'reconciliation',
                    'Generating Daily Stock Reconciliation',
                    55
                );

                $report = $reconciliation->finalizeForTradingDay($company->id, $branchId, $day, $userId);

                if ($isCatchUp) {
                    $report->update([
                        'metadata' => array_merge($this->metadataArray($report->metadata), [
                            'operation_type' => 'catch_up',
                            'catch_up' => array_merge($catchUp, [
                                'reason' => $operationRequest['reason'],
                                'confirmed_by' => $userId,
                            ]),
                        ]),
                    ]);
                }

                $this->writeDayEndProgress(
                    $company->id,
                    $branchId,
                    $runKey,
                    'close',
                    'Closing the trading date',
                    78
                );

                $day->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'metadata' => array_merge($this->metadataArray($day->metadata), $isCatchUp ? [
                        'closed_by_operation' => 'catch_up',
                        'catch_up' => array_merge($catchUp, [
                            'reason' => $operationRequest['reason'],
                            'confirmed_by' => $userId,
                        ]),
                    ] : []),
                ]);

                $nextDate = $isCatchUp
                    ? $catchUp['target_system_date']
                    : HospitalityTradingDatePolicy::nextOpenDate($tradingDate);
                $nextDay = null;

                if ($nextDate) {
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'advance',
                        'Opening the next trading date',
                        90
                    );

                    $nextDay = $this->openNextTradingDay(
                        $company->id,
                        $branchId,
                        $nextDate,
                        $day->id
                    );

                    if ($isCatchUp) {
                        $nextDay->update([
                            'metadata' => array_merge($this->metadataArray($nextDay->metadata), [
                                'opened_by_operation' => 'catch_up',
                                'catch_up_source_trading_date' => $catchUp['source_trading_date'],
                                'skipped_date_from' => $catchUp['skipped_date_from'],
                                'skipped_date_to' => $catchUp['skipped_date_to'],
                                'skipped_day_count' => $catchUp['skipped_day_count'],
                            ]),
                        ]);
                    }
                } else {
                    $this->writeDayEndProgress(
                        $company->id,
                        $branchId,
                        $runKey,
                        'advance',
                        'Waiting for the next Africa/Harare system date',
                        90
                    );
                }

                $accountingSummary = $this->reconciliationAccountingSummary($report);

                $run->update([
                    'next_trading_date' => $nextDate,
                    'status' => 'complete',
                    'completed_by' => $userId,
                    'completed_at' => now(),
                    'lock_released_at' => now(),
                    'summary' => [
                        'report_id' => $report->id,
                        'operation_type' => $isCatchUp ? 'catch_up' : 'standard',
                        ...$accountingSummary,
                    ],
                    'metadata' => array_merge($this->metadataArray($run->metadata), [
                        'completed_system_date' => HospitalityTradingDatePolicy::systemDate(),
                        'awaiting_next_system_date' => $nextDate === null,
                        'operation_type' => $isCatchUp ? 'catch_up' : 'standard',
                        'catch_up' => $isCatchUp ? array_merge($catchUp, [
                            'reason' => $operationRequest['reason'],
                            'confirmed_by' => $userId,
                        ]) : null,
                        'operational_readiness_at_close' => [
                            'verified_at' => now()->toISOString(),
                            'open_shift_count' => (int) ($operationalReadiness['open_shift_count'] ?? 0),
                            'trading_date_sale_count' => (int) ($operationalReadiness['trading_date_sale_count'] ?? 0),
                            'cashed_up_sale_count' => (int) ($operationalReadiness['cashed_up_sale_count'] ?? 0),
                            'uncashed_sale_count' => (int) ($operationalReadiness['uncashed_sale_count'] ?? 0),
                            'verification_errors' => $operationalReadiness['verification_errors'] ?? [],
                        ],
                    ]),
                ]);

                return response()->json([
                    'message' => $nextDay
                        ? 'Day End completed. Daily Stock Reconciliation was generated and the next trading date is open.'
                        : 'Day End completed. Daily Stock Reconciliation was generated; sales will remain closed until the next Africa/Harare system date.',
                    'data' => array_merge($operationPayload, [
                        'run_key' => $runKey,
                        'day_end_run_id' => $run->id,
                        'expected_trading_date' => $expectedTradingDate,
                        'progress_url' => "/api/pos/trading-day/day-end/progress/{$runKey}",
                        'status' => 'complete',
                        'idempotent_replay' => false,
                        'closed_trading_day' => $this->tradingDayPayload($day->fresh()),
                        'next_trading_day' => $nextDay ? $this->tradingDayPayload($nextDay) : null,
                        'awaiting_next_system_date' => $nextDay === null,
                        'report' => $report,
                        'progress_stages' => $this->completedDayEndProgressStages(),
                    ]),
                ]);
            }, 1);

            $responsePayload = $response->getData(true);
            if (($responsePayload['data']['status'] ?? null) === 'complete') {
                $this->writeDayEndProgress(
                    $company->id,
                    $branchId,
                    $runKey,
                    'complete',
                    'Day End completed',
                    100,
                    'complete'
                );
                // The transaction above has committed both the closed day and
                // its successor. Publish that branch state now so the edge
                // server can pull it independently of which till ran Day End.
                $this->publishCompletedDayEndSnapshot((string) $company->id, $branchId);
            }

            $this->releaseDayEndRuntimeLock($company->id, $branchId, $runKey);

            return $response;
        } catch (Throwable $exception) {
            $this->releaseDayEndRuntimeLock($company->id, $branchId, $runKey);
            $this->recordFailedDayEnd(
                companyId: $company->id,
                branchId: $branchId,
                tradingDate: $failedTradingDate,
                runKey: $runKey,
                userId: $userId,
                exception: $exception,
                operationMetadata: $isCatchUp ? [
                    'operation_type' => 'catch_up',
                    'catch_up' => array_merge(
                        $this->catchUpDetails(
                            $expectedTradingDate,
                            HospitalityTradingDatePolicy::systemDate(),
                            'open'
                        ),
                        ['reason' => $operationRequest['reason'] ?? null]
                    ),
                ] : ['operation_type' => 'standard']
            );

            report($exception);

            return response()->json([
                'message' => 'Day End failed. No trading date was advanced; it is safe to resolve the error and retry with the same run key.',
                'code' => 'DAY_END_FAILED',
                'data' => [
                    'run_key' => $runKey,
                    'status' => 'failed',
                    'stage_key' => 'failed',
                    'stage_label' => 'Day End failed',
                    'percent' => 100,
                ],
            ], 500);
        }
    }

    public function progress(Request $request, string $runKey): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }

        $payload = Cache::store('file')->get($this->dayEndProgressCacheKey($company->id, $branchId, $runKey));

        if (!$payload) {
            $run = TradingDayEnd::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('lock_token', $runKey)
                ->first();

            if ($run) {
                $payload = $this->persistedDayEndProgressPayload($run, $runKey);
            }
        }

        return response()->json([
            'data' => $payload ?: [
                'run_key' => $runKey,
                'status' => 'pending',
                'stage_key' => 'queued',
                'stage_label' => 'Waiting for Day End to start',
                'percent' => 0,
                'is_final' => false,
                'items' => [],
                'stages' => $this->dayEndProgressStages(),
                'updated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Enterprise safety action: create audited front stock closings for selected dates.
     * This does not pretend there was a physical count. It force-closes using current system expected quantities.
     */
    public function forceMissingFrontStockClosings(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);
        $till = $this->resolveTill($request);
        $userId = auth()->id();

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        if ($authority = $this->edgeDayEndAuthorityResponse((string) $company->id, $branchId)) {
            return $authority;
        }

        $activeDay = $this->activeOpenTradingDay($company->id, $branchId);
        if (!$activeDay) {
            return response()->json([
                'message' => 'No open Hospitality trading date is available for front stock closing.',
                'code' => 'AWAITING_NEXT_SYSTEM_DATE',
            ], 409);
        }

        $activeDayPayload = $this->tradingDayPayload($activeDay);
        if ($activeDayPayload['trading_date_is_future'] ?? false) {
            return response()->json([
                'message' => $activeDayPayload['block_reason'],
                'code' => 'FUTURE_TRADING_DATE',
                'data' => $activeDayPayload,
            ], 422);
        }

        if ($activeDayPayload['has_duplicate_open_trading_days'] ?? false) {
            return response()->json([
                'message' => $activeDayPayload['block_reason'],
                'code' => 'DUPLICATE_OPEN_TRADING_DAYS',
                'data' => $activeDayPayload,
            ], 409);
        }

        $androidReadiness = app(AndroidDayEndReadiness::class)->assess((string) $company->id, $branchId);
        if (! $androidReadiness['can_run_day_end']) {
            return response()->json([
                'message' => 'Front stock force closing is blocked while Android devices have pending or unverified offline work.',
                'code' => 'DAY_END_ANDROID_SYNC_REQUIRED',
                'data' => ['blocking_android_devices' => $androidReadiness['blocking_devices']],
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'dates' => ['required', 'array', 'min:1'],
            'dates.*' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'force_mode' => ['nullable', 'string', 'max:60'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $dates = collect($request->input('dates', []))
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values();
        $activeTradingDate = $this->tradingDateString($activeDay);

        if ($activeTradingDate && $dates->contains($activeTradingDate)) {
            return response()->json([
                'message' => 'The active trading date cannot be force closed. Complete a physical Front Stock Reconciliation before Day End.',
                'code' => 'FRONT_STOCK_RECONCILIATION_REQUIRED',
                'data' => [
                    'branch_id' => $branchId,
                    'trading_date' => $activeTradingDate,
                    'suggested_action' => 'Issue any negative ingredients from BOH to FRONT, then count and save Front Stock Reconciliation for the active trading date.',
                ],
            ], 422);
        }
        $systemDate = HospitalityTradingDatePolicy::systemDate();
        $futureDates = $dates->filter(fn ($date) => $date > $systemDate)->values();

        if ($futureDates->isNotEmpty()) {
            return response()->json([
                'message' => 'Front stock cannot be force closed for a date after the Africa/Harare system date.',
                'code' => 'FUTURE_TRADING_DATE',
                'data' => [
                    'system_date' => $systemDate,
                    'future_dates' => $futureDates->all(),
                ],
            ], 422);
        }

        $reason = trim((string) $request->input('reason', 'Forced by Day End preflight because manual front stock closing was missed.'));
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($company, $branchId, $till, $userId, $dates, $reason, &$created, &$skipped) {
            foreach ($dates as $date) {
                $existing = FrontStockDailyClosing::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->whereDate('closing_date', $date)
                    ->where('stock_area', self::FRONT_STOCK_AREA)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $skipped[] = [
                        'date' => $date,
                        'reason' => 'Already closed',
                        'closing_id' => $existing->id,
                    ];
                    continue;
                }

                $snapshot = $this->buildSystemExpectedFrontStockLines(
                    companyId: $company->id,
                    branchId: $branchId,
                    stockArea: self::FRONT_STOCK_AREA
                );

                $closing = FrontStockDailyClosing::create($this->filterColumns('front_stock_daily_closings', [
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'till_id' => $till?->id,
                    'closed_by' => $userId,
                    'closing_date' => $date,
                    'stock_area' => self::FRONT_STOCK_AREA,
                    'status' => 'closed',
                    'notes' => $reason,
                    'metadata' => [
                        'source' => 'day_end_enterprise_guard',
                        'closing_method' => 'force_close_system_expected',
                        'warning' => 'No physical count was captured for this date. Counted quantity was set equal to system expected quantity to unlock Day End.',
                        'forced_by' => $userId,
                        'forced_at' => now()->toISOString(),
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                $summary = [
                    'total_lines' => 0,
                    'variance_lines' => 0,
                    'expected_total_quantity' => 0,
                    'counted_total_quantity' => 0,
                    'variance_total_quantity' => 0,
                    'variance_total_value' => 0,
                ];

                foreach ($snapshot as $line) {
                    $expectedQty = round((float) ($line['expected_quantity'] ?? 0), 6);
                    $unitCost = round((float) ($line['unit_cost'] ?? 0), 6);
                    $currencyCode = $line['currency_code'] ?? 'USD';

                    FrontStockDailyClosingLine::create($this->filterColumns('front_stock_daily_closing_lines', [
                        'id' => (string) Str::uuid(),
                        'front_stock_daily_closing_id' => $closing->id,
                        'company_id' => $company->id,
                        'branch_id' => $branchId,
                        'product_id' => $line['product_id'],
                        'product_code' => $line['product_code'] ?? null,
                        'product_sku' => $line['product_sku'] ?? null,
                        'product_name' => $line['product_name'] ?? 'Product',
                        'product_type' => $line['product_type'] ?? null,
                        'uom_code' => $line['uom_code'] ?? 'EACH',
                        'stock_area' => self::FRONT_STOCK_AREA,
                        'expected_quantity' => $expectedQty,
                        'counted_quantity' => $expectedQty,
                        'variance_quantity' => 0,
                        'unit_cost' => $unitCost,
                        'variance_value' => 0,
                        'notes' => 'Force closed from Day End preflight: counted quantity equals system expected quantity.',
                        'metadata' => [
                            'source' => 'day_end_enterprise_guard',
                            'closing_method' => 'force_close_system_expected',
                            'currency_code' => $currencyCode,
                        ],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    $summary['total_lines']++;
                    $summary['expected_total_quantity'] += $expectedQty;
                    $summary['counted_total_quantity'] += $expectedQty;
                }

                $closing->update($this->filterColumns('front_stock_daily_closings', array_map(
                    fn ($value) => is_numeric($value) ? round((float) $value, 6) : $value,
                    $summary
                )));

                $created[] = [
                    'date' => $date,
                    'closing_id' => $closing->id,
                    'line_count' => $summary['total_lines'],
                    'closing_method' => 'force_close_system_expected',
                ];
            }
        });

        $day = $this->activeOpenTradingDay($company->id, $branchId);

        $calendar = $day
            ? $this->frontStockClosingCalendar($company->id, $branchId, $this->tradingDateString($day), HospitalityTradingDatePolicy::systemDate())
            : [];

        $createdCount = count($created);
        $preparationMessage = $createdCount . ' missing front stock closing day(s) backfilled. '
            . 'This prepared the Day End checks only; it did not close or advance the branch business date. '
            . 'The business date remains ' . ($activeTradingDate ?: 'unresolved')
            . ' until Run Day End or Catch Up to Today completes successfully.';

        return response()->json([
            'message' => $preparationMessage,
            'data' => [
                'operation' => 'front_stock_closing_backfill',
                'trading_day_advanced' => false,
                'day_end_required' => true,
                'active_trading_date' => $activeTradingDate,
                'suggested_action' => 'Resolve every shift, cashup, stock and reconciliation blocker, then run Day End or Catch Up to Today.',
                'created' => $created,
                'skipped' => $skipped,
                'front_stock_closing_calendar' => $calendar,
            ],
        ]);
    }

    public function rollbackRunning(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company || !$branchId) {
            return response()->json(['message' => 'Company or branch could not be resolved.'], 422);
        }
        if ($authority = $this->edgeDayEndAuthorityResponse((string) $company->id, $branchId)) {
            return $authority;
        }

        $day = TradingDay::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('status', 'day_end_running')
            ->latest('day_end_started_at')
            ->first();

        if (!$day) {
            return response()->json(['message' => 'No running Day End lock found.']);
        }

        $runKey = $day->lock_token;

        $day->update([
            'status' => 'open',
            'day_end_started_at' => null,
            'day_end_started_by' => null,
            'lock_token' => null,
            'metadata' => array_merge($this->metadataArray($day->metadata), ['rollback_at' => now()->toISOString()]),
        ]);

        $this->releaseDayEndRuntimeLock($company->id, $branchId, $runKey);

        if ($runKey) {
            TradingDayEnd::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('lock_token', $runKey)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => 'Day End lock was released by an authorised rollback.',
                    'lock_released_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Day End lock released. Trading day restored.', 'data' => $this->tradingDayPayload($day->fresh())]);
    }

    private function currentOrCreateOpenTradingDay(string $companyId, string $branchId): ?TradingDay
    {
        $day = $this->activeOpenTradingDay($companyId, $branchId);

        if ($day) {
            return $day;
        }

        $lastClosed = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'closed')
            ->orderByDesc('trading_date')
            ->first();

        $tradingDate = $lastClosed
            ? HospitalityTradingDatePolicy::nextOpenDate($this->tradingDateString($lastClosed))
            : HospitalityTradingDatePolicy::systemDate();

        if (!$tradingDate) {
            return null;
        }

        // Multiple FOH devices can request /current at the same instant. The
        // database unique key plus firstOrCreate's unique-violation recovery
        // guarantees they all receive the same branch trading day.
        return TradingDay::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'trading_date' => $tradingDate,
            ],
            [
                'id' => (string) Str::uuid(),
                'status' => 'open',
                'opened_at' => now(),
                'metadata' => [
                    'source' => 'auto_open_current_trading_day',
                    'created_from_last_closed_day' => $lastClosed?->id,
                    'system_date_at_open' => HospitalityTradingDatePolicy::systemDate(),
                ],
            ]
        );
    }

    private function publishCompletedDayEndSnapshot(string $companyId, string $branchId): void
    {
        try {
            app(SyncConfigurationService::class)->publishBranchSnapshot($companyId, $branchId);
        } catch (Throwable $exception) {
            // Day End is already committed and must never be reported as
            // rolled back because snapshot publication had a transient
            // failure. The signed pull endpoint also materializes the latest
            // snapshot, so a later edge retry remains self-healing.
            report($exception);
        }
    }

    /** @return array<string, mixed> */
    private function syncDayEndOriginMetadata(Request $request): array
    {
        if (! $request->attributes->getBoolean('sync_initiated_day_end')) {
            return [];
        }

        $node = $request->attributes->get('sync_node');

        return [
            'initiated_via' => 'paired_edge',
            'source_sync_node_id' => is_object($node) && isset($node->id)
                ? (string) $node->id
                : null,
            'source_till_id' => $request->attributes->get('sync_initiated_till_id'),
        ];
    }


    /**
     * Resolve the authoritative active business trading day for the branch.
     *
     * Important:
     * - If there is only one open day, use it. This preserves the rule that
     *   sales continue posting to the unclosed business day even if the system
     *   calendar has moved on.
     * - If duplicate open days exist because of older data or a failed rollout,
     *   use the latest open trading date. The layout and Day End must not pick
     *   the old orphan date such as 6 when the real active open day is 7.
     * - A running Day End always wins, because that is the branch lock.
     */
    private function activeOpenTradingDay(string $companyId, string $branchId): ?TradingDay
    {
        $running = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'day_end_running')
            ->orderByDesc('day_end_started_at')
            ->orderByDesc('trading_date')
            ->first();

        if ($running) {
            return $running;
        }

        $day = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first();

        return $day;
    }

    private function activeOpenTradingDayForUpdate(string $companyId, string $branchId): ?TradingDay
    {
        $running = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'day_end_running')
            ->lockForUpdate()
            ->orderByDesc('day_end_started_at')
            ->orderByDesc('trading_date')
            ->first();

        if ($running) {
            return $running;
        }

        $day = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->lockForUpdate()
            ->orderByDesc('trading_date')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first();

        return $day;
    }

    private function openDayDiagnostics(?TradingDay $day): array
    {
        if (!$day) {
            return [
                'open_trading_day_count' => 0,
                'has_duplicate_open_trading_days' => false,
                'open_trading_days' => [],
            ];
        }

        $openRows = TradingDay::query()
            ->where('company_id', $day->company_id)
            ->where('branch_id', $day->branch_id)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByDesc('trading_date')
            ->get(['id', 'trading_date', 'status']);

        return [
            'open_trading_day_count' => $openRows->count(),
            'has_duplicate_open_trading_days' => $openRows->count() > 1,
            'open_trading_days' => $openRows->map(fn ($row) => [
                'id' => $row->id,
                'trading_date' => $this->tradingDateString($row),
                'status' => $row->status,
            ])->values()->all(),
        ];
    }

    private function tradingDateString(?TradingDay $day): ?string
    {
        if (!$day) {
            return null;
        }

        /*
         |--------------------------------------------------------------------------
         | Do NOT read $day->trading_date directly for API business date output.
         |--------------------------------------------------------------------------
         | If the model casts trading_date as a date/datetime, Laravel serializes it
         | to UTC. A database DATE value of 2026-07-07 can therefore appear in JSON
         | as 2026-07-06T22:00:00.000000Z when the app timezone is +02:00.
         |
         | The business trading date must be returned exactly as stored in MySQL.
         */
        $raw = $day->getRawOriginal('trading_date') ?: $day->getAttributeFromArray('trading_date');

        if (!$raw) {
            return null;
        }

        return HospitalityTradingDatePolicy::normalize($raw);
    }

    private function tradingDayPayload(?TradingDay $day): ?array
    {
        if (!$day) {
            return null;
        }

        $payload = $day->toArray();
        $businessDate = $this->tradingDateString($day);

        $payload['trading_date'] = $businessDate;
        $payload['business_date'] = $businessDate;
        $payload['active_business_date'] = $businessDate;
        $state = HospitalityTradingDatePolicy::state($businessDate, $day->status);
        $diagnostics = $this->openDayDiagnostics($day);

        if ($diagnostics['has_duplicate_open_trading_days']) {
            $state['can_sell'] = false;
            $state['can_run_day_end'] = false;
            $state['sale_block_reason'] = 'Multiple open Hospitality trading dates exist for this branch. Resolve the duplicate trading-day records before selling or running Day End.';
        }

        return array_merge(
            $payload,
            $state,
            [
                'sale_allowed' => $state['can_sell'],
                'block_reason' => $state['sale_block_reason'],
                'is_future' => $state['trading_date_is_future'],
                'is_past' => $state['trading_date_is_past'],
            ],
            $diagnostics
        );
    }

    private function awaitingNextTradingDayPayload(string $companyId, string $branchId): array
    {
        $systemDate = HospitalityTradingDatePolicy::systemDate();
        $lastClosed = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'closed')
            ->orderByDesc('trading_date')
            ->first();
        $lastClosedDate = $this->tradingDateString($lastClosed);
        $nextCalendarDate = $lastClosedDate
            ? Carbon::parse($lastClosedDate)->addDay()->toDateString()
            : $systemDate;
        $reason = $lastClosedDate === $systemDate
            ? "Trading date {$systemDate} is already closed. Sales can resume when the Africa/Harare system date reaches {$nextCalendarDate}."
            : 'No valid Hospitality trading date is open for this branch.';

        return [
            'id' => null,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'trading_date' => null,
            'business_date' => null,
            'active_business_date' => null,
            'system_date' => $systemDate,
            'system_timezone' => HospitalityTradingDatePolicy::TIMEZONE,
            'status' => 'awaiting_next_system_date',
            'last_closed_trading_date' => $lastClosedDate,
            'next_trading_date_available_on' => $nextCalendarDate,
            'trading_date_is_future' => false,
            'trading_date_is_past' => false,
            'trading_date_matches_system_date' => false,
            'is_future' => false,
            'is_past' => false,
            'awaiting_next_system_date' => true,
            'can_sell' => false,
            'sale_allowed' => false,
            'can_run_day_end' => false,
            'sale_block_reason' => $reason,
            'block_reason' => $reason,
            'open_trading_day_count' => 0,
            'has_duplicate_open_trading_days' => false,
            'open_trading_days' => [],
        ];
    }

    private function openNextTradingDay(
        string $companyId,
        string $branchId,
        string $nextDate,
        string $closedTradingDayId
    ): TradingDay {
        $normalizedNextDate = HospitalityTradingDatePolicy::normalize($nextDate);
        $systemDate = HospitalityTradingDatePolicy::systemDate();

        if (!$normalizedNextDate || $normalizedNextDate > $systemDate) {
            throw new \LogicException('Day End refused to open a trading date after the Africa/Harare system date.');
        }

        $nextDay = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('trading_date', $normalizedNextDate)
            ->lockForUpdate()
            ->first();

        if ($nextDay) {
            if (!in_array($nextDay->status, ['open', 'day_end_running'], true)) {
                throw new \RuntimeException("Trading date {$normalizedNextDate} already exists with status {$nextDay->status} and cannot be reopened automatically.");
            }

            return $nextDay;
        }

        return TradingDay::create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'trading_date' => $normalizedNextDate,
            'status' => 'open',
            'opened_at' => now(),
            'metadata' => [
                'opened_after_day_end_id' => $closedTradingDayId,
                'system_date_at_open' => $systemDate,
            ],
        ]);
    }

    private function completedDayEndResponse(TradingDayEnd $run, string $runKey, bool $idempotentReplay): JsonResponse
    {
        $closedDay = TradingDay::query()
            ->where('company_id', $run->company_id)
            ->where('branch_id', $run->branch_id)
            ->whereDate('trading_date', HospitalityTradingDatePolicy::normalize($run->trading_date))
            ->first();
        $nextDate = HospitalityTradingDatePolicy::normalize($run->next_trading_date);
        $nextDay = $nextDate
            ? TradingDay::query()
                ->where('company_id', $run->company_id)
                ->where('branch_id', $run->branch_id)
                ->whereDate('trading_date', $nextDate)
                ->first()
            : null;
        $summary = $this->metadataArray($run->summary);
        $report = DailyStockReconciliationReport::query()
            ->where('company_id', $run->company_id)
            ->where('branch_id', $run->branch_id)
            ->when(
                $summary['report_id'] ?? null,
                fn ($query, $reportId) => $query->where('id', $reportId),
                fn ($query) => $query->whereDate('trading_date', HospitalityTradingDatePolicy::normalize($run->trading_date))
            )
            ->with(['lines' => fn ($query) => $query
                ->where('company_id', $run->company_id)
                ->where('branch_id', $run->branch_id)])
            ->first();

        $this->writeDayEndProgress(
            $run->company_id,
            $run->branch_id,
            $runKey,
            'complete',
            'Day End completed',
            100,
            'complete',
            [],
            $this->operationPayloadForRun($run)
        );

        return response()->json([
            'message' => 'Day End was already completed for this trading date; the original result was returned without running it again.',
            'data' => array_merge($this->operationPayloadForRun($run), [
                'run_key' => $runKey,
                'day_end_run_id' => $run->id,
                'expected_trading_date' => HospitalityTradingDatePolicy::normalize($run->trading_date),
                'progress_url' => "/api/pos/trading-day/day-end/progress/{$runKey}",
                'status' => 'complete',
                'idempotent_replay' => $idempotentReplay,
                'closed_trading_day' => $this->tradingDayPayload($closedDay),
                'next_trading_day' => $this->tradingDayPayload($nextDay),
                'awaiting_next_system_date' => $nextDay === null,
                'report' => $report,
                'progress_stages' => $this->completedDayEndProgressStages(),
            ]),
        ]);
    }

    private function dayEndRunPayload(TradingDayEnd $run, string $runKey, bool $alreadyRunning = false): array
    {
        return array_merge($this->operationPayloadForRun($run), [
            'run_key' => $runKey,
            'day_end_run_id' => $run->id,
            'status' => $run->status,
            'trading_date' => HospitalityTradingDatePolicy::normalize($run->trading_date),
            'expected_trading_date' => HospitalityTradingDatePolicy::normalize($run->trading_date),
            'system_date' => HospitalityTradingDatePolicy::systemDate(),
            'already_running' => $alreadyRunning,
            'progress_url' => "/api/pos/trading-day/day-end/progress/{$runKey}",
            'progress_stages' => $this->dayEndProgressStages(),
        ]);
    }

    /** @return array<string, mixed> */
    private function catchUpDetails(?string $sourceTradingDate, string $targetSystemDate, ?string $status): array
    {
        $sourceTradingDate = HospitalityTradingDatePolicy::normalize($sourceTradingDate);
        $targetSystemDate = HospitalityTradingDatePolicy::normalize($targetSystemDate)
            ?: HospitalityTradingDatePolicy::systemDate();
        $lagDays = $sourceTradingDate && $sourceTradingDate < $targetSystemDate
            ? (int) Carbon::parse($sourceTradingDate)->diffInDays(Carbon::parse($targetSystemDate))
            : 0;
        $skippedDayCount = max(0, $lagDays - 1);

        return [
            'available' => $sourceTradingDate !== null
                && $status === 'open'
                && $lagDays > 0,
            'lag_days' => $lagDays,
            'source_trading_date' => $sourceTradingDate,
            'target_system_date' => $targetSystemDate,
            'skipped_date_from' => $skippedDayCount > 0
                ? Carbon::parse($sourceTradingDate)->addDay()->toDateString()
                : null,
            'skipped_date_to' => $skippedDayCount > 0
                ? Carbon::parse($targetSystemDate)->subDay()->toDateString()
                : null,
            'skipped_day_count' => $skippedDayCount,
            'requires_reason' => true,
        ];
    }

    /** @param array<string, mixed> $catchUp */
    private function catchUpOperationPayload(array $catchUp): array
    {
        return [
            'operation_type' => 'catch_up',
            'catch_up' => $catchUp,
            'lag_days' => (int) ($catchUp['lag_days'] ?? 0),
            'source_trading_date' => $catchUp['source_trading_date'] ?? null,
            'target_system_date' => $catchUp['target_system_date'] ?? null,
            'skipped_date_from' => $catchUp['skipped_date_from'] ?? null,
            'skipped_date_to' => $catchUp['skipped_date_to'] ?? null,
            'skipped_day_count' => (int) ($catchUp['skipped_day_count'] ?? 0),
        ];
    }

    private function operationPayloadForRun(TradingDayEnd $run): array
    {
        $metadata = $this->metadataArray($run->metadata);
        if ($this->operationTypeForRun($run) !== 'catch_up' || !is_array($metadata['catch_up'] ?? null)) {
            return ['operation_type' => 'standard'];
        }

        return $this->catchUpOperationPayload($metadata['catch_up']);
    }

    private function operationTypeForRun(TradingDayEnd $run): string
    {
        return ($this->metadataArray($run->metadata)['operation_type'] ?? null) === 'catch_up'
            ? 'catch_up'
            : 'standard';
    }

    /**
     * Preserve the DSR's accounting currency boundary in the Day End audit.
     * Mixed-currency headline values are deliberately null; per-currency rows
     * are authoritative and must not be replaced by legacy zero placeholders.
     */
    private function reconciliationAccountingSummary(DailyStockReconciliationReport $report): array
    {
        $metadata = $this->metadataArray($report->metadata);
        $summary = is_array($metadata['summary'] ?? null) ? $metadata['summary'] : [];
        $currencyTotals = array_values((array) ($summary['currency_totals'] ?? []));
        $inventoryCurrencyTotals = array_values((array) ($summary['inventory_currency_totals'] ?? []));
        $salesAvailable = array_key_exists('headline_sales_totals_available', $summary)
            ? (bool) $summary['headline_sales_totals_available']
            : count($currencyTotals) <= 1;
        $varianceAvailable = array_key_exists('headline_variance_total_available', $summary)
            ? (bool) $summary['headline_variance_total_available']
            : count($inventoryCurrencyTotals) <= 1;

        $value = static function (array $source, string $key, mixed $fallback, bool $available): mixed {
            if (!$available) {
                return null;
            }

            return is_numeric($source[$key] ?? null)
                ? (float) $source[$key]
                : (is_numeric($fallback) ? (float) $fallback : 0.0);
        };

        return [
            'currency_code' => (string) ($report->currency_code ?: 'USD'),
            'headline_sales_totals_available' => $salesAvailable,
            'headline_variance_total_available' => $varianceAvailable,
            'headline_totals_note' => $summary['headline_totals_note'] ?? null,
            'total_revenue' => $value($summary, 'total_revenue', $report->getRawOriginal('total_revenue'), $salesAvailable),
            'total_recipe_cost' => $value($summary, 'total_recipe_cost', $report->getRawOriginal('total_recipe_cost'), $salesAvailable),
            'gross_profit' => $value($summary, 'gross_profit', $report->getRawOriginal('gross_profit'), $salesAvailable),
            'total_variance_value' => $value($summary, 'total_variance_value', $report->getRawOriginal('total_variance_value'), $varianceAvailable),
            'currency_totals' => $currencyTotals,
            'inventory_currency_totals' => $inventoryCurrencyTotals,
        ];
    }

    private function idempotencyOperationMismatchResponse(
        string $companyId,
        string $branchId,
        string $runKey,
        string $requestedOperationType,
        string $existingOperationType
    ): JsonResponse {
        $message = 'The supplied Day End run key belongs to a different operation type. Use the original operation or a new run key.';
        $details = [
            'run_key' => $runKey,
            'requested_operation_type' => $requestedOperationType,
            'existing_operation_type' => $existingOperationType,
            'refresh_required' => true,
            'can_run_day_end' => false,
        ];

        $this->writeDayEndProgress(
            $companyId,
            $branchId,
            $runKey,
            'blocked',
            'Run key belongs to another Day End operation',
            100,
            'blocked',
            [$details]
        );

        return response()->json([
            'message' => $message,
            'code' => 'IDEMPOTENCY_OPERATION_MISMATCH',
            'data' => $details,
        ], 409);
    }

    private function staleTradingDateResponse(
        string $companyId,
        string $branchId,
        string $runKey,
        ?string $expectedTradingDate,
        ?string $observedTradingDate,
        ?string $authoritativeTradingDate,
        ?TradingDay $currentDay,
        string $message
    ): JsonResponse {
        $details = [
            'run_key' => $runKey,
            'status' => 'blocked',
            'expected_trading_date' => $expectedTradingDate,
            'observed_trading_date' => $observedTradingDate,
            'authoritative_trading_date' => $authoritativeTradingDate,
            'system_date' => HospitalityTradingDatePolicy::systemDate(),
            'refresh_required' => true,
        ];

        $this->writeDayEndProgress(
            $companyId,
            $branchId,
            $runKey,
            'blocked',
            'Trading date changed; refresh Day End',
            100,
            'blocked',
            [$details]
        );

        return response()->json([
            'message' => $message,
            'code' => 'STALE_TRADING_DATE',
            'data' => array_merge(
                $this->dayEndProgressOperationContext($companyId, $branchId, $runKey),
                $details,
                [
                'can_run_day_end' => false,
                'current_trading_day' => $currentDay
                    ? $this->tradingDayPayload($currentDay)
                    : $this->awaitingNextTradingDayPayload($companyId, $branchId),
                ]
            ),
        ], 409);
    }

    private function dayEndProgressCacheKey(string $companyId, string $branchId, string $runKey): string
    {
        return 'hospitality_day_end_progress:' . $companyId . ':' . $branchId . ':' . $runKey;
    }

    private function dayEndRuntimeLockCacheKey(string $companyId, string $branchId): string
    {
        return HospitalityTradingDatePolicy::runtimeLockCacheKey($companyId, $branchId);
    }

    private function acquireDayEndRuntimeLock(
        string $companyId,
        string $branchId,
        string $runKey,
        string $tradingDate
    ): void {
        Cache::store('file')->put(
            $this->dayEndRuntimeLockCacheKey($companyId, $branchId),
            [
                'run_key' => $runKey,
                'trading_date' => $tradingDate,
                'status' => 'running',
                'started_at' => now()->toISOString(),
            ],
            now()->addMinutes(30)
        );
    }

    private function releaseDayEndRuntimeLock(string $companyId, string $branchId, ?string $runKey = null): void
    {
        $key = $this->dayEndRuntimeLockCacheKey($companyId, $branchId);
        $lock = Cache::store('file')->get($key);

        if (!$lock || !$runKey || ($lock['run_key'] ?? null) === $runKey) {
            Cache::store('file')->forget($key);
        }
    }

    private function writeDayEndProgress(
        string $companyId,
        string $branchId,
        string $runKey,
        string $stageKey,
        string $stageLabel,
        int $percent,
        string $status = 'running',
        array $items = [],
        array $operationContext = []
    ): void {
        $percent = max(0, min(100, $percent));
        $final = in_array($status, ['complete', 'failed', 'blocked'], true);
        $cacheKey = $this->dayEndProgressCacheKey($companyId, $branchId, $runKey);
        $preservedOperationContext = $this->dayEndProgressOperationContext(
            $companyId,
            $branchId,
            $runKey
        );

        Cache::store('file')->put($cacheKey, array_merge($preservedOperationContext, $operationContext, [
            'run_key' => $runKey,
            'status' => $status,
            'stage_key' => $stageKey,
            'stage_label' => $stageLabel,
            'percent' => $percent,
            'is_final' => $final,
            'items' => $items,
            'stages' => $this->dayEndProgressStagesWithStatus($stageKey, $percent, $final && $status === 'complete'),
            'updated_at' => now()->toISOString(),
        ]), now()->addHours(24));
    }

    private function dayEndProgressOperationContext(
        string $companyId,
        string $branchId,
        string $runKey
    ): array {
        return collect((array) Cache::store('file')->get(
            $this->dayEndProgressCacheKey($companyId, $branchId, $runKey),
            []
        ))->only([
            'operation_type',
            'catch_up',
            'source_trading_date',
            'target_system_date',
            'skipped_date_from',
            'skipped_date_to',
            'skipped_day_count',
            'lag_days',
        ])->all();
    }

    private function persistedDayEndProgressPayload(TradingDayEnd $run, string $runKey): array
    {
        $status = match ($run->status) {
            'complete', 'completed' => 'complete',
            'failed' => 'failed',
            'blocked' => 'blocked',
            default => 'running',
        };
        $stageKey = $status === 'complete' ? 'complete' : $status;
        $percent = in_array($status, ['complete', 'failed', 'blocked'], true) ? 100 : 20;

        return array_merge($this->operationPayloadForRun($run), [
            'run_key' => $runKey,
            'day_end_run_id' => $run->id,
            'status' => $status,
            'stage_key' => $stageKey,
            'stage_label' => match ($status) {
                'complete' => 'Day End completed',
                'failed' => 'Day End failed',
                'blocked' => 'Day End is blocked',
                default => 'Day End is running',
            },
            'percent' => $percent,
            'is_final' => in_array($status, ['complete', 'failed', 'blocked'], true),
            'items' => [],
            'failure_reason' => $run->failure_reason,
            'trading_date' => HospitalityTradingDatePolicy::normalize($run->trading_date),
            'next_trading_date' => HospitalityTradingDatePolicy::normalize($run->next_trading_date),
            'summary' => $run->summary,
            'stages' => $this->dayEndProgressStagesWithStatus($stageKey, $percent, $status === 'complete'),
            'updated_at' => optional($run->updated_at)->toISOString(),
        ]);
    }

    private function dayEndProgressStages(): array
    {
        return [
            ['key' => 'preflight', 'label' => 'Checking trading date and stock controls', 'percent' => 10],
            ['key' => 'lock', 'label' => 'Locking sales for the trading date', 'percent' => 25],
            ['key' => 'reconciliation', 'label' => 'Generating Daily Stock Reconciliation', 'percent' => 55],
            ['key' => 'close', 'label' => 'Closing the trading date', 'percent' => 78],
            ['key' => 'advance', 'label' => 'Preparing the next trading date', 'percent' => 90],
            ['key' => 'complete', 'label' => 'Day End completed', 'percent' => 100],
        ];
    }

    private function completedDayEndProgressStages(): array
    {
        return collect($this->dayEndProgressStages())
            ->map(fn ($stage) => array_merge($stage, ['status' => 'complete']))
            ->values()
            ->all();
    }

    private function dayEndProgressStagesWithStatus(string $stageKey, int $percent, bool $completed): array
    {
        return collect($this->dayEndProgressStages())
            ->map(function (array $stage) use ($stageKey, $percent, $completed) {
                $status = $completed || $stage['percent'] < $percent
                    ? 'complete'
                    : ($stage['key'] === $stageKey ? 'running' : 'pending');

                return array_merge($stage, ['status' => $status]);
            })
            ->values()
            ->all();
    }

    private function recordFailedDayEnd(
        string $companyId,
        string $branchId,
        ?string $tradingDate,
        string $runKey,
        ?string $userId,
        Throwable $exception,
        array $operationMetadata = []
    ): void {
        $message = mb_substr($exception->getMessage() ?: 'Unknown Day End error.', 0, 2000);

        $this->writeDayEndProgress(
            $companyId,
            $branchId,
            $runKey,
            'failed',
            'Day End failed',
            100,
            'failed',
            [['message' => $message]]
        );

        if (!$tradingDate || !Schema::hasTable('trading_day_ends')) {
            return;
        }

        $run = TradingDayEnd::query()->firstOrNew([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'trading_date' => $tradingDate,
        ]);

        if (!$run->exists) {
            $run->id = (string) Str::uuid();
        }

        $run->fill([
            'status' => 'failed',
            'started_by' => $userId,
            'started_at' => $run->started_at ?: now(),
            'failed_at' => now(),
            'failure_reason' => $message,
            'lock_token' => $runKey,
            'lock_released_at' => now(),
            'metadata' => array_merge($this->metadataArray($run->metadata), [
                'source' => 'hospitality_day_end',
                'failed_system_date' => HospitalityTradingDatePolicy::systemDate(),
                ...$operationMetadata,
            ]),
        ])->save();
    }

    /**
     * Verify the accounting boundary that must be clean before Hospitality Day
     * End closes a trading date. A branch cannot advance while a cashier can
     * still post to an open shift, or while completed transactions for this
     * trading date are not covered by a completed cashup for the same shift and
     * trading date.
     *
     * @return array<string, mixed>
     */
    private function dayEndOperationalReadiness(
        string $companyId,
        string $branchId,
        TradingDay $day,
        string $tradingDate
    ): array {
        $requiredTables = [
            'pos_user_shift_sessions',
            'sales_transactions',
            'pos_cashups',
        ];
        $missingTables = collect($requiredTables)
            ->reject(fn (string $table) => Schema::hasTable($table))
            ->values()
            ->all();

        if ($missingTables !== []) {
            return $this->emptyDayEndOperationalReadiness([
                'Day End cannot verify shifts and cashups because required tables are missing: '
                . implode(', ', $missingTables) . '.',
            ]);
        }

        $hasTradingDayId = Schema::hasColumn('sales_transactions', 'trading_day_id');
        $hasBusinessDate = Schema::hasColumn('sales_transactions', 'business_date');
        $cashupsHaveTradingDate = Schema::hasColumn('pos_cashups', 'trading_date');

        $verificationErrors = [];
        if (!$hasTradingDayId && !$hasBusinessDate) {
            $verificationErrors[] = 'Sales do not expose trading_day_id or business_date, so Hospitality cashup coverage cannot be verified safely.';
        }
        if (!$cashupsHaveTradingDate) {
            $verificationErrors[] = 'Cashups do not expose trading_date, so Hospitality cashup coverage cannot be verified safely.';
        }

        if ($verificationErrors !== []) {
            return $this->emptyDayEndOperationalReadiness($verificationErrors);
        }

        $openShiftQuery = DB::table('pos_user_shift_sessions as shifts')
            ->where('shifts.company_id', $companyId)
            ->where('shifts.branch_id', $branchId)
            ->where(function ($query) {
                // A mismatched legacy row (closed status without closed_at, or
                // an open status with a timestamp) is still unsafe to advance.
                $query->whereRaw("LOWER(COALESCE(shifts.status, 'open')) <> 'closed'")
                    ->orWhereNull('shifts.closed_at');
            });

        if (Schema::hasTable('pos_users')) {
            $openShiftQuery
                ->leftJoin('pos_users', 'pos_users.id', '=', 'shifts.pos_user_id')
                ->addSelect(DB::raw("COALESCE(pos_users.name, pos_users.username, 'Unknown cashier') as cashier_name"));
        } else {
            $openShiftQuery->addSelect(DB::raw("'Unknown cashier' as cashier_name"));
        }

        $openShifts = $openShiftQuery
            ->addSelect([
                'shifts.id',
                'shifts.pos_user_id',
                'shifts.shift_name',
                'shifts.status',
                'shifts.opened_at',
                'shifts.closed_at',
            ])
            ->orderBy('shifts.opened_at')
            ->get()
            ->map(fn ($shift) => [
                'shift_session_id' => (string) $shift->id,
                'cashier_id' => (string) $shift->pos_user_id,
                'cashier_name' => $shift->cashier_name,
                'shift_name' => $shift->shift_name,
                'status' => $shift->status,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
            ])
            ->values();

        $salesQuery = DB::table('sales_transactions as sales')
            ->where('sales.company_id', $companyId)
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed');

        if (Schema::hasColumn('sales_transactions', 'deleted_at')) {
            $salesQuery->whereNull('sales.deleted_at');
        }

        $salesQuery->where(function ($query) use ($day, $tradingDate, $hasTradingDayId, $hasBusinessDate) {
            if ($hasTradingDayId) {
                $query->where('sales.trading_day_id', $day->id);
            }

            if ($hasBusinessDate) {
                $method = $hasTradingDayId ? 'orWhereDate' : 'whereDate';
                $query->{$method}('sales.business_date', $tradingDate);
            }
        });

        $totalSalesCount = (clone $salesQuery)->count();
        $cashupCloseColumns = collect(['cashup_closed_at', 'closed_at', 'updated_at'])
            ->filter(fn (string $column) => Schema::hasColumn('pos_cashups', $column))
            ->map(fn (string $column) => "cashups.{$column}")
            ->values()
            ->all();
        $saleTimeColumns = collect(['transaction_date', 'created_at'])
            ->filter(fn (string $column) => Schema::hasColumn('sales_transactions', $column))
            ->map(fn (string $column) => "sales.{$column}")
            ->values()
            ->all();
        $cashupCloseExpression = count($cashupCloseColumns) > 1
            ? 'COALESCE(' . implode(', ', $cashupCloseColumns) . ')'
            : ($cashupCloseColumns[0] ?? 'NULL');
        $saleTimeExpression = count($saleTimeColumns) > 1
            // created_at catches a late/offline insertion whose business
            // transaction timestamp was backdated to before the cashup.
            ? 'GREATEST(COALESCE(' . $saleTimeColumns[0] . ', ' . $saleTimeColumns[1] . '), '
                . 'COALESCE(' . $saleTimeColumns[1] . ', ' . $saleTimeColumns[0] . '))'
            : ($saleTimeColumns[0] ?? 'NULL');

        $uncashedSalesQuery = (clone $salesQuery)
            ->where(function ($query) use (
                $companyId,
                $branchId,
                $tradingDate,
                $cashupCloseExpression,
                $saleTimeExpression
            ) {
                $query->whereNull('sales.shift_session_id')
                    ->orWhereNotExists(function ($cashupQuery) use (
                        $companyId,
                        $branchId,
                        $tradingDate,
                        $cashupCloseExpression,
                        $saleTimeExpression
                    ) {
                        $cashupQuery
                            ->selectRaw('1')
                            ->from('pos_cashups as cashups')
                            ->where('cashups.company_id', $companyId)
                            ->where('cashups.branch_id', $branchId)
                            ->whereColumn('cashups.shift_session_id', 'sales.shift_session_id')
                            ->whereIn(DB::raw('LOWER(cashups.status)'), ['closed', 'approved'])
                            ->whereDate('cashups.trading_date', $tradingDate)
                            ->whereRaw("{$cashupCloseExpression} >= {$saleTimeExpression}");

                        if (Schema::hasColumn('pos_cashups', 'deleted_at')) {
                            $cashupQuery->whereNull('cashups.deleted_at');
                        }
                    });
            });

        $uncashedSaleCount = (clone $uncashedSalesQuery)->count();
        $uncashedSalesByCurrency = (clone $uncashedSalesQuery)
            ->selectRaw("COALESCE(NULLIF(UPPER(TRIM(sales.currency_code)), ''), 'UNKNOWN') as currency_code")
            ->selectRaw('COUNT(*) as transaction_count')
            // Group the physical column for MySQL ONLY_FULL_GROUP_BY. PHP then
            // merges case/whitespace variants into the normalized code.
            ->groupBy('sales.currency_code')
            ->orderBy('sales.currency_code')
            ->get()
            ->map(fn ($row) => [
                'currency_code' => $row->currency_code,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->groupBy('currency_code')
            ->map(fn ($rows, $currencyCode) => [
                'currency_code' => $currencyCode,
                'transaction_count' => (int) $rows->sum('transaction_count'),
            ])
            ->values()
            ->all();
        $uncashedShiftSessions = (clone $uncashedSalesQuery)
            ->whereNotNull('sales.shift_session_id')
            ->selectRaw('sales.shift_session_id, sales.cashier_id, COUNT(*) as transaction_count')
            ->groupBy('sales.shift_session_id', 'sales.cashier_id')
            ->orderBy('sales.shift_session_id')
            ->get()
            ->map(fn ($row) => [
                'shift_session_id' => (string) $row->shift_session_id,
                'cashier_id' => $row->cashier_id ? (string) $row->cashier_id : null,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values()
            ->all();
        $uncashedSalesSample = (clone $uncashedSalesQuery)
            ->select([
                'sales.id',
                'sales.invoice_number',
                'sales.shift_session_id',
                'sales.cashier_id',
                'sales.transaction_date',
                'sales.currency_code',
            ])
            ->orderBy('sales.transaction_date')
            ->limit(25)
            ->get()
            ->map(fn ($sale) => [
                'sale_id' => (string) $sale->id,
                'invoice_number' => $sale->invoice_number,
                'shift_session_id' => $sale->shift_session_id ? (string) $sale->shift_session_id : null,
                'cashier_id' => $sale->cashier_id ? (string) $sale->cashier_id : null,
                'transaction_date' => $sale->transaction_date,
                'currency_code' => strtoupper((string) ($sale->currency_code ?: 'UNKNOWN')),
            ])
            ->values()
            ->all();

        $blockingReasons = [];
        if ($openShifts->isNotEmpty()) {
            $blockingReasons[] = $openShifts->count()
                . ' branch shift(s) are still open. Complete cashup and close every shift before Day End.';
        }
        if ($uncashedSaleCount > 0) {
            $blockingReasons[] = $uncashedSaleCount
                . ' completed transaction(s) for trading date ' . $tradingDate
                . ' are not covered by a completed cashup for the same shift and trading date.';
        }

        return [
            'can_run_day_end' => $openShifts->isEmpty() && $uncashedSaleCount === 0,
            'trading_date' => $tradingDate,
            'open_shift_count' => $openShifts->count(),
            'blocking_open_shifts' => $openShifts->all(),
            'trading_date_sale_count' => (int) $totalSalesCount,
            'cashed_up_sale_count' => max(0, (int) $totalSalesCount - $uncashedSaleCount),
            'uncashed_sale_count' => $uncashedSaleCount,
            'uncashed_sale_without_shift_count' => (clone $uncashedSalesQuery)
                ->whereNull('sales.shift_session_id')
                ->count(),
            'uncashed_shift_sessions' => $uncashedShiftSessions,
            'uncashed_sales_by_currency' => $uncashedSalesByCurrency,
            'uncashed_sales_sample' => $uncashedSalesSample,
            'verification_errors' => [],
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyDayEndOperationalReadiness(array $verificationErrors = []): array
    {
        return [
            'can_run_day_end' => false,
            'trading_date' => null,
            'open_shift_count' => 0,
            'blocking_open_shifts' => [],
            'trading_date_sale_count' => 0,
            'cashed_up_sale_count' => 0,
            'uncashed_sale_count' => 0,
            'uncashed_sale_without_shift_count' => 0,
            'uncashed_shift_sessions' => [],
            'uncashed_sales_by_currency' => [],
            'uncashed_sales_sample' => [],
            'verification_errors' => $verificationErrors,
            'blocking_reasons' => $verificationErrors,
        ];
    }

    private function frontStockClosingExists(string $companyId, string $branchId, string $date): bool
    {
        $closing = FrontStockDailyClosing::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $date)
            ->where('stock_area', self::FRONT_STOCK_AREA)
            ->whereRaw("LOWER(COALESCE(status, 'closed')) = 'closed'")
            ->latest('updated_at')
            ->first();

        return $closing !== null
            && $this->frontStockClosingIsFresh($closing, $companyId, $branchId);
    }

    private function frontStockClosingIsFresh(
        FrontStockDailyClosing $closing,
        string $companyId,
        string $branchId
    ): bool {
        if (!Schema::hasTable('stock_movements') || !$closing->updated_at) {
            return true;
        }

        return !DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereRaw("LOWER(COALESCE(stock_area, '')) = ?", [self::FRONT_STOCK_AREA])
            ->where('created_at', '>', $closing->updated_at)
            ->where(function ($query) {
                $query->whereNull('reference_type')
                    ->orWhere('reference_type', '!=', 'front_stock_daily_closing');
            })
            ->exists();
    }

    private function frontStockClosingCalendar(string $companyId, string $branchId, string $dateFrom, string $dateTo): array
    {
        $dateFrom = Carbon::parse($dateFrom)->toDateString();
        $dateTo = Carbon::parse($dateTo)->toDateString();

        $existing = FrontStockDailyClosing::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_area', self::FRONT_STOCK_AREA)
            ->whereDate('closing_date', '>=', $dateFrom)
            ->whereDate('closing_date', '<=', $dateTo)
            ->withCount('lines')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->closing_date)->toDateString());

        $period = CarbonPeriod::create($dateFrom, $dateTo);
        $rows = [];

        foreach ($period as $date) {
            $dateString = $date->toDateString();
            $closing = $existing->get($dateString);
            $isFresh = $closing
                ? $this->frontStockClosingIsFresh($closing, $companyId, $branchId)
                : false;
            $hasClosedStatus = (bool) $closing && in_array(
                strtolower(trim((string) ($closing->status ?? 'closed'))),
                ['', 'closed'],
                true
            );
            $isClosed = $hasClosedStatus && ($dateString !== $dateFrom || $isFresh);

            $rows[] = [
                'date' => $dateString,
                'stock_area' => self::FRONT_STOCK_AREA,
                'is_closed' => $isClosed,
                'status' => $isClosed
                    ? ($closing->status ?: 'closed')
                    : ($closing && $hasClosedStatus ? 'stale_reconciliation' : 'missing'),
                'closing_id' => $closing?->id,
                'closed_by' => $closing?->closed_by,
                'closed_at' => $closing?->created_at,
                'line_count' => (int) ($closing?->lines_count ?? 0),
                'total_lines' => (int) ($closing?->total_lines ?? $closing?->lines_count ?? 0),
                'variance_lines' => (int) ($closing?->variance_lines ?? 0),
                'variance_total_value' => round((float) ($closing?->variance_total_value ?? 0), 6),
                'method' => $this->metadataArray($closing?->metadata)['closing_method'] ?? ($isClosed ? 'manual' : 'not_closed'),
                'reconciliation_is_fresh' => $isFresh,
                'requires_recount_after_stock_activity' => (bool) $closing && !$isFresh && $dateString === $dateFrom,
                'can_force_close' => !$isClosed && $dateString !== $dateFrom,
                'blocks_day_end' => $dateString === $dateFrom && !$isClosed,
            ];
        }

        return $rows;
    }

    private function buildSystemExpectedFrontStockLines(string $companyId, string $branchId, string $stockArea = 'front'): array
    {
        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('pc') ?: 'NULL';
        $query = DB::table('products as p')
            ->leftJoin('product_stocks as ps', function ($join) use ($companyId, $branchId, $stockArea) {
                $join->on('ps.product_id', '=', 'p.id')
                    ->where('ps.company_id', '=', $companyId)
                    ->where('ps.branch_id', '=', $branchId);

                if (Schema::hasColumn('product_stocks', 'stock_area')) {
                    $join->where('ps.stock_area', '=', $stockArea);
                }
            })
            ->leftJoin('product_costs as pc', function ($join) use ($companyId, $branchId) {
                $join->on('pc.product_id', '=', 'p.id')
                    ->where('pc.company_id', '=', $companyId);

                if (Schema::hasColumn('product_costs', 'branch_id')) {
                    $join->where(function ($query) use ($branchId) {
                        $query->whereNull('pc.branch_id')->orWhere('pc.branch_id', '=', $branchId);
                    });
                }
            })
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where(function ($query) {
                $query->where('p.is_active', true)->orWhereNull('p.is_active');
            })
            ->whereIn(DB::raw('LOWER(p.product_type)'), self::ALLOWED_FRONT_STOCK_CLOSING_PRODUCT_TYPES)
            ->selectRaw("
                p.id as product_id,
                p.name as product_name,
                p.code as product_code,
                p.sku as product_sku,
                p.product_type as product_type,
                COALESCE(u.code, u.name, 'EACH') as uom_code,
                COALESCE(SUM(ps.quantity_on_hand), 0) as expected_quantity,
                COALESCE(MAX(pc.average_cost), MAX(pc.last_cost), 0) as unit_cost,
                COALESCE(MAX({$costCurrency}), 'USD') as currency_code
            ")
            ->groupBy('p.id', 'p.name', 'p.code', 'p.sku', 'p.product_type', 'u.code', 'u.name')
            ->orderBy('p.name');

        return $query->get()->map(fn ($row) => [
            'product_id' => (string) $row->product_id,
            'product_name' => $row->product_name,
            'product_code' => $row->product_code,
            'product_sku' => $row->product_sku,
            'product_type' => $row->product_type,
            'uom_code' => $row->uom_code ?: 'EACH',
            'expected_quantity' => round((float) $row->expected_quantity, 6),
            'unit_cost' => round((float) $row->unit_cost, 6),
            'currency_code' => $row->currency_code ?: 'USD',
        ])->values()->all();
    }

    private function metadataArray(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_object($metadata)) {
            return (array) $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function resolveCompany(Request $request): mixed
    {
        return app()->bound('company')
            ? app('company')
            : ($request->attributes->get('company') ?? $request->company ?? null);
    }

    private function resolveTill(Request $request): mixed
    {
        return app()->bound('till')
            ? app('till')
            : ($request->attributes->get('till') ?? $request->till ?? null);
    }

    private function resolveBranchId(Request $request): ?string
    {
        $company = $this->resolveCompany($request);
        if (!$company?->id) {
            return null;
        }

        $normalize = static function (mixed $value): ?string {
            if (!is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        };

        $bodyBranchId = $normalize($request->input('branch_id'));
        $headerBranchId = $normalize($request->header('X-Branch-ID'));
        $workspaceBranchId = $normalize($request->attributes->get('business_mode_branch_id'));
        $tillBranchId = $normalize($this->resolveTill($request)?->branch_id);

        if ($bodyBranchId && $headerBranchId && $bodyBranchId !== $headerBranchId) {
            $this->rejectBranchContext(
                409,
                'The request branch does not match the active workspace branch.',
                'BRANCH_CONTEXT_CONFLICT',
                [
                    'request_branch_id' => $bodyBranchId,
                    'workspace_branch_id' => $headerBranchId,
                ]
            );
        }

        $requestedBranchId = $headerBranchId ?: $bodyBranchId;

        // A Front Office request is physically bound to its registered till.
        // Neither a body field, header, nor a mode attribute may redirect Day
        // End to another branch, even when the operator can access both.
        foreach (array_filter([
            'requested_branch_id' => $requestedBranchId,
            'workspace_branch_id' => $workspaceBranchId,
        ]) as $source => $candidateBranchId) {
            if ($tillBranchId && $candidateBranchId !== $tillBranchId) {
                $this->rejectBranchContext(
                    403,
                    'This till is registered to a different branch. Day End cannot be redirected.',
                    'TILL_BRANCH_MISMATCH',
                    [
                        'till_branch_id' => $tillBranchId,
                        $source => $candidateBranchId,
                    ]
                );
            }
        }

        if (
            !$tillBranchId
            && $workspaceBranchId
            && $requestedBranchId
            && $workspaceBranchId !== $requestedBranchId
        ) {
            $this->rejectBranchContext(
                409,
                'The selected branch does not match the active Hospitality workspace.',
                'BRANCH_CONTEXT_CONFLICT',
                [
                    'request_branch_id' => $requestedBranchId,
                    'workspace_branch_id' => $workspaceBranchId,
                ]
            );
        }

        $authoritativeBranchId = $tillBranchId ?: $workspaceBranchId ?: $requestedBranchId;
        if (!$authoritativeBranchId) {
            // Day End is branch-local and mutates stock, trading-day and cashup
            // state. Never let the generic branch scope service select the
            // first accessible branch for a multi-branch operator when the
            // request did not carry an explicit workspace/till/branch context.
            $this->rejectBranchContext(
                422,
                'Select a Hospitality branch before using trading-day operations.',
                'BRANCH_CONTEXT_REQUIRED'
            );
        }

        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchId ? [$requestedBranchId] : []
        );
        $resolvedBranchId = $normalize($scope['context_branch_id'] ?? null);

        if ($authoritativeBranchId && $resolvedBranchId !== $authoritativeBranchId) {
            $this->rejectBranchContext(
                403,
                'The selected branch is not authorised for this Day End context.',
                'BRANCH_ACCESS_DENIED',
                [
                    'branch_id' => $authoritativeBranchId,
                ]
            );
        }

        return $resolvedBranchId;
    }

    private function rejectBranchContext(
        int $status,
        string $message,
        string $code,
        array $details = []
    ): never {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            ...$details,
        ], $status));
    }

    private function edgeDayEndAuthorityResponse(string $companyId, string $branchId): ?JsonResponse
    {
        if (config('salexa_sync.deployment_mode') !== 'edge') {
            return null;
        }

        return response()->json([
            'message' => 'This branch POS is synchronized to Central Back Office. Run Day End once from Central Back Office (or a directly connected Android terminal) after every branch shift is cashed up and FRONT stock is reconciled. The completed trading date will then download to every web terminal automatically.',
            'code' => 'DAY_END_CLOUD_AUTHORITY_REQUIRED',
            'data' => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'branch_wide' => true,
                'local_day_end_started' => false,
                'suggested_action' => 'Wait for both synchronization indicators to turn green, then run Day End from the branch in Central Back Office.',
            ],
        ], 409);
    }

    private function runAuthoritativeEdgeDayEnd(
        Request $request,
        string $companyId,
        string $branchId,
        string $runKey,
        string $expectedTradingDate,
        ?string $operatorUserId
    ): JsonResponse {
        if (!$operatorUserId) {
            return response()->json([
                'message' => 'Authentication is required to initiate Day End.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $state = SyncLocalState::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNotNull('node_id')
            ->latest('updated_at')
            ->first();
        if (!$state) {
            return response()->json([
                'message' => 'This branch server is not paired with Central Back Office. Day End was not started.',
                'code' => 'DAY_END_CLOUD_PAIRING_REQUIRED',
                'data' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'local_day_end_started' => false,
                ],
            ], 409);
        }

        try {
            $result = app(SyncOrchestrator::class)->runAuthoritativeDayEnd(
                $state,
                [
                    'run_key' => $runKey,
                    'expected_trading_date' => $expectedTradingDate,
                    'operator_user_id' => $operatorUserId,
                    'source_till_id' => $this->resolveTill($request)?->id,
                    'operation_type' => 'standard',
                ],
                function () use ($request): array {
                    // The branch database owns immediate LAN-only facts such
                    // as a just-opened no-sales shift. This callback runs
                    // after the Central snapshot pull, so a closing captured
                    // by Back Office or Android is visible here as well.
                    $preflight = $this->preflight($request);
                    $body = json_decode($preflight->getContent(), true);

                    return [
                        'ready' => is_array($body)
                            && (bool) data_get($body, 'data.can_run_day_end', false),
                        'status' => $preflight->getStatusCode(),
                        'body' => is_array($body)
                            ? $body
                            : [
                                'message' => 'The branch Day End readiness result was invalid.',
                                'code' => 'DAY_END_LOCAL_PREFLIGHT_INVALID',
                            ],
                    ];
                }
            );
        } catch (SyncRunAlreadyInProgress) {
            return response()->json([
                'message' => 'Branch synchronization is already running. Day End was not started; retry when both indicators are green.',
                'code' => 'DAY_END_SYNC_IN_PROGRESS',
                'data' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'local_day_end_started' => false,
                ],
            ], 409);
        } catch (\RuntimeException $exception) {
            $pending = str_starts_with($exception->getMessage(), 'Day End is waiting for ');

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $pending ? 'DAY_END_SYNC_PENDING' : 'DAY_END_CLOUD_UNAVAILABLE',
                'data' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'local_day_end_started' => false,
                ],
            ], $pending ? 409 : 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Central Back Office could not be reached. No local Day End was started; retry with the same run key when connectivity returns.',
                'code' => 'DAY_END_CLOUD_UNAVAILABLE',
                'data' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'local_day_end_started' => false,
                ],
            ], 503);
        }

        $localPreflight = $result['local_preflight'] ?? null;
        if (is_array($localPreflight) && ! (bool) ($localPreflight['ready'] ?? false)) {
            return response()->json(
                $localPreflight['body'],
                max(409, (int) ($localPreflight['status'] ?? 409))
            );
        }

        $command = $result['command'] ?? null;
        if (!is_array($command) || !is_array($command['body'] ?? null)) {
            return response()->json([
                'message' => 'Central Back Office returned an invalid Day End response. Retry with the same run key.',
                'code' => 'DAY_END_CLOUD_RESPONSE_INVALID',
            ], 502);
        }

        $status = (int) ($command['status'] ?? 502);
        if ($status < 100 || $status > 599) {
            $status = 502;
        }

        return response()->json($command['body'], $status)
            ->header(
                'X-Salexa-Edge-Snapshot-Applied',
                (bool) ($result['snapshot_applied'] ?? false) ? '1' : '0'
            );
    }
}
