<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Payments\PaymentStatus;
use App\Sales\SaleStatus;
use App\Shifts\ShiftStatus;
use App\Shifts\TillStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TillCloseAndCashDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->store = Store::create(['name' => 'Test Store', 'code' => 'TST-001']);

        $this->terminal = Terminal::create([
            'store_id' => $this->store->id,
            'registration_code' => 'TERM-TEST-001',
            'name' => 'Test Terminal',
            'status' => 'active',
        ]);
    }

    private function makeCashier(): User
    {
        $cashier = User::create([
            'name' => 'Cashier One',
            'email' => 'cashier1@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1111'),
            'employee_code' => 'CSH100',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $cashier->assignRole('cashier');

        return $cashier;
    }

    private function makeManager(string $pin = '9999'): User
    {
        $manager = User::create([
            'name' => 'Manager One',
            'email' => 'manager1@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make($pin),
            'employee_code' => 'MGR100',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $manager->assignRole('manager');

        return $manager;
    }

    /**
     * @return array{shift: array, till: array}
     */
    private function openShiftAndTill(User $cashier, float $openingFloat = 200): array
    {
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');

        $till = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift['id'], 'opening_float' => $openingFloat])
            ->assertCreated()->json('till');

        return ['shift' => $shift, 'till' => $till];
    }

    public function test_paid_in_requires_manager_approval(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        ['till' => $till] = $this->openShiftAndTill($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Float top-up'])
            ->assertUnprocessable();

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Float top-up', 'manager_pin' => '9999']);

        $response->assertCreated()->assertJsonPath('cash_movement.type', 'paid_in');
        $this->assertDatabaseHas('audit_logs', ['action' => 'till.paid_in']);
    }

    public function test_paid_out_and_cash_drop_reduce_expected_cash(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        ['till' => $till] = $this->openShiftAndTill($cashier, 200);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-out", ['amount' => 30, 'reason' => 'Supplies', 'manager_pin' => '9999'])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/cash-drop", ['amount' => 50, 'manager_pin' => '9999'])
            ->assertCreated();

        // Expected cash = 200 opening - 30 paid-out - 50 cash-drop = 120.
        $count = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 120]);

        $count->assertOk()->assertJsonPath('till.variance', '0.0000');
    }

    public function test_no_sale_open_does_not_change_expected_cash(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        ['till' => $till] = $this->openShiftAndTill($cashier, 200);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/no-sale", ['reason' => 'Checking change', 'manager_pin' => '9999'])
            ->assertCreated();

        $count = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200]);

        $count->assertOk()->assertJsonPath('till.variance', '0.0000');
    }

    public function test_small_variance_lets_the_shift_proceed_to_reconciling(): void
    {
        $cashier = $this->makeCashier();
        ['till' => $till, 'shift' => $shift] = $this->openShiftAndTill($cashier, 200);

        // Threshold defaults to 5; a variance of 1 stays within it.
        $count = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 201]);

        $count->assertOk()->assertJsonPath('till.variance', '1.0000');
        $this->assertDatabaseHas('shifts', ['id' => $shift['id'], 'status' => ShiftStatus::Reconciling->value]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/close")
            ->assertOk()
            ->assertJsonPath('till.status', TillStatus::Closed->value);
    }

    public function test_large_variance_blocks_close_until_a_manager_resolves_it(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        ['till' => $till, 'shift' => $shift] = $this->openShiftAndTill($cashier, 200);

        $count = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 150]);

        $count->assertOk()->assertJsonPath('till.variance', '-50.0000');
        $this->assertDatabaseHas('shifts', ['id' => $shift['id'], 'status' => ShiftStatus::VarianceUnderReview->value]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/close")
            ->assertUnprocessable();

        $resolve = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/resolve-variance", [
                'explanation' => 'Till was short due to a miscount at open',
                'manager_pin' => '9999',
            ]);

        $resolve->assertOk();
        $this->assertDatabaseHas('shifts', ['id' => $shift['id'], 'status' => ShiftStatus::Reconciling->value]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/close")
            ->assertOk()
            ->assertJsonPath('till.status', TillStatus::Closed->value);
    }

    public function test_close_shift_requires_every_till_closed(): void
    {
        $cashier = $this->makeCashier();
        ['till' => $till, 'shift' => $shift] = $this->openShiftAndTill($cashier, 200);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/close")
            ->assertUnprocessable();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200])->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/close")
            ->assertOk()
            ->assertJsonPath('shift.status', ShiftStatus::Closed->value);
    }

    public function test_close_shift_is_blocked_by_an_active_sale(): void
    {
        $cashier = $this->makeCashier();
        ['till' => $till, 'shift' => $shift] = $this->openShiftAndTill($cashier, 200);

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200])->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/close")
            ->assertUnprocessable();
    }

    public function test_x_report_returns_totals_without_changing_state(): void
    {
        $cashier = $this->makeCashier();
        ['till' => $till] = $this->openShiftAndTill($cashier, 200);

        $response = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/tills/{$till['id']}/x-report");

        $response->assertOk()->assertJsonPath('report.opening_float', '200.0000');
        $this->assertDatabaseHas('tills', ['id' => $till['id'], 'status' => TillStatus::Open->value]);
    }

    public function test_void_cancels_a_pending_payment_and_marks_the_sale_cancelled(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier, 200);

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $item = Item::create([
            'sku' => 'SKU-VOID', 'barcode' => '6001234567890', 'name' => 'Item', 'price' => 10, 'tax_rate' => 0, 'active' => true,
        ]);
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated();

        $payment = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'card',
            'idempotency_key' => 'idem-void-1',
            'amount' => 10,
            'simulate' => 'timeout',
        ])->json('payment');

        $this->assertSame(PaymentStatus::Unknown->value, $payment['status']);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Customer left']);

        $response->assertOk()->assertJsonPath('sale.status', SaleStatus::Cancelled->value);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => PaymentStatus::Cancelled->value]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.voided']);
    }

    public function test_void_is_rejected_once_the_sale_is_already_paid(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier, 200);

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $item = Item::create([
            'sku' => 'SKU-PAID', 'barcode' => '6009876543210', 'name' => 'Item', 'price' => 10, 'tax_rate' => 0, 'active' => true,
        ]);
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'cash',
            'idempotency_key' => 'idem-paid-1',
            'amount' => 10,
            'amount_tendered' => 10,
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Too late'])
            ->assertUnprocessable();
    }
}
