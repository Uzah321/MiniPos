<?php

namespace Tests\Unit;

use App\Services\Sales\CreditCustomerService;
use Illuminate\Support\Collection;
use Modules\Sales\Http\Controllers\CreditCustomerController;
use Modules\Sales\Http\Controllers\SalesController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CreditCustomerServiceTest extends TestCase
{
    private CreditCustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CreditCustomerService();
    }

    public function test_payment_first_settles_debt_then_creates_only_incremental_credit(): void
    {
        $impact = $this->service->calculatePaymentImpact(30, 50);

        $this->assertSame(30.0, $impact['previous_balance']);
        $this->assertSame(-20.0, $impact['new_balance']);
        $this->assertSame(30.0, $impact['applied_to_existing_debt']);
        $this->assertSame(20.0, $impact['overpayment_credit_created']);
        $this->assertSame(20.0, $impact['customer_credit_after_payment']);
    }

    public function test_existing_credit_is_kept_separate_from_credit_created_by_new_payment(): void
    {
        $impact = $this->service->calculatePaymentImpact(-10, 20);

        $this->assertSame(-30.0, $impact['new_balance']);
        $this->assertSame(0.0, $impact['applied_to_existing_debt']);
        $this->assertSame(20.0, $impact['overpayment_credit_created']);
        $this->assertSame(30.0, $impact['customer_credit_after_payment']);
    }

    public function test_partial_payment_does_not_create_customer_credit(): void
    {
        $impact = $this->service->calculatePaymentImpact(100, 25);

        $this->assertSame(75.0, $impact['new_balance']);
        $this->assertSame(25.0, $impact['applied_to_existing_debt']);
        $this->assertSame(0.0, $impact['overpayment_credit_created']);
        $this->assertSame(0.0, $impact['customer_credit_after_payment']);
    }

    public function test_payment_impact_rejects_zero_or_negative_account_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculatePaymentImpact(20, 0);
    }

    public function test_approved_account_requires_explicit_confirmation_before_creating_credit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Confirm the overpayment');

        $this->service->assessPaymentPolicy('approved', true, 10, 15, false);
    }

    public function test_approved_account_can_create_credit_after_confirmation(): void
    {
        $impact = $this->service->assessPaymentPolicy('approved', true, 10, 15, true);

        $this->assertSame(10.0, $impact['applied_to_existing_debt']);
        $this->assertSame(5.0, $impact['overpayment_credit_created']);
        $this->assertSame(-5.0, $impact['new_balance']);
    }

    public function test_blocked_account_can_only_pay_up_to_existing_debt(): void
    {
        $settlement = $this->service->assessPaymentPolicy('blocked', true, 20, 20, false);
        $this->assertSame(0.0, $settlement['new_balance']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be overpaid');
        $this->service->assessPaymentPolicy('blocked', true, 20, 20.01, true);
    }

    public function test_pending_account_cannot_receive_payment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pending customer accounts');

        $this->service->assessPaymentPolicy('pending', true, 20, 10, false);
    }

    public function test_credit_card_tender_is_not_mistaken_for_customer_account_credit(): void
    {
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'isCustomerCreditPaymentType');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($controller, (object) [
            'money_code' => 'credit_card',
            'name' => 'Credit Card',
        ]));
        $this->assertTrue($method->invoke($controller, (object) [
            'money_code' => 'customer_credit',
            'name' => 'Customer Account',
        ]));
    }

    public function test_http_overpayment_contract_exposes_confirmation_but_not_the_internal_override_flag(): void
    {
        $source = $this->methodSource(CreditCustomerController::class, 'recordPayment');

        $this->assertStringContainsString("'confirm_overpayment' => ['nullable', 'boolean']", $source);
        $this->assertStringContainsString(
            "'allow_account_credit' => \$request->boolean('confirm_overpayment')",
            $source
        );
        $this->assertStringNotContainsString("'allow_account_credit' => ['", $source);
    }

    public function test_sale_payment_classification_uses_real_payment_rows_and_requires_primary_match(): void
    {
        $source = $this->methodSource(SalesController::class, 'store');

        $this->assertStringContainsString("\$paymentRows = collect(\$request->input('payments', []))", $source);
        $this->assertStringContainsString(
            "\$paymentType = \$paymentTypesById->get(\$paymentRows->first()['payment_type_id'])",
            $source
        );
        $this->assertStringContainsString('The primary payment type does not match the first payment row.', $source);
        $this->assertStringContainsString('Customer account tender must be the only payment row on a true credit sale.', $source);
    }

    public function test_duplicate_sale_lookup_and_unique_key_race_both_return_the_full_receipt_contract(): void
    {
        $storeSource = $this->methodSource(SalesController::class, 'store');
        $replaySource = $this->methodSource(SalesController::class, 'buildDuplicateSaleCreatedResponse');

        $this->assertGreaterThanOrEqual(2, substr_count($storeSource, 'buildDuplicateSaleCreatedResponse'));
        $this->assertStringContainsString("->where('client_sale_id', \$request->client_sale_id)", $storeSource);
        $this->assertStringContainsString('$this->buildSaleCreatedResponse(', $replaySource);
        $this->assertStringContainsString('duplicate: true', $replaySource);
    }

    public function test_credit_report_never_combines_money_from_different_currencies(): void
    {
        $controller = (new ReflectionClass(CreditCustomerController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'currencySafeCreditReportSummary');
        $mixed = new Collection([
            [
                'currency_code' => 'USD',
                'credit_limit' => 100,
                'net_balance' => 40,
                'current_balance' => 40,
                'amount_due' => 40,
                'customer_credit' => 0,
                'available_credit' => 60,
            ],
            [
                'currency_code' => 'ZWG',
                'credit_limit' => 3000,
                'net_balance' => 900,
                'current_balance' => 900,
                'amount_due' => 900,
                'customer_credit' => 0,
                'available_credit' => 2100,
            ],
        ]);

        $summary = $method->invoke($controller, 2, $mixed);

        $this->assertTrue($summary['has_mixed_currencies']);
        $this->assertNull($summary['currency_code']);
        $this->assertNull($summary['credit_limit']);
        $this->assertNull($summary['net_balance']);
        $this->assertNull($summary['amount_due']);
        $this->assertStringContainsString('summary_by_currency', $summary['mixed_currency_message']);

        $single = $method->invoke($controller, 1, new Collection([$mixed->first()]));
        $this->assertFalse($single['has_mixed_currencies']);
        $this->assertSame('USD', $single['currency_code']);
        $this->assertSame(100.0, $single['credit_limit']);
        $this->assertSame(40.0, $single['net_balance']);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
