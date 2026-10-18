<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HospitalityTradingDateReportContractTest extends TestCase
{
    private string $cashupSource;
    private string $reconciliationSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashupSource = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Modules/Sales/Http/Controllers/CashupController.php'
        );
        $this->reconciliationSource = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Modules/Product/Http/Controllers/MenuRecipeDailyStockReconciliationController.php'
        );
    }

    public function test_hospitality_cashup_uses_the_canonical_branch_mode_and_authoritative_open_date(): void
    {
        $this->assertStringContainsString('CompanySettingService $companySettings', $this->cashupSource);
        $this->assertStringContainsString(
            '$this->companySettings->isHospitalityMode($companyId, $branchId)',
            $this->cashupSource
        );
        $this->assertStringContainsString("'mode_mismatch' =>", $this->cashupSource);
        $this->assertStringContainsString('resolveActiveTradingDayState($companyId, $branchId)', $this->cashupSource);
        $this->assertStringContainsString('duplicate open Hospitality trading days', $this->cashupSource);
        $this->assertStringContainsString('HospitalityTradingDatePolicy::state(', $this->cashupSource);
        $this->assertStringContainsString(
            'Cashup trading date must match the authoritative branch trading date',
            $this->cashupSource
        );

        preg_match(
            '/private function resolveCashupTradingDate\([\s\S]*?\n    }\n\n    \/\*\* @return/',
            $this->cashupSource,
            $match
        );
        $resolver = $match[0] ?? '';

        $this->assertNotSame('', $resolver);
        $hospitalityStart = strpos($resolver, "if (\$businessMode === 'fast_food_boh')");
        $retailRequestStart = strrpos($resolver, "if (\$request->filled('trading_date'))");
        $this->assertLessThan(
            $retailRequestStart,
            $hospitalityStart
        );
        $this->assertStringNotContainsString(
            'Carbon::parse($openedAt ?: $closedAt)',
            substr($resolver, $hospitalityStart, $retailRequestStart - $hospitalityStart)
        );
    }

    public function test_hospitality_cashup_history_filters_groups_and_exports_by_trading_date(): void
    {
        $this->assertStringContainsString(
            "if (\$businessMode === 'fast_food_boh' && Schema::hasColumn('pos_cashups', 'trading_date'))",
            $this->cashupSource
        );
        $this->assertStringContainsString(
            "whereDate('pos_cashups.trading_date', '>=', \$request->date_from)",
            $this->cashupSource
        );
        $this->assertStringContainsString(
            "CASE WHEN pos_cashups.business_mode = 'fast_food_boh' THEN DATE(pos_cashups.trading_date)",
            $this->cashupSource
        );
        $this->assertStringContainsString("'fast_food_boh' => 'Trading Date'", $this->cashupSource);
        $this->assertStringContainsString("\$cashupDateHeader, 'Closed At'", $this->cashupSource);

        preg_match(
            '/private function cashupBracketDate\(\$row\): string[\s\S]*?\n    }\n\n    private function cashupBracketKey/',
            $this->cashupSource,
            $match
        );
        $bracketDate = $match[0] ?? '';
        $hospitalityBranch = strstr($bracketDate, "if (\$this->cashupBracketMode(\$row) === 'fast_food_boh')");
        $hospitalityBranch = strstr((string) $hospitalityBranch, "\n        return \$this->dateText(", true);

        $this->assertStringContainsString('$row->trading_date', (string) $hospitalityBranch);
        $this->assertStringNotContainsString('$row->cashup_opened_at', (string) $hospitalityBranch);
        $this->assertStringNotContainsString('$row->created_at', (string) $hospitalityBranch);
    }

    public function test_daily_reconciliation_prefers_business_date_identity_with_legacy_window_fallback(): void
    {
        $this->assertStringContainsString("Schema::hasColumn('sales_transactions', 'trading_day_id')", $this->reconciliationSource);
        $this->assertStringContainsString("Schema::hasColumn('sales_transactions', 'business_date')", $this->reconciliationSource);
        $this->assertStringContainsString("where('s.trading_day_id', \$tradingDay->id)", $this->reconciliationSource);
        $this->assertStringContainsString("whereDate('s.business_date', \$tradingDate)", $this->reconciliationSource);
        $this->assertStringContainsString("whereNull('s.business_date')", $this->reconciliationSource);
        $this->assertStringContainsString("whereBetween('s.transaction_date', [\$start, \$end])", $this->reconciliationSource);
        $this->assertStringContainsString('HospitalityTradingDatePolicy::nextOpenDate(', $this->reconciliationSource);
        $this->assertStringContainsString('TradingDay::firstOrCreate([', $this->reconciliationSource);
        $this->assertStringContainsString('duplicate open trading days', $this->reconciliationSource);
        $this->assertStringContainsString(
            'Daily Stock Reconciliation trading date must match the authoritative branch trading date',
            $this->reconciliationSource
        );
        $this->assertStringNotContainsString(
            'Carbon::parse($date ?: now())->toDateString()',
            $this->reconciliationSource
        );
        $this->assertStringContainsString('$cell(\'Trading Date\', \'Label\')', $this->reconciliationSource);
        $this->assertStringContainsString('Trading Date: ', $this->reconciliationSource);
    }
}
