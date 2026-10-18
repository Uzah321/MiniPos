<?php

namespace Tests\Unit;

use Modules\Sales\Http\Controllers\SalesReportController;
use ReflectionMethod;
use Tests\TestCase;

class ProfitAndLossCashMovementCurrencyTest extends TestCase
{
    private const CASH_OUT_TYPES = ['cash_out', 'bank_drop', 'paid_out', 'petty_cash', 'float_out'];
    private const CASH_IN_TYPES = ['cash_in', 'float_in'];

    public function test_mixed_native_cash_movements_never_become_a_combined_report_total(): void
    {
        $summary = $this->summarize([
            ['movement_type' => 'cash_out', 'currency_code' => 'USD', 'movement_count' => 2, 'amount' => 10],
            ['movement_type' => 'cash_in', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 3],
            ['movement_type' => 'cash_out', 'currency_code' => 'ZWG', 'movement_count' => 1, 'amount' => 260],
        ], 'USD');

        $this->assertFalse($summary['currency_compatible']);
        $this->assertTrue($summary['is_mixed_currency']);
        $this->assertTrue($summary['has_unconverted_amounts']);
        $this->assertNull($summary['cashout_total']);
        $this->assertNull($summary['cashin_total']);
        $this->assertNull($summary['net_cash_movement']);
        $this->assertStringContainsString('Historical exchange-rate snapshots are not stored', $summary['message']);
        $this->assertSame([
            [
                'currency_code' => 'USD',
                'movement_count' => 3,
                'cashout_count' => 2,
                'cashin_count' => 1,
                'cashout_total' => 10.0,
                'cashin_total' => 3.0,
                'net_cash_movement' => -7.0,
            ],
            [
                'currency_code' => 'ZWG',
                'movement_count' => 1,
                'cashout_count' => 1,
                'cashin_count' => 0,
                'cashout_total' => 260.0,
                'cashin_total' => 0.0,
                'net_cash_movement' => -260.0,
            ],
        ], $summary['totals_by_currency']);
    }

    public function test_single_foreign_movement_is_still_unconverted_for_a_base_currency_report(): void
    {
        $summary = $this->summarize([
            ['movement_type' => 'cash_out', 'currency_code' => 'ZWG', 'movement_count' => 1, 'amount' => 130],
        ], 'USD');

        $this->assertFalse($summary['is_mixed_currency']);
        $this->assertFalse($summary['currency_compatible']);
        $this->assertTrue($summary['has_unconverted_amounts']);
        $this->assertNull($summary['cashout_total']);
    }

    public function test_movements_matching_the_selected_transaction_currency_keep_scalar_totals(): void
    {
        $summary = $this->summarize([
            ['movement_type' => 'cash_out', 'currency_code' => 'ZWG', 'movement_count' => 2, 'amount' => 200],
            ['movement_type' => 'cash_in', 'currency_code' => 'ZWG', 'movement_count' => 1, 'amount' => 50],
        ], 'ZWG');

        $this->assertTrue($summary['currency_compatible']);
        $this->assertFalse($summary['is_mixed_currency']);
        $this->assertFalse($summary['has_unconverted_amounts']);
        $this->assertSame(200.0, $summary['cashout_total']);
        $this->assertSame(50.0, $summary['cashin_total']);
        $this->assertSame(-150.0, $summary['net_cash_movement']);
        $this->assertNull($summary['message']);
    }

    public function test_base_currency_only_movements_can_adjust_a_base_currency_report(): void
    {
        $summary = $this->summarize([
            ['movement_type' => 'petty_cash', 'currency_code' => 'usd', 'movement_count' => 1, 'amount' => 12.5],
            ['movement_type' => 'float_in', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 2.5],
        ], 'usd');

        $this->assertTrue($summary['currency_compatible']);
        $this->assertSame('USD', $summary['report_currency_code']);
        $this->assertSame(12.5, $summary['cashout_total']);
        $this->assertSame(2.5, $summary['cashin_total']);
        $this->assertSame(-10.0, $summary['net_cash_movement']);
    }

    public function test_non_profit_and_loss_drawer_events_do_not_create_a_false_currency_conflict(): void
    {
        $summary = $this->summarize([
            ['movement_type' => 'cash_out', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 10],
            ['movement_type' => 'drawer_open', 'currency_code' => 'ZWG', 'movement_count' => 1, 'amount' => 0],
        ], 'USD');

        $this->assertTrue($summary['currency_compatible']);
        $this->assertFalse($summary['is_mixed_currency']);
        $this->assertSame(10.0, $summary['cashout_total']);
        $this->assertCount(2, $summary['summary_by_type_currency']);
        $this->assertCount(1, $summary['totals_by_currency']);
    }

    public function test_unconverted_cash_movements_make_net_profit_and_margin_null_not_zero(): void
    {
        $method = new ReflectionMethod(SalesReportController::class, 'profitAndLossAfterCashMovements');
        $method->setAccessible(true);

        $result = $method->invoke(app(SalesReportController::class), 75.0, 100.0, [
            'cashout_total' => null,
            'cashin_total' => null,
            'currency_compatible' => false,
            'is_mixed_currency' => true,
            'has_unconverted_amounts' => true,
            'message' => 'Use per-currency totals.',
        ]);

        $this->assertNull($result['cashout_total']);
        $this->assertNull($result['cashin_total']);
        $this->assertNull($result['net_cash_movement']);
        $this->assertNull($result['net_profit_after_cashouts']);
        $this->assertNull($result['net_margin_after_cashouts_percent']);
        $this->assertSame('Use per-currency totals.', $result['message']);
    }

    public function test_no_cash_movements_are_a_compatible_zero_adjustment(): void
    {
        $summary = $this->summarize([], 'USD');

        $this->assertTrue($summary['currency_compatible']);
        $this->assertSame(0.0, $summary['cashout_total']);
        $this->assertSame(0.0, $summary['cashin_total']);
        $this->assertSame(0.0, $summary['net_cash_movement']);
        $this->assertSame([], $summary['totals_by_currency']);
    }

    private function summarize(array $rows, ?string $reportCurrencyCode): array
    {
        $method = new ReflectionMethod(SalesReportController::class, 'summarizeProfitAndLossCashMovements');
        $method->setAccessible(true);

        return $method->invoke(
            app(SalesReportController::class),
            collect($rows),
            self::CASH_OUT_TYPES,
            self::CASH_IN_TYPES,
            $reportCurrencyCode
        );
    }
}
