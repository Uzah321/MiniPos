<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Kitchen\KitchenOrderStatus;
use App\Models\Store;
use App\Models\User;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KitchenOrderTest extends TestCase
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
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'barcode' => '600'.random_int(100000000, 999999999),
            'name' => 'Test Item',
            'price' => 10,
            'tax_rate' => 0,
            'station' => 'grill',
            'active' => true,
        ], $overrides));
    }

    /**
     * Opens a shift+till, starts a sale and adds one line for the given item.
     */
    private function startSaleWithItem(User $cashier, Item $item, float $quantity = 1): array
    {
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift->json('shift.id'), 'opening_float' => 200])
            ->assertCreated();

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()
            ->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => $quantity])
            ->assertCreated();

        return $sale;
    }

    public function test_sending_a_sale_creates_a_kitchen_order_with_routed_lines(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['station' => 'grill']);
        $sale = $this->startSaleWithItem($cashier, $item, 2);

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders");

        $response->assertCreated()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Sent->value)
            ->assertJsonPath('kitchen_order.lines.0.station', 'grill')
            ->assertJsonPath('kitchen_order.lines.0.quantity', '2.0000');
    }

    public function test_sending_again_only_routes_newly_added_lines(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item, 1);

        $first = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');

        // Add another line for a second item and send again.
        $secondItem = $this->makeItem(['name' => 'Second Item']);
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $secondItem->id, 'quantity' => 1])
            ->assertCreated();

        $second = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');

        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(2, $second['lines']);
    }

    public function test_sending_with_nothing_new_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item, 1);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertUnprocessable();
    }

    public function test_a_line_advances_through_the_happy_path_and_the_order_mirrors_it(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item);

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');

        $lineId = $order['lines'][0]['id'];

        foreach ([
            KitchenOrderStatus::Acknowledged,
            KitchenOrderStatus::Accepted,
            KitchenOrderStatus::Preparing,
            KitchenOrderStatus::Ready,
            KitchenOrderStatus::ServedOrCollected,
        ] as $expected) {
            $response = $this->actingAs($cashier, 'sanctum')
                ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/advance");

            $response->assertOk()
                ->assertJsonPath('line.status', $expected->value)
                ->assertJsonPath('kitchen_order.status', $expected->value);
        }

        // Cannot advance past served/collected.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/advance")
            ->assertUnprocessable();
    }

    public function test_order_status_reflects_the_least_advanced_line(): void
    {
        $cashier = $this->makeCashier();
        $itemA = $this->makeItem(['name' => 'A']);
        $itemB = $this->makeItem(['name' => 'B']);
        $sale = $this->startSaleWithItem($cashier, $itemA);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $itemB->id, 'quantity' => 1])
            ->assertCreated();

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');

        $lineA = $order['lines'][0]['id'];

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineA}/advance");

        // Line A moved to Acknowledged, but line B is still Sent, so the order stays Sent.
        $response->assertOk()->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Sent->value);
    }

    public function test_voiding_a_line_requires_manager_approval(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item);

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');
        $lineId = $order['lines'][0]['id'];

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/void", ['reason' => 'Wrong item'])
            ->assertUnprocessable();

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/void", [
                'reason' => 'Wrong item',
                'manager_pin' => '9999',
            ]);

        $response->assertOk()->assertJsonPath('line.status', KitchenOrderStatus::Voided->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kitchen_order.line.voided']);
    }

    public function test_resending_a_line_creates_a_marked_duplicate(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item);

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');
        $lineId = $order['lines'][0]['id'];

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/resend");

        $response->assertCreated()
            ->assertJsonPath('line.is_duplicate', true)
            ->assertJsonPath('line.status', KitchenOrderStatus::Sent->value);
        $this->assertNotSame($lineId, $response->json('line.id'));
    }

    public function test_handover_requires_every_line_ready_then_closes_out(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item);

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');
        $lineId = $order['lines'][0]['id'];

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/handover")
            ->assertUnprocessable();

        foreach ([
            KitchenOrderStatus::Acknowledged,
            KitchenOrderStatus::Accepted,
            KitchenOrderStatus::Preparing,
            KitchenOrderStatus::Ready,
        ] as $expected) {
            $this->actingAs($cashier, 'sanctum')
                ->postJson("/api/v1/kitchen-orders/{$order['id']}/lines/{$lineId}/advance")
                ->assertOk();
        }

        $handover = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/handover");

        $handover->assertOk()->assertJsonPath('kitchen_order.status', KitchenOrderStatus::ServedOrCollected->value);

        $close = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/kitchen-orders/{$order['id']}/close");

        $close->assertOk()->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Completed->value);
    }

    public function test_close_is_rejected_while_a_line_is_still_unsettled(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem();
        $sale = $this->startSaleWithItem($cashier, $item);

        $order = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->json('kitchen_order');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$order['id']}/close")
            ->assertUnprocessable();
    }
}
