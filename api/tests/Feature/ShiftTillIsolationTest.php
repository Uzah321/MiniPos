<?php

namespace Tests\Feature;

use App\Services\Auth\SuperAdminService;
use App\Services\Shifts\ShiftPolicyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PosUser;
use Modules\Sales\Http\Controllers\CashupController;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ShiftTillIsolationTest extends TestCase
{
    private const COMPANY = '00000000-0000-0000-0000-000000000101';

    private const BRANCH = '00000000-0000-0000-0000-000000000102';

    private const USER = '00000000-0000-0000-0000-000000000103';

    private const TILL_A = '00000000-0000-0000-0000-000000000104';

    private const TILL_B = '00000000-0000-0000-0000-000000000105';

    private const SHIFT_A = '00000000-0000-0000-0000-000000000106';

    private const SHIFT_B = '00000000-0000-0000-0000-000000000107';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('pos_user_id');
            $table->string('shift_name');
            $table->string('status');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_cash_drawer_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            $table->uuid('shift_session_id');
            $table->string('movement_type');
            $table->decimal('amount', 15, 4);
            $table->string('currency_code');
            $table->string('reason')->nullable();
            $table->string('notes')->nullable();
            $table->string('reference_no')->nullable();
            $table->timestamp('occurred_at');
            $table->softDeletes();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->softDeletes();
        });
        Schema::create('sales_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            $table->uuid('cashier_id');
            $table->uuid('shift_session_id');
            $table->string('status');
            $table->string('sale_type')->nullable();
            $table->boolean('is_credit_sale')->default(false);
            $table->string('currency_code')->default('USD');
            $table->string('base_currency_code')->default('USD');
            $table->decimal('subtotal_amount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('base_subtotal_amount', 15, 4)->default(0);
            $table->decimal('base_discount_amount', 15, 4)->default(0);
            $table->decimal('base_tax_amount', 15, 4)->default(0);
            $table->decimal('base_total_amount', 15, 4)->default(0);
            $table->decimal('credit_amount', 15, 4)->default(0);
            $table->decimal('credit_paid_amount', 15, 4)->default(0);
            $table->decimal('credit_balance_amount', 15, 4)->default(0);
            $table->softDeletes();
        });
        Schema::create('sales_transaction_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            $table->uuid('shift_session_id');
            $table->string('payment_name');
            $table->string('money_code');
            $table->string('currency_code');
            $table->string('base_currency_code');
            $table->decimal('amount', 15, 4);
            $table->decimal('base_amount', 15, 4);
            $table->decimal('amount_tendered', 15, 4);
            $table->decimal('change_amount', 15, 4);
        });
        DB::table('pos_users')->insert(['id' => self::USER, 'name' => 'Cashier']);
        foreach ([[self::SHIFT_A, self::TILL_A], [self::SHIFT_B, self::TILL_B]] as [$shift, $till]) {
            DB::table('pos_user_shift_sessions')->insert([
                'id' => $shift, 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
                'till_id' => $till, 'pos_user_id' => self::USER, 'shift_name' => 'morning',
                'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sales_transaction_payments');
        Schema::dropIfExists('sales_transactions');
        Schema::dropIfExists('pos_users');
        Schema::dropIfExists('pos_cash_drawer_movements');
        Schema::dropIfExists('pos_user_shift_sessions');
        parent::tearDown();
    }

    public function test_sale_and_cashup_resolve_only_the_current_till_shift(): void
    {
        $sales = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $openShift = new ReflectionMethod($sales, 'getOpenShift');
        self::assertSame(self::SHIFT_A, $openShift->invoke($sales, self::COMPANY, self::BRANCH, self::TILL_A, self::USER)->id);
        self::assertSame(self::SHIFT_B, $openShift->invoke($sales, self::COMPANY, self::BRANCH, self::TILL_B, self::USER)->id);

        $superAdmins = $this->createMock(SuperAdminService::class);
        $superAdmins->method('isSuperAdmin')->willReturn(true);
        $cashup = new CashupController($this->createMock(ShiftPolicyService::class), superAdmins: $superAdmins);
        $resolve = new ReflectionMethod($cashup, 'resolveCashupShift');
        $actor = new PosUser;
        $actor->id = self::USER;
        self::assertSame(self::SHIFT_A, $resolve->invoke($cashup, self::COMPANY, self::BRANCH, self::TILL_A, $actor, null, [])->id);
        try {
            $resolve->invoke($cashup, self::COMPANY, self::BRANCH, self::TILL_A, $actor, self::SHIFT_B, []);
            self::fail('Till A selected Till B shift.');
        } catch (NotFoundHttpException) {
            self::assertDatabaseHas('pos_user_shift_sessions', ['id' => self::SHIFT_B, 'status' => 'open']);
        }
    }

    public function test_cashup_movement_totals_ignore_other_till_even_with_same_shift_id(): void
    {
        foreach ([[self::TILL_A, '10.00'], [self::TILL_B, '90.00']] as [$till, $amount]) {
            DB::table('pos_cash_drawer_movements')->insert([
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY,
                'branch_id' => self::BRANCH, 'till_id' => $till,
                'shift_session_id' => self::SHIFT_A, 'movement_type' => 'cash_out',
                'amount' => $amount, 'currency_code' => 'USD', 'occurred_at' => now(),
            ]);
        }
        $cashup = new CashupController($this->createMock(ShiftPolicyService::class));
        $summary = (new ReflectionMethod($cashup, 'buildCashMovementSummary'))
            ->invoke($cashup, self::COMPANY, self::BRANCH, self::TILL_A, self::SHIFT_A);
        self::assertSame(10.0, $summary['cashout_total']);
        self::assertSame(1, $summary['cashout_count']);
    }

    public function test_cashup_sale_and_payment_totals_ignore_other_till_even_with_same_shift_id(): void
    {
        foreach ([[self::TILL_A, 10], [self::TILL_B, 90]] as [$till, $amount]) {
            DB::table('sales_transactions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY,
                'branch_id' => self::BRANCH, 'till_id' => $till, 'cashier_id' => self::USER,
                'shift_session_id' => self::SHIFT_A, 'status' => 'completed',
                'total_amount' => $amount, 'base_total_amount' => $amount,
            ]);
            DB::table('sales_transaction_payments')->insert([
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY,
                'branch_id' => self::BRANCH, 'till_id' => $till,
                'shift_session_id' => self::SHIFT_A, 'payment_name' => 'CASH',
                'money_code' => 'cash', 'currency_code' => 'USD', 'base_currency_code' => 'USD',
                'amount' => $amount, 'base_amount' => $amount,
                'amount_tendered' => $amount, 'change_amount' => 0,
            ]);
        }
        $cashup = new CashupController($this->createMock(ShiftPolicyService::class));
        $summary = (new ReflectionMethod($cashup, 'buildShiftSummary'))
            ->invoke($cashup, self::COMPANY, self::BRANCH, self::TILL_A, self::SHIFT_A, self::USER);
        self::assertSame(1, $summary['totals']['transaction_count']);
        self::assertSame(10.0, $summary['totals']['expected_total']);
        self::assertSame(10.0, $summary['cash']['expected_cash']);
    }
}
