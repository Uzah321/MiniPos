<?php

namespace Tests\Unit;

use App\Services\Shifts\ShiftPolicyService;
use Modules\Sales\Http\Controllers\CashupController;
use ReflectionMethod;
use Tests\TestCase;

class CashupMultiCurrencyTotalsTest extends TestCase
{
    public function test_multi_currency_reconciliation_never_adds_native_amounts_together(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $method = new ReflectionMethod($controller, 'buildCashupTotals');
        $method->setAccessible(true);

        $totals = $method->invoke($controller, [
            [
                'currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'expected_amount' => 10,
                'counted_amount' => 8,
                'variance' => -2,
                'expected_base_amount' => 10,
                'counted_base_amount' => 8,
            ],
            [
                'currency_code' => 'ZWG',
                'base_currency_code' => 'USD',
                'expected_amount' => 300,
                'counted_amount' => 330,
                'variance' => 30,
                'expected_base_amount' => 10,
                'counted_base_amount' => 11,
            ],
        ]);

        $this->assertTrue($totals['is_multi_currency']);
        $this->assertSame('MULTI', $totals['currency_code']);
        $this->assertNull($totals['expected_total']);
        $this->assertNull($totals['counted_total']);
        $this->assertNull($totals['total_variance']);
        $this->assertSame(20.0, $totals['base_expected_total']);
        $this->assertSame(19.0, $totals['base_counted_total']);
        $this->assertSame(-1.0, $totals['base_total_variance']);
    }

    public function test_single_currency_reconciliation_keeps_native_totals(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $method = new ReflectionMethod($controller, 'buildCashupTotals');
        $method->setAccessible(true);

        $totals = $method->invoke($controller, [[
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'expected_amount' => 10,
            'counted_amount' => 8,
            'variance' => -2,
            'expected_base_amount' => 10,
            'counted_base_amount' => 8,
        ]]);

        $this->assertFalse($totals['is_multi_currency']);
        $this->assertSame(10.0, $totals['expected_total']);
        $this->assertSame(8.0, $totals['counted_total']);
        $this->assertSame(-2.0, $totals['total_variance']);
    }

    public function test_unmatched_zero_count_does_not_poison_multi_currency_base_totals(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $varianceMethod = new ReflectionMethod($controller, 'buildCashupVariance');
        $varianceMethod->setAccessible(true);
        $totalsMethod = new ReflectionMethod($controller, 'buildCashupTotals');
        $totalsMethod->setAccessible(true);

        $expected = [
            [
                'payment_name' => 'Cash',
                'money_code' => 'cash',
                'currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'payment_count' => 1,
                'amount' => 10,
                'base_amount' => 10,
            ],
            [
                'payment_name' => 'Cash',
                'money_code' => 'cash',
                'currency_code' => 'ZAR',
                'base_currency_code' => 'USD',
                'payment_count' => 1,
                'amount' => 180,
                'base_amount' => 10,
            ],
        ];
        $counted = [
            // A zero against an expected row is a real shortage and must stay.
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD', 'amount' => 0],
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'ZAR', 'amount' => 180],
            // Active on the device, but unused by this shift.
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'GBP', 'amount' => 0],
        ];

        $variance = $varianceMethod->invoke($controller, $expected, $counted);
        $totals = $totalsMethod->invoke($controller, $variance);

        $this->assertSame(['USD', 'ZAR'], array_column($variance, 'currency_code'));
        $this->assertSame(-10.0, $variance[0]['base_variance']);
        $this->assertSame(20.0, $totals['base_expected_total']);
        $this->assertSame(10.0, $totals['base_counted_total']);
        $this->assertSame(-10.0, $totals['base_total_variance']);
    }

    public function test_real_counted_only_amount_remains_a_native_currency_discrepancy(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $method = new ReflectionMethod($controller, 'buildCashupVariance');
        $method->setAccessible(true);

        $variance = $method->invoke($controller, [], [[
            'payment_name' => 'Cash',
            'money_code' => 'cash',
            'currency_code' => 'ZAR',
            'amount' => 25,
        ]]);

        $this->assertCount(1, $variance);
        $this->assertSame('ZAR', $variance[0]['currency_code']);
        $this->assertSame(0, $variance[0]['payment_count']);
        $this->assertSame(25.0, $variance[0]['counted_amount']);
        $this->assertSame(25.0, $variance[0]['variance']);
        $this->assertNull($variance[0]['counted_base_amount']);
        $this->assertNull($variance[0]['base_variance']);
    }

    public function test_multi_currency_cash_movements_only_expose_currency_buckets(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $method = new ReflectionMethod($controller, 'cashMovementCurrencySummary');
        $method->setAccessible(true);

        $summary = $method->invoke($controller, [
            ['movement_type' => 'cash_out', 'currency_code' => 'USD', 'movement_count' => 2, 'amount' => 5],
            ['movement_type' => 'cash_in', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 2],
            ['movement_type' => 'cash_out', 'currency_code' => 'ZWG', 'movement_count' => 1, 'amount' => 150],
        ], ['cash_out'], ['cash_in']);

        $this->assertTrue($summary['is_multi_currency']);
        $this->assertSame('MULTI', $summary['currency_code']);
        $this->assertNull($summary['cashout_total']);
        $this->assertNull($summary['cashin_total']);
        $this->assertNull($summary['net_cash_movement']);
        $this->assertSame(4, $summary['movement_count']);
        $this->assertSame([
            [
                'currency_code' => 'USD',
                'movement_count' => 3,
                'cashout_count' => 2,
                'cashin_count' => 1,
                'cashout_total' => 5.0,
                'cashin_total' => 2.0,
                'net_cash_movement' => -3.0,
            ],
            [
                'currency_code' => 'ZWG',
                'movement_count' => 1,
                'cashout_count' => 1,
                'cashin_count' => 0,
                'cashout_total' => 150.0,
                'cashin_total' => 0.0,
                'net_cash_movement' => -150.0,
            ],
        ], $summary['totals_by_currency']);
    }

    public function test_single_currency_cash_movements_keep_safe_headline_totals(): void
    {
        $controller = new CashupController($this->createMock(ShiftPolicyService::class));
        $method = new ReflectionMethod($controller, 'cashMovementCurrencySummary');
        $method->setAccessible(true);

        $summary = $method->invoke($controller, [
            ['movement_type' => 'cash_out', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 5],
            ['movement_type' => 'cash_in', 'currency_code' => 'USD', 'movement_count' => 1, 'amount' => 2],
        ], ['cash_out'], ['cash_in']);

        $this->assertFalse($summary['is_multi_currency']);
        $this->assertSame('USD', $summary['currency_code']);
        $this->assertSame(5.0, $summary['cashout_total']);
        $this->assertSame(2.0, $summary['cashin_total']);
        $this->assertSame(-3.0, $summary['net_cash_movement']);
    }
}
