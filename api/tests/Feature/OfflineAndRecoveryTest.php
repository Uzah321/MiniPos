<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Payments\PaymentStatus;
use App\Sales\SaleStatus;
use App\Shifts\ShiftStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OfflineAndRecoveryTest extends TestCase
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

    private function makeItem(array $overrides = []): Item
    {
        return Item::create(array_merge([
            'sku' => 'SKU-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'barcode' => '600'.random_int(100000000, 999999999),
            'name' => 'Test Item',
            'price' => 10,
            'tax_rate' => 0,
            'active' => true,
        ], $overrides));
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

    public function test_offline_sale_syncs_once_and_is_idempotent_on_retry(): void
    {
        $cashier = $this->makeCashier();
        ['shift' => $shift] = $this->openShiftAndTill($cashier);
        $item = $this->makeItem(['price' => 15]);

        $payload = [
            'transactions' => [[
                'client_reference' => 'OFFLINE-LOCAL-0001',
                'shift_id' => $shift['id'],
                'lines' => [['item_id' => $item->id, 'quantity' => 2]],
                'amount_tendered' => 30,
            ]],
        ];

        $first = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sync/offline-sales', $payload);

        $first->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.0.sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('results.0.sale.is_offline', true)
            ->assertJsonPath('results.0.sale.total', '30.0000');

        $saleId = $first->json('results.0.sale.id');

        $second = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sync/offline-sales', $payload);

        $second->assertOk()->assertJsonPath('results.0.status', 'already_synced');
        $this->assertSame($saleId, $second->json('results.0.sale.id'));
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_offline_sync_reports_a_per_item_failure_without_blocking_the_rest_of_the_batch(): void
    {
        $cashier = $this->makeCashier();
        ['shift' => $shift] = $this->openShiftAndTill($cashier);
        $item = $this->makeItem();

        $payload = [
            'transactions' => [
                [
                    'client_reference' => 'OFFLINE-BAD-ITEM',
                    'shift_id' => $shift['id'],
                    'lines' => [['item_id' => 999999, 'quantity' => 1]],
                ],
                [
                    'client_reference' => 'OFFLINE-GOOD-ITEM',
                    'shift_id' => $shift['id'],
                    'lines' => [['item_id' => $item->id, 'quantity' => 1]],
                    'amount_tendered' => 10,
                ],
            ],
        ];

        $response = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sync/offline-sales', $payload);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'failed')
            ->assertJsonPath('results.1.status', 'created');
    }

    public function test_offline_sale_requires_the_shifts_till_to_still_be_open(): void
    {
        $cashier = $this->makeCashier();
        ['shift' => $shift, 'till' => $till] = $this->openShiftAndTill($cashier);
        $item = $this->makeItem();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200])->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();

        $response = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sync/offline-sales', [
            'transactions' => [[
                'client_reference' => 'OFFLINE-AFTER-CLOSE',
                'shift_id' => $shift['id'],
                'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            ]],
        ]);

        $response->assertOk()->assertJsonPath('results.0.status', 'failed');
        $this->assertDatabaseMissing('sales', ['client_reference' => 'OFFLINE-AFTER-CLOSE']);
    }

    public function test_recover_resolves_an_unknown_payment_and_completes_the_sale(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $item = $this->makeItem();

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated();

        $payment = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'card',
            'idempotency_key' => 'idem-recover-1',
            'amount' => 10,
            'simulate' => 'timeout',
        ])->json('payment');

        $this->assertSame(PaymentStatus::Unknown->value, $payment['status']);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/recover", ['resolve' => 'approved']);

        $response->assertOk()
            ->assertJsonPath('sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('resolved_payments.0.status', PaymentStatus::Captured->value);
    }

    public function test_close_shift_is_blocked_by_an_unresolved_unknown_payment_until_placed_on_exception_hold(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        ['shift' => $shift, 'till' => $till] = $this->openShiftAndTill($cashier);
        $item = $this->makeItem();

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'card',
            'idempotency_key' => 'idem-exception-1',
            'amount' => 10,
            'simulate' => 'timeout',
        ])->assertStatus(202);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200])->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/close")
            ->assertUnprocessable();

        $hold = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/exception-hold", [
            'note' => 'Card timed out, holding for reconciliation with the processor',
            'manager_pin' => '9999',
        ]);
        $hold->assertOk()->assertJsonPath('sale.exception_reviewed_by', fn ($id) => $id !== null);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/close")
            ->assertOk()
            ->assertJsonPath('shift.status', ShiftStatus::Closed->value);
    }

    public function test_reassign_terminal_moves_an_open_shift(): void
    {
        $cashier = $this->makeCashier();
        ['shift' => $shift] = $this->openShiftAndTill($cashier);

        $newTerminal = Terminal::create([
            'store_id' => $this->store->id,
            'registration_code' => 'TERM-TEST-002',
            'name' => 'Backup Terminal',
            'status' => 'active',
        ]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/reassign-terminal", ['terminal_id' => $newTerminal->id]);

        $response->assertOk()->assertJsonPath('shift.terminal_id', $newTerminal->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.terminal_reassigned']);
    }

    public function test_reassign_terminal_rejects_a_terminal_in_another_store(): void
    {
        $cashier = $this->makeCashier();
        ['shift' => $shift] = $this->openShiftAndTill($cashier);

        $otherStore = Store::create(['name' => 'Other Store', 'code' => 'OTH-001']);
        $otherTerminal = Terminal::create([
            'store_id' => $otherStore->id,
            'registration_code' => 'TERM-OTHER-001',
            'name' => 'Other Terminal',
            'status' => 'active',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/shifts/{$shift['id']}/reassign-terminal", ['terminal_id' => $otherTerminal->id])
            ->assertUnprocessable();
    }

    public function test_reassign_terminal_rejects_a_terminal_with_an_active_shift(): void
    {
        $cashierOne = $this->makeCashier();
        ['shift' => $shiftOne] = $this->openShiftAndTill($cashierOne);

        $secondTerminal = Terminal::create([
            'store_id' => $this->store->id,
            'registration_code' => 'TERM-TEST-003',
            'name' => 'Second Terminal',
            'status' => 'active',
        ]);

        $cashierTwo = User::create([
            'name' => 'Cashier Two',
            'email' => 'cashier2@test.local',
            'password' => Hash::make('password'),
            'employee_code' => 'CSH200',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $cashierTwo->assignRole('cashier');

        $this->actingAs($cashierTwo, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $secondTerminal->id])
            ->assertCreated();

        $this->actingAs($cashierOne, 'sanctum')
            ->postJson("/api/v1/shifts/{$shiftOne['id']}/reassign-terminal", ['terminal_id' => $secondTerminal->id])
            ->assertUnprocessable();
    }
}
