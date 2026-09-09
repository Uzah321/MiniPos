<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Sales\Sale;
use App\Sales\SaleStatus;
use App\Tables\RestaurantTable;
use App\Tables\TableStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TableBillOperationsTest extends TestCase
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

    private function makeTable(array $overrides = []): RestaurantTable
    {
        return RestaurantTable::create(array_merge([
            'store_id' => $this->store->id,
            'label' => 'T'.random_int(1, 999999),
            'status' => TableStatus::Available,
        ], $overrides));
    }

    private function makeItem(array $overrides = []): Item
    {
        return Item::create(array_merge([
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'barcode' => '600'.random_int(100000000, 999999999),
            'name' => 'Test Item',
            'price' => 10,
            'tax_rate' => 0,
            'active' => true,
        ], $overrides));
    }

    private function openShiftAndTill(User $cashier, float $openingFloat = 200): void
    {
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift->json('shift.id'), 'opening_float' => $openingFloat])
            ->assertCreated();
    }

    private function startSaleOnTable(User $cashier, RestaurantTable $table): array
    {
        return $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', [
            'terminal_id' => $this->terminal->id,
            'table_id' => $table->id,
        ])->assertCreated()->json('sale');
    }

    public function test_merge_moves_sales_and_kitchen_orders_and_sums_guests(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);

        $tableA = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id, 'guest_count' => 2]);
        $tableB = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id, 'guest_count' => 3]);

        $saleB = $this->startSaleOnTable($cashier, $tableB);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$tableA->id}/merge", ['from_table_ids' => [$tableB->id]]);

        $response->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $saleB['id'], 'table_id' => $tableA->id]);
        $this->assertDatabaseHas('tables', ['id' => $tableA->id, 'guest_count' => 5]);
        $this->assertDatabaseHas('tables', ['id' => $tableB->id, 'status' => TableStatus::Available->value, 'server_id' => null]);
    }

    public function test_merge_is_rejected_when_a_bill_has_a_payment_in_progress(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);

        $tableA = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $tableB = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $saleB = $this->startSaleOnTable($cashier, $tableB);
        Sale::find($saleB['id'])->update(['status' => SaleStatus::PaymentPending]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$tableA->id}/merge", ['from_table_ids' => [$tableB->id]])
            ->assertUnprocessable();
    }

    public function test_split_creates_a_new_bill_with_the_moved_item(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $sale = $this->startSaleOnTable($cashier, $table);

        $itemA = $this->makeItem(['name' => 'Burger']);
        $itemB = $this->makeItem(['name' => 'Fries']);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $itemA->id, 'quantity' => 1])->assertCreated();
        $lineB = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $itemB->id, 'quantity' => 1])->assertCreated()->json('line');

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/split", [
            'sale_id' => $sale['id'],
            'lines' => [['sale_line_id' => $lineB['id']]],
        ]);

        $response->assertCreated();
        $newSale = $response->json('new_sale');
        $this->assertCount(1, $newSale['lines']);
        $this->assertSame($itemB->id, $newSale['lines'][0]['item_id']);
        $this->assertCount(1, $response->json('sale.lines'));
        $this->assertSame($table->id, $newSale['table_id']);
    }

    public function test_split_moves_only_the_requested_partial_quantity(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $sale = $this->startSaleOnTable($cashier, $table);
        $item = $this->makeItem();

        $line = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 3])
            ->assertCreated()->json('line');

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/split", [
            'sale_id' => $sale['id'],
            'lines' => [['sale_line_id' => $line['id'], 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertEquals(2, (float) $response->json('sale.lines.0.quantity'));
        $this->assertEquals(1, (float) $response->json('new_sale.lines.0.quantity'));
    }

    public function test_move_item_between_guests_shifts_the_line_and_recalculates_both_bills(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $saleA = $this->startSaleOnTable($cashier, $table);
        $saleB = $this->startSaleOnTable($cashier, $table);

        $item = $this->makeItem(['price' => 20]);
        $line = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$saleA['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated()->json('line');

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/move-item", [
            'from_sale_id' => $saleA['id'],
            'to_sale_id' => $saleB['id'],
            'sale_line_id' => $line['id'],
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('from_sale.lines'));
        $this->assertCount(1, $response->json('to_sale.lines'));
        $this->assertEquals(20, (float) $response->json('to_sale.total'));
    }

    public function test_move_item_to_a_bill_on_a_different_table_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $tableA = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $tableB = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $saleA = $this->startSaleOnTable($cashier, $tableA);
        $saleB = $this->startSaleOnTable($cashier, $tableB);

        $item = $this->makeItem();
        $line = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$saleA['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated()->json('line');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$tableA->id}/move-item", [
            'from_sale_id' => $saleA['id'],
            'to_sale_id' => $saleB['id'],
            'sale_line_id' => $line['id'],
        ])->assertUnprocessable();
    }

    public function test_service_charge_uses_the_configured_default_rate(): void
    {
        config(['pos.service_charge_rate' => 0.1]);

        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $sale = $this->startSaleOnTable($cashier, $table);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])->assertCreated();

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/service-charge", [
            'sale_id' => $sale['id'],
        ]);

        $response->assertOk();
        $this->assertEquals(10, (float) $response->json('sale.service_charge_amount'));
        $this->assertEquals(110, (float) $response->json('sale.total'));
    }

    public function test_tip_is_added_on_top_of_the_bill_total(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $sale = $this->startSaleOnTable($cashier, $table);
        $item = $this->makeItem(['price' => 50, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])->assertCreated();

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/tip", [
            'sale_id' => $sale['id'],
            'amount' => 5,
        ]);

        $response->assertOk();
        $this->assertEquals(5, (float) $response->json('sale.tip_amount'));
        $this->assertEquals(55, (float) $response->json('sale.total'));
    }

    public function test_tip_cannot_be_negative(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $sale = $this->startSaleOnTable($cashier, $table);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/tip", [
            'sale_id' => $sale['id'],
            'amount' => -5,
        ])->assertUnprocessable();
    }
}
