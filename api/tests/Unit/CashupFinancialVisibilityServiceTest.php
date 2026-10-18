<?php

namespace Tests\Unit;

use App\Services\Cashups\CashupFinancialVisibilityService;
use Tests\TestCase;

class CashupFinancialVisibilityServiceTest extends TestCase
{
    public function test_it_redacts_cashup_money_recursively_without_removing_operational_audit_fields(): void
    {
        $service = new CashupFinancialVisibilityService();

        $payload = [
            'id' => 'cashup-1',
            'status' => 'closed',
            'transaction_count' => 4,
            'expected_total' => 125.50,
            'counted_total' => 124.00,
            'total_variance' => -1.50,
            'currency_summary' => [[
                'currency_code' => 'USD',
                'payment_count' => 4,
                'expected_amount' => 125.50,
                'expected_base_amount' => 125.50,
                'counted_amount' => 124.00,
                'variance' => -1.50,
            ]],
            'sales_summary' => [
                'totals' => ['subtotal' => 100, 'tax' => 25.50],
                'credit' => ['credit_sales_total' => 30, 'collections_count' => 1],
            ],
            'denominations' => [[
                'currency_code' => 'USD',
                'denomination_value' => 20,
                'quantity' => 6,
                'line_total' => 120,
                'type' => 'note',
            ]],
        ];

        $redacted = $service->redact($payload);

        $this->assertSame('cashup-1', $redacted['id']);
        $this->assertSame('closed', $redacted['status']);
        $this->assertSame(4, $redacted['transaction_count']);
        $this->assertNull($redacted['expected_total']);
        $this->assertNull($redacted['counted_total']);
        $this->assertNull($redacted['total_variance']);
        $this->assertSame('USD', $redacted['currency_summary'][0]['currency_code']);
        $this->assertSame(4, $redacted['currency_summary'][0]['payment_count']);
        $this->assertNull($redacted['currency_summary'][0]['expected_amount']);
        $this->assertNull($redacted['currency_summary'][0]['expected_base_amount']);
        $this->assertNull($redacted['currency_summary'][0]['counted_amount']);
        $this->assertNull($redacted['currency_summary'][0]['variance']);
        $this->assertNull($redacted['sales_summary']['totals']['subtotal']);
        $this->assertNull($redacted['sales_summary']['totals']['tax']);
        $this->assertNull($redacted['sales_summary']['credit']['credit_sales_total']);
        $this->assertSame(1, $redacted['sales_summary']['credit']['collections_count']);
        $this->assertSame('USD', $redacted['denominations'][0]['currency_code']);
        $this->assertSame('note', $redacted['denominations'][0]['type']);
        $this->assertNull($redacted['denominations'][0]['denomination_value']);
        $this->assertNull($redacted['denominations'][0]['quantity']);
        $this->assertNull($redacted['denominations'][0]['line_total']);
    }

    public function test_active_summary_sets_canonical_and_compatibility_permission_flags_to_denied(): void
    {
        $service = new CashupFinancialVisibilityService();

        $redacted = $service->redactActiveSummary([
            'permissions' => ['can_cashup_on_behalf' => true],
            'totals' => ['expected_total' => 10],
        ]);

        $this->assertTrue($redacted['permissions']['can_cashup_on_behalf']);
        $this->assertFalse($redacted['permissions']['can_view_cashup_financial_amounts']);
        $this->assertFalse($redacted['permissions']['can_view_expected_cashup_amounts']);
        $this->assertNull($redacted['totals']['expected_total']);
    }

    public function test_it_redacts_shift_and_day_end_sales_amount_aliases_but_keeps_counts(): void
    {
        $service = new CashupFinancialVisibilityService();

        $payload = [
            'transaction_count' => 8,
            'payment_count' => 3,
            'cashup_count' => 2,
            'gross_sales' => 400,
            'net_sales' => 375,
            'discounts' => 25,
            'average_basket' => 46.875,
            'base_gross_sales' => 400,
            'base_net_sales' => 375,
            'base_subtotal' => 350,
            'base_discount' => 25,
            'base_tax' => 25,
            'invoiced_value' => 400,
            'base_invoiced_value' => 400,
            'cash_sale_value' => 300,
            'base_cash_sale_value' => 300,
            'credit_issued_value' => 100,
            'base_credit_issued_value' => 100,
            'credit_paid_value' => 75,
            'base_credit_paid_value' => 75,
            'credit_outstanding_value' => 25,
            'base_credit_outstanding_value' => 25,
            'money_received_value' => 375,
            'base_money_received_value' => 375,
            'sales_value' => 375,
            'base_sales_value' => 375,
            'cost_value' => 200,
            'base_cost_value' => 200,
            'profit_value' => 175,
            'base_profit_value' => 175,
            'gross_profit' => 175,
            'estimated_profit' => 175,
            'cashup_variance' => -2,
            'counted_base_amount' => 373,
            'base_variance' => -2,
            'base_expected_total' => 375,
            'base_counted_total' => 373,
            'base_total_variance' => -2,
        ];

        $redacted = $service->redact($payload);

        $this->assertSame(8, $redacted['transaction_count']);
        $this->assertSame(3, $redacted['payment_count']);
        $this->assertSame(2, $redacted['cashup_count']);

        foreach (array_diff(array_keys($payload), ['transaction_count', 'payment_count', 'cashup_count']) as $key) {
            $this->assertNull($redacted[$key], "{$key} must not leak without the cashup financial permission.");
        }
    }

    public function test_blind_active_summary_redacts_expected_amounts_for_every_tender_type(): void
    {
        $service = new CashupFinancialVisibilityService();
        $tenders = [
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD'],
            ['payment_name' => 'Visa', 'money_code' => 'card', 'currency_code' => 'USD'],
            ['payment_name' => 'EcoCash', 'money_code' => 'mobile_money', 'currency_code' => 'ZWG'],
            ['payment_name' => 'Voucher', 'money_code' => 'voucher', 'currency_code' => 'USD'],
        ];

        $payload = [
            'payments' => collect($tenders)->map(fn (array $tender, int $index) => array_merge($tender, [
                'payment_count' => $index + 1,
                'amount' => 50 + $index,
                'base_amount' => 50 + $index,
                'amount_tendered' => 60 + $index,
                'change_amount' => 10,
            ]))->all(),
            'currency_summary' => [[
                'currency_code' => 'USD',
                'expected_value' => 203,
                'difference_amount' => -2,
            ]],
        ];

        $redacted = $service->redactActiveSummary($payload);

        foreach ($redacted['payments'] as $index => $payment) {
            $this->assertSame($tenders[$index]['payment_name'], $payment['payment_name']);
            $this->assertSame($tenders[$index]['money_code'], $payment['money_code']);
            $this->assertSame($index + 1, $payment['payment_count']);
            $this->assertNull($payment['amount']);
            $this->assertNull($payment['base_amount']);
            $this->assertNull($payment['amount_tendered']);
            $this->assertNull($payment['change_amount']);
        }

        $this->assertNull($redacted['currency_summary'][0]['expected_value']);
        $this->assertNull($redacted['currency_summary'][0]['difference_amount']);
    }

    public function test_blind_cashup_errors_never_echo_expected_or_variance_amounts(): void
    {
        $service = new CashupFinancialVisibilityService();

        $redacted = $service->redactBlindCashupErrors([
            'counted_payments.0.amount' => ['The counted amount must be numeric.'],
            'expected_amount' => ['Expected amount is USD 60.00.'],
            'payment' => ['Expected USD 60.00; variance is USD -10.00.'],
            'business_mode' => ['Cashup mode does not match this branch.'],
        ]);

        $this->assertSame(
            ['The counted amount must be numeric.'],
            $redacted['counted_payments.0.amount']
        );
        $this->assertSame(
            ['Cashup values could not be validated. Review the counted payment entries and try again.'],
            $redacted['expected_amount']
        );
        $this->assertSame(
            ['Cashup values could not be validated. Review the counted payment entries and try again.'],
            $redacted['payment']
        );
        $this->assertSame(
            ['Cashup mode does not match this branch.'],
            $redacted['business_mode']
        );
    }
}
