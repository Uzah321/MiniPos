<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Kitchen\KitchenOrderStatus;
use App\Models\Store;
use App\Models\User;
use App\Sales\SaleStatus;
use App\Tables\RestaurantTable;
use App\Tables\TableStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sprint 8 "end-to-end automation": walks one full dine-in order across every
 * domain built this project touches, rather than testing each in isolation -
 * table -> basket -> kitchen -> bill -> payment -> receipt -> close -> shift
 * close -> Z report. A break anywhere in that chain (a wrong status name, a
 * relation that doesn't recalculate, a total that doesn't carry through)
 * would surface here even if every domain's own unit tests still pass.
 */
class FullDineInFlowTest extends TestCase
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
            'name' => 'Server One',
            'email' => 'server1@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1111'),
            'employee_code' => 'SRV100',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $cashier->assignRole('cashier');

        return $cashier;
    }

    private function makeManager(): User
    {
        $manager = User::create([
            'name' => 'Manager One',
            'email' => 'manager1@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('9999'),
            'employee_code' => 'MGR100',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $manager->assignRole('manager');

        return $manager;
    }

    public function test_a_full_dine_in_order_completes_from_table_open_to_shift_close(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager();

        $burger = Item::create([
            'sku' => 'SKU-BURGER', 'barcode' => '6001000000001', 'name' => 'Burger',
            'price' => 40, 'tax_rate' => 0, 'station' => 'grill', 'active' => true,
        ]);
        $fries = Item::create([
            'sku' => 'SKU-FRIES', 'barcode' => '6001000000002', 'name' => 'Fries',
            'price' => 15, 'tax_rate' => 0, 'station' => 'fryer', 'active' => true,
        ]);

        // 1. Start of day: open shift and till.
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');

        $till = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift['id'], 'opening_float' => 200])
            ->assertCreated()->json('till');

        // 2. Seat guests at a table.
        $table = RestaurantTable::create([
            'store_id' => $this->store->id,
            'label' => 'T1',
            'status' => TableStatus::Available,
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/occupy", ['guest_count' => 2])
            ->assertOk()
            ->assertJsonPath('table.status', TableStatus::Occupied->value);

        // 3. Start the tab against that table and build the basket.
        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id, 'table_id' => $table->id])
            ->assertCreated()->json('sale');

        $this->assertSame($table->id, $sale['table_id']);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $burger->id, 'quantity' => 1])
            ->assertCreated();
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $fries->id, 'quantity' => 1])
            ->assertCreated();

        // 4. Send the order to the kitchen and route both lines to their stations.
        $kitchenOrder = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Sent->value)
            ->json('kitchen_order');

        $this->assertCount(2, $kitchenOrder['lines']);
        $stations = array_column($kitchenOrder['lines'], 'station');
        $this->assertContains('grill', $stations);
        $this->assertContains('fryer', $stations);

        // 5. Kitchen works both lines through to ready, independently.
        foreach ($kitchenOrder['lines'] as $line) {
            foreach ([
                KitchenOrderStatus::Acknowledged, KitchenOrderStatus::Accepted,
                KitchenOrderStatus::Preparing, KitchenOrderStatus::Ready,
            ] as $expected) {
                $this->actingAs($cashier, 'sanctum')
                    ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/lines/{$line['id']}/advance")
                    ->assertOk()
                    ->assertJsonPath('line.status', $expected->value);
            }
        }

        // 6. Server hands both dishes over and closes out the kitchen order.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/handover")
            ->assertOk()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::ServedOrCollected->value);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/close")
            ->assertOk()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Completed->value);

        // 7. Table requests the bill; a service charge and tip are added.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/bill-request")
            ->assertOk()
            ->assertJsonPath('table.status', TableStatus::BillRequested->value);

        $withCharge = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/service-charge", ['sale_id' => $sale['id'], 'rate' => 0.10])
            ->assertOk();
        // Subtotal 55, 10% service charge = 5.50 -> total 60.50.
        $this->assertEquals(60.5, (float) $withCharge->json('sale.total'));

        $withTip = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/tip", ['sale_id' => $sale['id'], 'amount' => 10])
            ->assertOk();
        $this->assertEquals(70.5, (float) $withTip->json('sale.total'));

        // 8. Guest pays in cash; the sale completes and a receipt is issued.
        $payment = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'cash',
            'idempotency_key' => 'e2e-dine-in-1',
            'amount' => 70.5,
            'amount_tendered' => 100,
        ]);
        $payment->assertCreated()
            ->assertJsonPath('sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('change', '29.5000');

        $receiptNumber = $payment->json('sale.receipt_number');
        $this->assertNotEmpty($receiptNumber);

        $receipt = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/sales/{$sale['id']}/receipt");
        $receipt->assertOk()->assertJsonPath('receipt.receipt_number', $receiptNumber);

        // 9. Table closes now that its bill is settled.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk()
            ->assertJsonPath('table.status', TableStatus::Available->value);

        // 10. End of day: count the drawer, close the till and the shift.
        // Expected cash = 200 opening + 70.50 cash sale = 270.50.
        $count = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 270.5]);
        $count->assertOk()->assertJsonPath('till.variance', '0.0000');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/shifts/{$shift['id']}/close")->assertOk();

        // 11. The Z report ties the whole shift together.
        $zReport = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/shifts/{$shift['id']}/z-report");

        $zReport->assertOk()
            ->assertJsonPath('report.sales.count', 1)
            ->assertJsonPath('report.sales.net_total', '70.5000')
            ->assertJsonPath('report.payments_by_tender.cash', '70.5000')
            ->assertJsonPath('report.tills.0.variance', '0.0000');
    }

    public function test_an_unavailable_kitchen_item_can_be_removed_from_the_bill_mid_flow(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager();

        $steak = Item::create([
            'sku' => 'SKU-STEAK', 'barcode' => '6001000000003', 'name' => 'Steak',
            'price' => 80, 'tax_rate' => 0, 'station' => 'grill', 'active' => true,
        ]);
        $salad = Item::create([
            'sku' => 'SKU-SALAD', 'barcode' => '6001000000004', 'name' => 'Salad',
            'price' => 20, 'tax_rate' => 0, 'station' => 'cold', 'active' => true,
        ]);

        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift['id'], 'opening_float' => 200])
            ->assertCreated();

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $steak->id, 'quantity' => 1])
            ->assertCreated();
        $saladLine = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $salad->id, 'quantity' => 1])
            ->assertCreated()->json('line');

        $kitchenOrder = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/kitchen-orders")
            ->assertCreated()->json('kitchen_order');

        $saladKitchenLine = collect($kitchenOrder['lines'])->firstWhere('item_id', $salad->id);

        // Kitchen is out of salad: void that line, requiring manager approval.
        $void = $this->actingAs($cashier, 'sanctum')->postJson(
            "/api/v1/kitchen-orders/{$kitchenOrder['id']}/lines/{$saladKitchenLine['id']}/void",
            ['reason' => 'Out of salad', 'manager_pin' => '9999'],
        );
        $void->assertOk()->assertJsonPath('line.status', 'voided');

        // Removing the salad from the bill too, so the guest isn't charged for it.
        $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/sales/{$sale['id']}/lines/{$saladLine['id']}", ['reason' => 'Item unavailable in kitchen'])
            ->assertOk()
            ->assertJsonCount(1, 'sale.lines');

        // The steak line is unaffected and still reaches ready/served/completed.
        $steakKitchenLine = collect($kitchenOrder['lines'])->firstWhere('item_id', $steak->id);

        foreach ([
            KitchenOrderStatus::Acknowledged, KitchenOrderStatus::Accepted,
            KitchenOrderStatus::Preparing, KitchenOrderStatus::Ready,
        ] as $expected) {
            $this->actingAs($cashier, 'sanctum')
                ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/lines/{$steakKitchenLine['id']}/advance")
                ->assertOk()
                ->assertJsonPath('line.status', $expected->value);
        }

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/handover")
            ->assertOk()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::ServedOrCollected->value);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/kitchen-orders/{$kitchenOrder['id']}/close")
            ->assertOk()
            ->assertJsonPath('kitchen_order.status', KitchenOrderStatus::Completed->value);

        $sale = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/sales/{$sale['id']}")->json('sale');
        $this->assertEquals(80, (float) $sale['total']);
    }
}
