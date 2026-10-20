<?php

namespace Tests\Feature;

use App\Services\Shifts\ShiftSessionOpener;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Http\Controllers\CreditCustomerController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ShiftSessionOpenerTest extends TestCase
{
    private const COMPANY = '00000000-0000-0000-0000-000000000201';

    private const BRANCH = '00000000-0000-0000-0000-000000000202';

    private const USER = '00000000-0000-0000-0000-000000000203';

    private const TILL_A = '00000000-0000-0000-0000-000000000204';

    private const TILL_B = '00000000-0000-0000-0000-000000000205';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_configuration_id')->nullable();
            $table->uuid('pos_user_id');
            $table->string('shift_name');
            $table->string('status');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 15, 2)->nullable();
            $table->decimal('closing_cash', 15, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->softDeletes();
        });
        Schema::create('shiftconfigurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->boolean('enable_shifts')->default(false);
            $table->string('shift_mode')->nullable();
            $table->string('morning_shift_start')->nullable();
            $table->string('morning_shift_end')->nullable();
            $table->string('afternoon_shift_start')->nullable();
            $table->string('afternoon_shift_end')->nullable();
            $table->string('night_shift_start')->nullable();
            $table->string('night_shift_end')->nullable();
            $table->integer('early_clock_in_minutes')->nullable();
            $table->boolean('allow_unscheduled_shifts')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        DB::table('pos_users')->insert(['id' => self::USER, 'name' => 'Cashier']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shiftconfigurations');
        Schema::dropIfExists('pos_users');
        Schema::dropIfExists('pos_user_shift_sessions');
        parent::tearDown();
    }

    public function test_shop_without_shift_rules_gets_one_all_day_session_per_till(): void
    {
        $opener = app(ShiftSessionOpener::class);

        $first = $opener->openFor(self::COMPANY, self::BRANCH, self::TILL_A, self::USER);
        $again = $opener->openFor(self::COMPANY, self::BRANCH, self::TILL_A, self::USER);
        $otherTill = $opener->openFor(self::COMPANY, self::BRANCH, self::TILL_B, self::USER);

        self::assertSame(ShiftSessionOpener::ALL_DAY_SHIFT, $first->shift_name);
        self::assertNull($first->shift_configuration_id);
        self::assertSame('open', $first->status);
        self::assertSame($first->id, $again->id);
        self::assertNotSame($first->id, $otherTill->id);
        self::assertSame(2, DB::table('pos_user_shift_sessions')->count());
    }

    public function test_shop_with_shift_rules_opens_the_current_scheduled_shift(): void
    {
        DB::table('shiftconfigurations')->insert([
            'id' => '00000000-0000-0000-0000-000000000209', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH, 'enable_shifts' => true, 'shift_mode' => 'automatic',
            'morning_shift_start' => '00:00', 'morning_shift_end' => '23:59', 'status' => 'active',
        ]);

        $session = app(ShiftSessionOpener::class)->openFor(self::COMPANY, self::BRANCH, self::TILL_A, self::USER);

        self::assertSame('morning', $session->shift_name);
        self::assertSame('00000000-0000-0000-0000-000000000209', $session->shift_configuration_id);
    }

    public function test_closed_shift_is_replaced_by_a_new_open_one(): void
    {
        $opener = app(ShiftSessionOpener::class);
        $closed = $opener->openFor(self::COMPANY, self::BRANCH, self::TILL_A, self::USER);
        DB::table('pos_user_shift_sessions')->where('id', $closed->id)
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $next = $opener->openFor(self::COMPANY, self::BRANCH, self::TILL_A, self::USER);

        self::assertNotSame($closed->id, $next->id);
        self::assertSame('open', $next->status);
    }

    public function test_till_account_work_opens_the_missing_shift_instead_of_failing(): void
    {
        $controller = (new ReflectionClass(CreditCustomerController::class))->newInstanceWithoutConstructor();
        $activeShift = new ReflectionMethod($controller, 'activeShiftFor');

        $shift = $activeShift->invoke($controller, self::COMPANY, self::BRANCH, self::USER, self::TILL_A);

        self::assertNotNull($shift);
        self::assertSame(self::TILL_A, $shift->till_id);
        self::assertSame(ShiftSessionOpener::ALL_DAY_SHIFT, $shift->shift_name);
    }
}
