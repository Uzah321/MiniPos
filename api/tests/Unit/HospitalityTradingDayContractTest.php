<?php

namespace Tests\Unit;

use App\Http\Middleware\BlockPosWhenDayEndRunning;
use Modules\Product\Http\Controllers\TradingDayEndController;
use Modules\Product\Http\Controllers\MenuRecipeDailyStockReconciliationController;
use Modules\Sales\Http\Controllers\SalesController;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Support\FoodIndustryTradingDaySupport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class HospitalityTradingDayContractTest extends TestCase
{
    public function test_day_end_exposes_ordered_progress_stages_for_polling(): void
    {
        $controller = new TradingDayEndController;
        $method = new ReflectionMethod($controller, 'dayEndProgressStages');
        $method->setAccessible(true);
        $stages = $method->invoke($controller);

        $this->assertSame(
            ['preflight', 'lock', 'reconciliation', 'close', 'advance', 'complete'],
            array_column($stages, 'key')
        );
        $this->assertSame([10, 25, 55, 78, 90, 100], array_column($stages, 'percent'));
    }

    public function test_day_end_contract_is_till_free_idempotent_and_future_safe(): void
    {
        $source = $this->source(TradingDayEndController::class);

        $this->assertStringContainsString("'run_key' => ['nullable', 'string', 'max:80'", $source);
        $this->assertStringContainsString("->where('lock_token', \$runKey)", $source);
        $this->assertStringContainsString('completedDayEndResponse', $source);
        $this->assertStringContainsString('HospitalityTradingDatePolicy::nextOpenDate', $source);
        $this->assertStringContainsString("'code' => 'FUTURE_TRADING_DATE'", $source);
        $this->assertStringContainsString("Cache::store('file')", $source);
        $this->assertStringContainsString('acquireDayEndRuntimeLock', $source);
        $this->assertStringContainsString('firstOrCreate', $source);
    }

    public function test_completed_cloud_day_end_publishes_the_branch_snapshot_after_commit(): void
    {
        $runSource = $this->methodSource(TradingDayEndController::class, 'runDayEnd');
        $publishSource = $this->methodSource(
            TradingDayEndController::class,
            'publishCompletedDayEndSnapshot'
        );
        $transactionEnd = strpos($runSource, '}, 1);');
        $publish = strpos(
            $runSource,
            '$this->publishCompletedDayEndSnapshot((string) $company->id, $branchId);',
            $transactionEnd
        );

        $this->assertIsInt($transactionEnd);
        $this->assertIsInt($publish);
        $this->assertGreaterThan($transactionEnd, $publish);
        $this->assertStringContainsString(
            'SyncConfigurationService::class)->publishBranchSnapshot($companyId, $branchId)',
            $publishSource
        );
        $this->assertStringContainsString('catch (Throwable $exception)', $publishSource);
        $this->assertStringContainsString('report($exception)', $publishSource);
        $catchUpSource = $this->methodSource(TradingDayEndController::class, 'catchUpToSystemDate');
        $this->assertStringContainsString(
            '$this->publishCompletedDayEndSnapshot((string) $company->id, $branchId);',
            $catchUpSource
        );
    }

    public function test_current_endpoint_contract_distinguishes_system_and_business_dates(): void
    {
        $source = $this->source(TradingDayEndController::class);

        foreach ([
            "'system_date'",
            "'trading_date'",
            "'business_date'",
            "'is_future'",
            "'is_past'",
            "'sale_allowed'",
            "'block_reason'",
            "'awaiting_next_system_date'",
        ] as $field) {
            $this->assertStringContainsString($field, $source);
        }
    }

    public function test_sales_persist_the_authoritative_business_date_and_block_invalid_days(): void
    {
        $traitSource = $this->source(FoodIndustryTradingDaySupport::class);
        $controllerSource = $this->source(SalesController::class);
        $transaction = new SalesTransaction;

        $this->assertContains('business_date', $transaction->getFillable());
        $this->assertContains('trading_day_id', $transaction->getFillable());
        $this->assertStringContainsString('No valid Hospitality trading date is open', $traitSource);
        $this->assertStringContainsString('Multiple open Hospitality trading dates exist', $traitSource);
        $this->assertStringContainsString('HospitalityTradingDatePolicy::nextOpenDate', $traitSource);
        $this->assertGreaterThanOrEqual(2, substr_count($controllerSource, 'HOSPITALITY_TRADING_DATE_BLOCKED'));
        $this->assertStringContainsString('Cancellation rejected by trading day control.', $controllerSource);
    }

    public function test_pos_runtime_lock_is_visible_outside_the_atomic_day_end_transaction(): void
    {
        $source = $this->source(BlockPosWhenDayEndRunning::class);

        $this->assertStringContainsString("Cache::store('file')", $source);
        $this->assertStringContainsString('HospitalityTradingDatePolicy::runtimeLockCacheKey', $source);
        $this->assertStringContainsString("'code' => 'DAY_END_RUNNING'", $source);
    }

    public function test_routes_keep_current_and_progress_readable_but_protect_mutations(): void
    {
        $controllerPath = (new ReflectionClass(TradingDayEndController::class))->getFileName();
        $source = file_get_contents(dirname($controllerPath, 5) . '/Modules/Product/routes/api.php');

        $this->assertStringContainsString("Route::get('/trading-day/current'", $source);
        $this->assertStringContainsString("Route::get('/trading-day/day-end/progress/{runKey}'", $source);
        $this->assertStringContainsString("Route::middleware('company.permission:general_procedure')->group", $source);
        $this->assertStringContainsString("Route::post('/trading-day/day-end'", $source);
    }

    public function test_day_end_requires_the_confirmed_date_and_rejects_a_stale_concurrent_request(): void
    {
        $source = $this->source(TradingDayEndController::class);

        $this->assertStringContainsString(
            "'expected_trading_date' => ['required', 'date_format:Y-m-d']",
            $source
        );
        $this->assertStringContainsString("'code' => 'STALE_TRADING_DATE'", $source);
        $this->assertStringContainsString("\$tradingDate !== \$expectedTradingDate", $source);
        $this->assertStringContainsString("\$tradingDate !== \$observedTradingDate", $source);
        $this->assertStringContainsString('serializedRunByKey', $source);
        $this->assertStringContainsString('refresh_required', $source);
    }

    public function test_day_end_blocks_open_shifts_and_sales_without_a_completed_same_date_cashup(): void
    {
        $controller = new TradingDayEndController;
        $emptyMethod = new ReflectionMethod($controller, 'emptyDayEndOperationalReadiness');
        $emptyMethod->setAccessible(true);
        $empty = $emptyMethod->invoke($controller, ['Cashup schema cannot be verified.']);
        $source = $this->source(TradingDayEndController::class);

        $this->assertFalse($empty['can_run_day_end']);
        $this->assertSame(['Cashup schema cannot be verified.'], $empty['verification_errors']);
        $this->assertArrayHasKey('blocking_open_shifts', $empty);
        $this->assertArrayHasKey('uncashed_sale_count', $empty);
        $this->assertStringContainsString('dayEndOperationalReadiness', $source);
        $this->assertStringContainsString("'pos_user_shift_sessions'", $source);
        $this->assertStringContainsString("->whereIn(DB::raw('LOWER(cashups.status)'), ['closed', 'approved'])", $source);
        $this->assertStringContainsString("->whereDate('cashups.trading_date', \$tradingDate)", $source);
        $this->assertStringContainsString("'code' => 'DAY_END_SHIFT_CASHUP_REQUIRED'", $source);
        $this->assertStringContainsString("'operational_readiness' => \$operationalReadiness", $source);
    }

    public function test_locked_operational_readiness_cannot_be_bypassed_by_the_stock_closing_override(): void
    {
        $source = $this->source(TradingDayEndController::class);
        $dayLockPosition = strpos($source, '$day = $this->activeOpenTradingDayForUpdate(');
        $readinessPosition = strpos(
            $source,
            '$operationalReadiness = $this->dayEndOperationalReadiness(',
            $dayLockPosition
        );
        $readinessBlockPosition = strpos(
            $source,
            "if (!(\$operationalReadiness['can_run_day_end'] ?? false))",
            $readinessPosition
        );
        $manualOverridePosition = strpos(
            $source,
            'if (!$manualClosingExists)',
            $readinessPosition
        );
        $this->assertIsInt($dayLockPosition);
        $this->assertIsInt($readinessPosition);
        $this->assertIsInt($readinessBlockPosition);
        $this->assertIsInt($manualOverridePosition);
        $this->assertLessThan($readinessPosition, $dayLockPosition);
        $this->assertLessThan($readinessBlockPosition, $readinessPosition);
        $this->assertLessThan($manualOverridePosition, $readinessBlockPosition);
    }

    public function test_blocked_day_end_is_retriable_without_advancing_or_leaving_a_runtime_lock(): void
    {
        $source = $this->source(TradingDayEndController::class);
        $readinessBlockStart = strpos(
            $source,
            "if (!(\$operationalReadiness['can_run_day_end'] ?? false))"
        );
        $manualClosingStart = strpos(
            $source,
            '$manualClosingExists = $this->frontStockClosingExists',
            $readinessBlockStart
        );
        $blockedBranch = substr(
            $source,
            $readinessBlockStart,
            $manualClosingStart - $readinessBlockStart
        );
        $retryBranchStart = strpos($source, '} else {', strpos($source, 'if (!$run) {'));
        $retryUpdateStart = strpos($source, '$run->update([', $retryBranchStart);
        $retryUpdateEnd = strpos($source, '// Recheck shifts and cashups', $retryUpdateStart);
        $retryUpdate = substr($source, $retryUpdateStart, $retryUpdateEnd - $retryUpdateStart);

        $this->assertStringContainsString("'status' => 'blocked'", $blockedBranch);
        $this->assertStringContainsString("'lock_released_at' => now()", $blockedBranch);
        $this->assertStringNotContainsString('acquireDayEndRuntimeLock', $blockedBranch);
        $this->assertStringNotContainsString("'status' => 'day_end_running'", $blockedBranch);
        $this->assertStringContainsString("'failed_at' => null", $retryUpdate);
        $this->assertStringContainsString("'failure_reason' => null", $retryUpdate);
        $this->assertStringContainsString("'lock_released_at' => null", $retryUpdate);
        $this->assertStringContainsString("'operational_readiness_at_close'", $source);
    }

    public function test_day_end_state_cache_reconciliation_and_database_work_are_branch_isolated(): void
    {
        $controller = new TradingDayEndController;
        $progressKey = new ReflectionMethod($controller, 'dayEndProgressCacheKey');
        $progressKey->setAccessible(true);
        $runtimeKey = new ReflectionMethod($controller, 'dayEndRuntimeLockCacheKey');
        $runtimeKey->setAccessible(true);

        $this->assertNotSame(
            $progressKey->invoke($controller, 'company-a', 'branch-a', 'run-1'),
            $progressKey->invoke($controller, 'company-a', 'branch-b', 'run-1')
        );
        $this->assertNotSame(
            $runtimeKey->invoke($controller, 'company-a', 'branch-a'),
            $runtimeKey->invoke($controller, 'company-a', 'branch-b')
        );

        foreach ([
            'currentOrCreateOpenTradingDay',
            'activeOpenTradingDay',
            'activeOpenTradingDayForUpdate',
            'openNextTradingDay',
            'frontStockClosingExists',
            'frontStockClosingCalendar',
            'recordFailedDayEnd',
            'dayEndOperationalReadiness',
        ] as $method) {
            $methodSource = $this->methodSource(TradingDayEndController::class, $method);
            $this->assertStringContainsString('company_id', $methodSource, "{$method} must scope company data.");
            $this->assertStringContainsString('branch_id', $methodSource, "{$method} must scope branch data.");
        }

        $runSource = str_replace("\r\n", "\n", $this->methodSource(TradingDayEndController::class, 'runDayEnd'));
        $this->assertStringContainsString(
            '$reconciliation->finalizeForTradingDay($company->id, $branchId, $day, $userId)',
            $runSource
        );
        $this->assertStringContainsString(
            "\$this->openNextTradingDay(\n                        \$company->id,\n                        \$branchId,",
            $runSource
        );
        $this->assertStringContainsString(
            "\$this->acquireDayEndRuntimeLock(\n                    \$company->id,\n                    \$branchId,",
            $runSource
        );

        $reconciliationSource = $this->methodSource(
            MenuRecipeDailyStockReconciliationController::class,
            'finalizeForTradingDay'
        );
        $this->assertGreaterThanOrEqual(2, substr_count($reconciliationSource, "'company_id' => \$companyId"));
        $this->assertGreaterThanOrEqual(2, substr_count($reconciliationSource, "'branch_id' => \$branchId"));
        $this->assertStringContainsString("->where('company_id', \$companyId)", $reconciliationSource);
        $this->assertStringContainsString("->where('branch_id', \$branchId)", $reconciliationSource);
    }

    public function test_branch_a_saved_reconciliation_and_recipe_cost_cannot_read_branch_b(): void
    {
        $savedQuery = $this->methodSource(
            MenuRecipeDailyStockReconciliationController::class,
            'savedReportQuery'
        );
        $this->assertGreaterThanOrEqual(2, substr_count($savedQuery, "->where('company_id', \$companyId)"));
        $this->assertGreaterThanOrEqual(2, substr_count($savedQuery, "->where('branch_id', \$branchId)"));

        foreach (['showSaved', 'downloadExcel', 'downloadPdf'] as $method) {
            $source = $this->methodSource(MenuRecipeDailyStockReconciliationController::class, $method);
            $this->assertStringContainsString('$branchId = $this->resolveBranchId($request)', $source);
            $this->assertStringContainsString('$this->savedReportQuery($company->id, $branchId)', $source);
        }

        $payloadSource = $this->methodSource(
            MenuRecipeDailyStockReconciliationController::class,
            'buildReportPayload'
        );
        $this->assertStringContainsString('$this->recipeIngredientRows(', $payloadSource);
        $this->assertStringContainsString('$branchId,', $payloadSource);

        $costSource = $this->methodSource(
            MenuRecipeDailyStockReconciliationController::class,
            'recipeIngredientRows'
        );
        $this->assertStringContainsString("->where('pc_branch.company_id', '=', \$companyId)", $costSource);
        $this->assertStringContainsString("->where('pc_branch.branch_id', '=', \$branchId)", $costSource);
        $this->assertStringContainsString("->where('pc_global.company_id', '=', \$companyId)", $costSource);
        $this->assertStringContainsString("->whereNull('pc_global.branch_id')", $costSource);
        $this->assertStringNotContainsString("->where('pc_global.branch_id', '=',", $costSource);
        $this->assertStringContainsString(
            '$unitCostExpression = "COALESCE({$branchAverage}, {$branchLast}, {$globalAverage}, {$globalLast}, 0)"',
            $costSource
        );
        $this->assertStringContainsString(
            '$currencyExpression = \'CASE WHEN \' . $branchCostExpression',
            $costSource
        );
    }

    public function test_catch_up_contract_closes_the_accumulated_day_and_audits_skipped_dates(): void
    {
        $controller = new TradingDayEndController;
        $detailsMethod = new ReflectionMethod($controller, 'catchUpDetails');
        $detailsMethod->setAccessible(true);
        $details = $detailsMethod->invoke($controller, '2026-07-31', '2026-10-02', 'open');

        $this->assertSame([
            'available' => true,
            'lag_days' => 63,
            'source_trading_date' => '2026-07-31',
            'target_system_date' => '2026-10-02',
            'skipped_date_from' => '2026-08-01',
            'skipped_date_to' => '2026-10-01',
            'skipped_day_count' => 62,
            'requires_reason' => true,
        ], $details);
        $this->assertFalse(
            $detailsMethod->invoke($controller, '2026-07-31', '2026-10-02', 'day_end_running')['available']
        );
        $this->assertFalse(
            $detailsMethod->invoke($controller, '2026-10-02', '2026-10-02', 'open')['available']
        );

        $catchUpSource = $this->methodSource(TradingDayEndController::class, 'catchUpToSystemDate');
        $this->assertStringContainsString("'confirmation_token' => ['required', 'in:CATCH_UP_TO_SYSTEM_DATE']", $catchUpSource);
        $this->assertStringContainsString("'expected_system_date' => ['required', 'date_format:Y-m-d']", $catchUpSource);
        $this->assertStringContainsString("'reason' => ['required', 'string', 'min:5'", $catchUpSource);
        $this->assertStringContainsString("'allow_without_manual_closing' => ['prohibited']", $catchUpSource);
        $this->assertStringNotContainsString("\$request->merge(['allow_without_manual_closing'", $catchUpSource);
        $this->assertStringContainsString('completedDayEndResponse($existingRun, $runKey, true)', $catchUpSource);
        $this->assertLessThan(
            strpos($catchUpSource, 'if ($expectedSystemDate !== $systemDate)'),
            strpos($catchUpSource, 'completedDayEndResponse($existingRun, $runKey, true)')
        );

        $runSource = $this->methodSource(TradingDayEndController::class, 'runDayEnd');
        $this->assertStringContainsString("'allow_without_manual_closing' => ['prohibited']", $runSource);
        $this->assertStringContainsString('$confirmedSystemDate !== $systemDateAtLock', $runSource);
        $this->assertStringContainsString("? \$catchUp['target_system_date']", $runSource);
        $this->assertStringContainsString("'closed_by_operation' => 'catch_up'", $runSource);
        $this->assertStringContainsString("'opened_by_operation' => 'catch_up'", $runSource);
        $this->assertStringContainsString("'skipped_day_count' => \$catchUp['skipped_day_count']", $runSource);
        $this->assertStringContainsString("'reason' => \$operationRequest['reason']", $runSource);
        $this->assertLessThan(
            strpos($runSource, "\$day->update([\n                    'status' => 'closed'"),
            strpos($runSource, '$report = $reconciliation->finalizeForTradingDay')
        );
        $this->assertStringNotContainsString('business_date =', $runSource);
    }

    public function test_catch_up_route_is_permission_protected_and_idempotency_cannot_change_operation_type(): void
    {
        $controllerPath = (new ReflectionClass(TradingDayEndController::class))->getFileName();
        $routes = file_get_contents(dirname($controllerPath, 5) . '/Modules/Product/routes/api.php');
        $source = $this->source(TradingDayEndController::class);

        $this->assertStringContainsString(
            "Route::post('/trading-day/day-end/catch-up', [TradingDayEndController::class, 'catchUpToSystemDate'])",
            $routes
        );
        $this->assertStringContainsString("Route::middleware('company.permission:general_procedure')->group", $routes);
        $this->assertStringContainsString("'code' => 'IDEMPOTENCY_OPERATION_MISMATCH'", $source);
        $this->assertStringContainsString('operationTypeForRun($existingRun)', $source);
        $this->assertStringContainsString("'operation_type' => 'catch_up'", $source);
    }

    public function test_missing_stock_closing_backfill_never_claims_to_advance_the_business_date(): void
    {
        $source = $this->methodSource(
            TradingDayEndController::class,
            'forceMissingFrontStockClosings'
        );

        $this->assertStringContainsString("'operation' => 'front_stock_closing_backfill'", $source);
        $this->assertStringContainsString("'trading_day_advanced' => false", $source);
        $this->assertStringContainsString("'day_end_required' => true", $source);
        $this->assertStringContainsString("'active_trading_date' => \$activeTradingDate", $source);
        $this->assertStringContainsString(
            'it did not close or advance the branch business date',
            $source
        );
        $this->assertStringContainsString(
            'until Run Day End or Catch Up to Today completes successfully',
            $source
        );
        $this->assertStringNotContainsString("'status' => 'closed',\n                    'closed_at'", $source);
        $this->assertStringNotContainsString('openNextTradingDay(', $source);
    }

    private function source(string $class): string
    {
        return file_get_contents((new ReflectionClass($class))->getFileName());
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((new ReflectionClass($class))->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
