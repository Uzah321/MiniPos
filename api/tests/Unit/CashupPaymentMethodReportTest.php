<?php

namespace Tests\Unit;

use App\Services\BusinessModeBranchScopeService;
use App\Services\Shifts\ShiftPolicyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Sales\Http\Controllers\CashupController;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class CashupPaymentMethodReportTest extends TestCase
{
    public function test_cashup_report_keeps_every_tender_separated_by_currency(): void
    {
        $tables = $this->paymentTables(collect([
            (object) [
                'id' => 'cashup-1',
                'shift_session_id' => 'shift-1',
                'business_mode' => 'retail',
                'cashup_bracket_date' => '2026-10-01',
                'cashup_opened_at' => '2026-10-01 08:00:00',
                'cashup_closed_at' => '2026-10-01 16:00:00',
                'cashier_name' => 'Cashier One',
                'till_code' => 'T01',
                'till_name' => 'Front Till',
                'status' => 'closed',
                'payment_summary' => [
                    $this->payment('Cash', 'cash', 'USD', 2, 20, 20),
                    $this->payment('EcoCash', 'mobile_money', 'ZWG', 3, 300, 300),
                    $this->payment('Visa', 'card', 'USD', 1, 15, 15),
                    $this->payment('Visa', 'card', 'ZWG', 1, 120, 110),
                ],
            ],
            (object) [
                'id' => 'cashup-2',
                'shift_session_id' => 'shift-2',
                'business_mode' => 'retail',
                'cashup_bracket_date' => '2026-10-01',
                'cashup_opened_at' => '2026-10-01 16:00:00',
                'cashup_closed_at' => '2026-10-01 23:00:00',
                'cashier_name' => 'Cashier Two',
                'till_code' => 'T02',
                'till_name' => 'Second Till',
                'status' => 'closed',
                'payment_summary' => [
                    $this->payment('Visa', 'card', 'USD', 2, 25, 25),
                ],
            ],
        ]), true, 'cashup');

        $this->assertSame(5, count($tables['rows']));
        $this->assertSame(
            ['Cash|USD', 'EcoCash|ZWG', 'Visa|USD', 'Visa|USD', 'Visa|ZWG'],
            collect($tables['rows'])->map(fn (array $row) => $row[9] . '|' . $row[11])->sort()->values()->all()
        );

        $visaUsd = collect($tables['summary_rows'])->first(
            fn (array $row) => $row[0] === 'Visa' && $row[2] === 'USD'
        );
        $this->assertNotNull($visaUsd);
        $this->assertSame(2, $visaUsd[3]);
        $this->assertSame(3, $visaUsd[4]);
        $this->assertSame('40.00', $visaUsd[5]);
        $this->assertSame('40.00', $visaUsd[6]);
        $this->assertSame('0.00', $visaUsd[7]);

        $visaZwg = collect($tables['summary_rows'])->first(
            fn (array $row) => $row[0] === 'Visa' && $row[2] === 'ZWG'
        );
        $this->assertSame('120.00', $visaZwg[5]);
        $this->assertSame('110.00', $visaZwg[6]);
        $this->assertSame('-10.00', $visaZwg[7]);
    }

    public function test_restricted_report_preserves_tender_and_count_but_redacts_amounts(): void
    {
        $tables = $this->paymentTables(collect([(object) [
            'id' => 'cashup-1',
            'shift_session_id' => 'shift-1',
            'business_mode' => 'fast_food_boh',
            'trading_date' => '2026-09-30',
            'cashup_closed_at' => '2026-10-01 01:00:00',
            'cashier_name' => 'Manager',
            'status' => 'closed',
            'payment_summary' => [
                $this->payment('Mastercard', 'card', 'USD', 4, 80, 80),
            ],
        ]]), false, 'cashup');

        $this->assertSame('Fast Food / BOH', $tables['rows'][0][0]);
        $this->assertSame('2026-09-30', $tables['rows'][0][1]);
        $this->assertSame('Mastercard', $tables['rows'][0][9]);
        $this->assertSame(4, $tables['rows'][0][12]);
        $this->assertNull($tables['rows'][0][13]);
        $this->assertNull($tables['rows'][0][14]);
        $this->assertNull($tables['rows'][0][15]);
        $this->assertSame(4, $tables['summary_rows'][0][4]);
        $this->assertNull($tables['summary_rows'][0][5]);
        $this->assertNull($tables['summary_rows'][0][6]);
        $this->assertNull($tables['summary_rows'][0][7]);
    }

    public function test_shift_report_reads_the_stored_cashup_payment_summary(): void
    {
        $tables = $this->paymentTables(collect([(object) [
            'id' => 'shift-1',
            'cashup_id' => 'cashup-1',
            'cashup_business_mode' => 'fast_food_boh',
            'cashup_trading_date' => '2026-09-30',
            'opened_at' => '2026-10-01 08:00:00',
            'closed_at' => '2026-10-01 22:00:00',
            'cashier_name' => 'Cashier One',
            'status' => 'closed',
            'cashup_payment_summary' => json_encode([
                $this->payment('Bank Transfer', 'bank_transfer', 'USD', 1, 50, 50),
                $this->payment('Voucher', 'voucher', 'USD', 2, 30, 30),
            ]),
        ]]), true, 'shift');

        $this->assertSame(2, count($tables['rows']));
        $this->assertSame(['Bank Transfer', 'Voucher'], collect($tables['rows'])->pluck(9)->all());
        $this->assertSame('2026-09-30', $tables['rows'][0][1]);
        $this->assertSame('Shifts', $tables['summary_headers'][3]);
    }

    public function test_open_shift_payment_counts_do_not_turn_pending_counts_into_zero(): void
    {
        $tables = $this->paymentTables(collect([(object) [
            'id' => 'open-shift-1',
            'cashup_id' => null,
            'cashup_business_mode' => 'retail',
            'opened_at' => '2026-10-03 08:00:00',
            'closed_at' => null,
            'cashier_name' => 'Cashier One',
            'status' => 'open',
            'cashup_payment_summary' => [[
                'payment_name' => 'Cash',
                'money_code' => 'cash',
                'currency_code' => 'USD',
                'payment_count' => 2,
                'expected_amount' => 20,
                'counted_amount' => null,
                'variance' => null,
            ]],
        ]]), true, 'shift');

        $this->assertSame('20.00', $tables['rows'][0][13]);
        $this->assertNull($tables['rows'][0][14]);
        $this->assertNull($tables['rows'][0][15]);
        $this->assertSame('20.00', $tables['summary_rows'][0][5]);
        $this->assertNull($tables['summary_rows'][0][6]);
        $this->assertNull($tables['summary_rows'][0][7]);
    }

    public function test_shift_exports_distinguish_pending_amounts_from_real_zeroes(): void
    {
        $method = new ReflectionMethod($this->controller(), 'nullableAmount');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->controller(), null));
        $this->assertSame('0.00', $method->invoke($this->controller(), 0));
        $this->assertSame('12.50', $method->invoke($this->controller(), 12.5));
    }

    public function test_cashup_currency_filtering_happens_before_pagination_metadata_is_calculated(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'paginateCashupRowsForCurrencies');
        $method->setAccessible(true);
        $request = Request::create('/cashups', 'GET', ['page' => 2, 'per_page' => 1]);
        $rows = collect([
            $this->cashupCurrencyRow('usd-only', ['USD']),
            $this->cashupCurrencyRow('zwg-only', ['ZWG']),
            $this->cashupCurrencyRow('multi', ['USD', 'ZWG']),
        ]);

        $page = $method->invoke($controller, $rows, ['ZWG'], 1, 2, $request);

        $this->assertSame(2, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame(2, $page->currentPage());
        $this->assertSame(['multi'], $page->getCollection()->pluck('id')->all());
        $this->assertSame(
            ['ZWG'],
            collect($page->items()[0]->currency_summary)->pluck('currency_code')->all()
        );
    }

    public function test_account_credit_is_reported_separately_from_received_tenders(): void
    {
        $record = (object) [
            'id' => 'cashup-1',
            'shift_session_id' => 'shift-1',
            'business_mode' => 'retail',
            'cashup_bracket_date' => '2026-10-01',
            'cashier_name' => 'Cashier One',
            'status' => 'closed',
            'payment_summary' => [
                $this->payment('Visa', 'card', 'USD', 1, 20, 20),
            ],
            'sales_summary' => [
                'credit' => [
                    'credit_sale_tender_rows' => [[
                        'payment_name' => 'Customer Account',
                        'money_code' => 'customer_credit',
                        'currency_code' => 'USD',
                        'payment_count' => 2,
                        'amount' => 45,
                    ]],
                ],
            ],
        ];

        $paymentTables = $this->paymentTables(collect([$record]), true, 'cashup');
        $creditTables = $this->accountCreditTables(collect([$record]), true, 'cashup');

        $this->assertSame(['Visa'], collect($paymentTables['rows'])->pluck(9)->all());
        $this->assertSame('Customer Account', $creditTables['rows'][0][6]);
        $this->assertSame(2, $creditTables['rows'][0][9]);
        $this->assertSame('45.00', $creditTables['rows'][0][10]);
    }

    public function test_open_shift_account_credit_summary_is_reported_without_a_cashup_snapshot(): void
    {
        $record = (object) [
            'id' => 'open-shift-1',
            'cashup_id' => null,
            'cashup_business_mode' => 'retail',
            'opened_at' => '2026-10-02 22:00:00',
            'cashier_name' => 'Cashier One',
            'status' => 'open',
            'cashup_sales_summary' => [
                'credit' => [
                    'sales_by_currency' => [[
                        'currency_code' => 'USD',
                        'credit_sale_count' => 2,
                        'credit_sales_total' => 45,
                    ]],
                    'credit_sale_tender_rows' => [],
                ],
            ],
        ];

        $creditTables = $this->accountCreditTables(collect([$record]), true, 'shift');

        $this->assertCount(1, $creditTables['rows']);
        $this->assertSame('-', $creditTables['rows'][0][2]);
        $this->assertSame('open-shift-1', $creditTables['rows'][0][3]);
        $this->assertSame(2, $creditTables['rows'][0][9]);
        $this->assertSame('45.00', $creditTables['rows'][0][10]);
    }

    public function test_cashup_report_mode_normalization_uses_the_branch_mode_vocabulary(): void
    {
        $method = new ReflectionMethod($this->controller(), 'cashupReportBusinessMode');
        $method->setAccessible(true);

        $this->assertSame('fast_food_boh', $method->invoke($this->controller(), 'hospitality'));
        $this->assertSame('fast_food_boh', $method->invoke($this->controller(), 'fast_food_boh'));
        $this->assertSame('retail', $method->invoke($this->controller(), 'retail'));
        $this->assertNull($method->invoke($this->controller(), 'unknown'));
    }

    public function test_cashup_report_scope_replaces_the_client_mode_with_the_authoritative_branch_mode(): void
    {
        $scope = $this->createMock(BusinessModeBranchScopeService::class);
        $scope->expects($this->once())
            ->method('resolve')
            ->willReturn([
                'business_mode' => 'hospitality',
                'context_branch_id' => 'branch-1',
                'branch_ids' => ['branch-1'],
                'accessible_branch_ids' => ['branch-1'],
            ]);
        $controller = new CashupController(
            $this->createMock(ShiftPolicyService::class),
            businessModeBranchScope: $scope
        );
        $request = Request::create('/shift-report', 'GET', [
            'business_mode' => 'hospitality',
            'branch_id' => 'branch-1',
        ]);
        $method = new ReflectionMethod($controller, 'resolveCashupReportBranchIds');
        $method->setAccessible(true);

        $branchIds = $method->invoke($controller, $request, 'company-1');

        $this->assertSame(['branch-1'], $branchIds);
        $this->assertSame('fast_food_boh', $request->input('business_mode'));
        $this->assertSame('fast_food_boh', $request->attributes->get('cashup_report_business_mode'));
    }

    public function test_cashup_report_scope_rejects_a_client_mode_that_conflicts_with_the_branch(): void
    {
        $scope = $this->createMock(BusinessModeBranchScopeService::class);
        $scope->method('resolve')->willReturn([
            'business_mode' => 'hospitality',
            'context_branch_id' => 'branch-1',
            'branch_ids' => ['branch-1'],
            'accessible_branch_ids' => ['branch-1'],
        ]);
        $controller = new CashupController(
            $this->createMock(ShiftPolicyService::class),
            businessModeBranchScope: $scope
        );
        $request = Request::create('/shift-report', 'GET', ['business_mode' => 'retail']);
        $method = new ReflectionMethod($controller, 'resolveCashupReportBranchIds');
        $method->setAccessible(true);

        try {
            $method->invoke($controller, $request, 'company-1');
            $this->fail('A conflicting client report mode should be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame(
                'BUSINESS_MODE_MISMATCH',
                data_get($exception->getResponse()->getData(true), 'code')
            );
        }
    }

    public function test_legacy_counted_cash_never_reconciles_against_a_non_cash_tender(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'normalizeCountedPayments');
        $method->setAccessible(true);
        $request = Request::create('/', 'POST', ['counted_cash' => 25]);
        $summary = ['payments' => [
            ['payment_name' => 'Card', 'money_code' => 'card', 'currency_code' => 'USD'],
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD'],
        ]];

        $rows = $method->invoke($controller, $request, $summary);

        $this->assertCount(1, $rows);
        $this->assertSame('Cash', $rows[0]['payment_name']);
        $this->assertSame('cash', $rows[0]['money_code']);
        $this->assertSame(25.0, $rows[0]['amount']);
    }

    public function test_legacy_counted_cash_is_merged_when_non_cash_counts_are_already_supplied(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'normalizeCountedPayments');
        $method->setAccessible(true);
        $request = Request::create('/', 'POST', [
            'counted_cash' => 25,
            'counted_payments' => [[
                'payment_name' => 'Visa',
                'money_code' => 'card',
                'currency_code' => 'USD',
                'amount' => 40,
            ]],
        ]);
        $summary = ['payments' => [
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD'],
            ['payment_name' => 'Visa', 'money_code' => 'card', 'currency_code' => 'USD'],
        ]];

        $rows = $method->invoke($controller, $request, $summary);

        $this->assertCount(2, $rows);
        $this->assertSame(['Cash', 'Visa'], collect($rows)->pluck('payment_name')->sort()->values()->all());
        $this->assertSame(25.0, collect($rows)->firstWhere('payment_name', 'Cash')['amount']);
    }

    public function test_counted_values_are_accepted_for_every_tender_type_during_blind_cashup(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'normalizeCountedPayments');
        $method->setAccessible(true);
        $request = Request::create('/', 'POST', [
            'counted_payments' => [
                ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD', 'amount' => 20],
                ['payment_name' => 'Visa', 'money_code' => 'card', 'currency_code' => 'USD', 'amount' => 30],
                ['payment_name' => 'EcoCash', 'money_code' => 'mobile_money', 'currency_code' => 'ZWG', 'amount' => 40],
                ['payment_name' => 'Voucher', 'money_code' => 'voucher', 'currency_code' => 'USD', 'amount' => 50],
            ],
        ]);

        $rows = $method->invoke($controller, $request, ['payments' => []]);

        $this->assertSame(
            [
                ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD', 'amount' => 20.0],
                ['payment_name' => 'Visa', 'money_code' => 'card', 'currency_code' => 'USD', 'amount' => 30.0],
                ['payment_name' => 'EcoCash', 'money_code' => 'mobile_money', 'currency_code' => 'ZWG', 'amount' => 40.0],
                ['payment_name' => 'Voucher', 'money_code' => 'voucher', 'currency_code' => 'USD', 'amount' => 50.0],
            ],
            $rows
        );
    }

    public function test_blind_cashup_controller_sanitizes_validation_and_internal_error_channels(): void
    {
        $source = file_get_contents(base_path('Modules/Sales/Http/Controllers/CashupController.php'));

        $this->assertGreaterThanOrEqual(2, substr_count(
            $source,
            '$this->cashupFinancialVisibility->redactBlindCashupErrors($validationErrors)'
        ));
        $this->assertStringContainsString(
            ": 'An internal error prevented the cashup from completing.'",
            $source
        );
    }

    public function test_legacy_counted_cash_requires_a_currency_when_multiple_cash_currencies_exist(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'normalizeCountedPayments');
        $method->setAccessible(true);
        $request = Request::create('/', 'POST', ['counted_cash' => 25]);
        $summary = ['payments' => [
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'USD'],
            ['payment_name' => 'Cash', 'money_code' => 'cash', 'currency_code' => 'ZWG'],
        ]];

        $this->expectException(ValidationException::class);
        $method->invoke($controller, $request, $summary);
    }

    public function test_movement_only_currency_is_kept_in_cash_reconciliation(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'buildCashByCurrency');
        $method->setAccessible(true);

        $rows = $method->invoke($controller, [[
            'payment_name' => 'Cash',
            'money_code' => 'cash',
            'currency_code' => 'USD',
            'expected_amount' => 10,
            'counted_amount' => 10,
        ]], [
            'cash_out_rows' => [[
                'currency_code' => 'ZWG',
                'amount' => 50,
            ]],
            'cash_in_rows' => [],
        ]);

        $this->assertSame(['USD', 'ZWG'], collect($rows)->pluck('currency_code')->all());
        $zwg = collect($rows)->firstWhere('currency_code', 'ZWG');
        $this->assertSame(0.0, $zwg['expected_cash']);
        $this->assertSame(50.0, $zwg['cashout_total']);
        $this->assertSame(-50.0, $zwg['expected_cash_after_cashouts']);
    }

    public function test_retail_cashup_ignores_client_trading_date_and_uses_shift_open_date(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'resolveCashupTradingDate');
        $method->setAccessible(true);
        $request = Request::create('/', 'POST', ['trading_date' => '2099-01-01']);

        $date = $method->invoke(
            $controller,
            $request,
            'retail',
            'company-1',
            'branch-1',
            '2026-09-30 22:00:00',
            Carbon::parse('2026-10-01 02:00:00')
        );

        $this->assertSame('2026-09-30', $date);
    }

    public function test_cashup_identity_matches_the_one_row_per_shift_unique_invariant(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'buildCashupIdentity');
        $method->setAccessible(true);

        $this->assertSame([
            'company_id' => 'company-1',
            'shift_session_id' => 'shift-1',
        ], $method->invoke($controller, 'company-1', 'shift-1'));
    }

    public function test_backend_cashup_and_shift_downloads_include_payment_method_sections(): void
    {
        $source = file_get_contents(base_path('Modules/Sales/Http/Controllers/CashupController.php'));

        $this->assertGreaterThanOrEqual(4, substr_count($source, "['title' => 'Payment Methods'"));
        $this->assertGreaterThanOrEqual(4, substr_count($source, "['title' => 'Payment Method Summary'"));
        $this->assertStringContainsString("as cashup_payment_summary", $source);
        $this->assertStringContainsString("'payment_method_summary_rows' =>", $source);
        $this->assertStringContainsString("whereNull('pos_cashups.id')", $source);
        $this->assertStringContainsString("\$alias = 'shift_report_trading_days'", $source);
        $this->assertStringContainsString('shift_report_sales_till.till_id', $source);
        $this->assertStringContainsString('shift_report_payment_till.till_id', $source);
        $this->assertStringContainsString('as resolved_till_id', $source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, "filled('till_id')"));
        $this->assertStringContainsString('$expectedCash + $cashIn - $cashOut', $source);

        $migration = file_get_contents(base_path(
            'Modules/Sales/database/migrations/2026_10_02_140000_harden_pos_cashup_reporting_columns.php'
        ));
        foreach ([
            'cashup_period_key',
            'base_currency_code',
            'cashout_total',
            'cashout_count',
            'expected_cash_after_cashouts',
            'cashout_summary',
            'shift_policy_snapshot',
            'business_mode_snapshot',
            'force_close_reason',
        ] as $column) {
            $this->assertStringContainsString("'{$column}'", $migration);
        }
    }

    public function test_hospitality_cashout_reports_use_the_authoritative_trading_day_contract(): void
    {
        $source = file_get_contents(base_path('Modules/Sales/Http/Controllers/CashupController.php'));

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, '$this->applyCashMovementReportModeAndDateFilters($baseQuery, $request);')
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "->on('pos_cashups.branch_id', '=', 'pos_cash_drawer_movements.branch_id')")
        );
        $this->assertStringContainsString("\$businessMode === 'fast_food_boh'", $source);
        $this->assertStringContainsString("\$request->filled('trading_date')", $source);
        $this->assertStringContainsString("\$alias = 'cashout_report_trading_days'", $source);
        $this->assertStringContainsString("'cashout_report_open_days'", $source);
        $this->assertStringContainsString('MAX({$alias}.trading_date)', $source);
        $this->assertStringContainsString('as report_business_date', $source);
        $this->assertStringContainsString("['Business Mode', 'Business Date', 'Occurred At'", $source);
        $this->assertStringContainsString('->filter(fn ($row) => empty($row->cashup_id)', $source);
    }

    public function test_open_report_rows_use_authoritative_mode_credit_and_business_date_fallbacks(): void
    {
        $source = file_get_contents(base_path('Modules/Sales/Http/Controllers/CashupController.php'));

        $this->assertStringContainsString("\$request->merge(['business_mode' => \$authoritativeMode])", $source);
        $this->assertStringContainsString("'code' => 'BUSINESS_MODE_MISMATCH'", $source);
        $this->assertStringContainsString("Schema::hasColumn('sales_transactions', 'is_credit_sale')", $source);
        $this->assertStringContainsString("\$salesSummary['credit'] = \$liveOpenShiftCredit->get", $source);
        $this->assertStringContainsString("'pos_user_shift_sessions.opened_at'", $source);
        $this->assertStringContainsString("Schema::hasTable('trading_day_ends')", $source);
        $this->assertStringContainsString("'statuses' => ['open', 'running', 'day_end_running']", $source);
    }

    private function paymentTables($records, bool $canViewFinancialAmounts, string $context): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'paymentMethodReportTables');
        $method->setAccessible(true);

        return $method->invoke($controller, $records, $canViewFinancialAmounts, $context);
    }

    private function accountCreditTables($records, bool $canViewFinancialAmounts, string $context): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'accountCreditReportTables');
        $method->setAccessible(true);

        return $method->invoke($controller, $records, $canViewFinancialAmounts, $context);
    }

    private function controller(): CashupController
    {
        return new CashupController($this->createMock(ShiftPolicyService::class));
    }

    private function cashupCurrencyRow(string $id, array $currencyCodes): object
    {
        return (object) [
            'id' => $id,
            'cashup_currency_code' => count($currencyCodes) > 1 ? 'MULTI' : $currencyCodes[0],
            'currency_summary' => collect($currencyCodes)->map(fn (string $currencyCode) => [
                'currency_code' => $currencyCode,
                'expected_amount' => 10,
                'counted_amount' => 10,
                'variance' => 0,
            ])->all(),
            'payment_summary' => [],
            'cash_by_currency' => [],
            'totals_by_currency' => [],
            'sales_summary' => [],
            'denominations' => [],
        ];
    }

    private function payment(
        string $paymentName,
        string $moneyCode,
        string $currencyCode,
        int $paymentCount,
        float $expectedAmount,
        float $countedAmount
    ): array {
        return [
            'payment_name' => $paymentName,
            'money_code' => $moneyCode,
            'currency_code' => $currencyCode,
            'payment_count' => $paymentCount,
            'expected_amount' => $expectedAmount,
            'counted_amount' => $countedAmount,
            'variance' => $countedAmount - $expectedAmount,
        ];
    }
}
