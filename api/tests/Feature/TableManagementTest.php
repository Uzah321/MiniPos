<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Tables\RestaurantTable;
use App\Tables\TableStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TableManagementTest extends TestCase
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
            'label' => 'T1',
            'status' => TableStatus::Available,
        ], $overrides));
    }

    /**
     * Opens a shift + till for the cashier, returning the cashier for reuse.
     */
    private function openShiftAndTill(User $cashier, float $openingFloat = 200): void
    {
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', [
                'shift_id' => $shift->json('shift.id'),
                'opening_float' => $openingFloat,
            ])
            ->assertCreated();
    }

    public function test_tables_are_listed_for_the_users_store(): void
    {
        $cashier = $this->makeCashier();
        $this->makeTable(['label' => 'T1']);
        $this->makeTable(['label' => 'T2']);

        $response = $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/tables');

        $response->assertOk();
        $this->assertCount(2, $response->json('tables'));
    }

    public function test_an_available_table_can_be_occupied(): void
    {
        $cashier = $this->makeCashier();
        $table = $this->makeTable();

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/occupy", ['guest_count' => 4]);

        $response->assertOk()
            ->assertJsonPath('table.status', TableStatus::Occupied->value)
            ->assertJsonPath('table.guest_count', 4)
            ->assertJsonPath('table.server_id', $cashier->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'table.occupy']);
    }

    public function test_occupying_a_table_that_is_not_available_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $table = $this->makeTable(['status' => TableStatus::Occupied]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/occupy", [])
            ->assertUnprocessable();
    }

    public function test_a_sale_can_be_started_against_an_occupied_table(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $response = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', [
            'terminal_id' => $this->terminal->id,
            'table_id' => $table->id,
        ]);

        $response->assertCreated()->assertJsonPath('sale.table_id', $table->id);
    }

    public function test_starting_a_sale_against_an_available_table_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable();

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', [
            'terminal_id' => $this->terminal->id,
            'table_id' => $table->id,
        ])->assertUnprocessable();
    }

    public function test_bill_request_moves_an_occupied_table_to_bill_requested(): void
    {
        $cashier = $this->makeCashier();
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/bill-request", [])
            ->assertOk()
            ->assertJsonPath('table.status', TableStatus::BillRequested->value);
    }

    public function test_transfer_moves_the_open_sale_and_kitchen_orders_to_the_destination_table(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $fromTable = $this->makeTable(['label' => 'T1', 'status' => TableStatus::Occupied, 'server_id' => $cashier->id, 'guest_count' => 2]);
        $toTable = $this->makeTable(['label' => 'T2']);

        $sale = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', [
            'terminal_id' => $this->terminal->id,
            'table_id' => $fromTable->id,
        ])->assertCreated()->json('sale');

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$fromTable->id}/transfer", ['to_table_id' => $toTable->id]);

        $response->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'table_id' => $toTable->id]);
        $this->assertDatabaseHas('tables', ['id' => $fromTable->id, 'status' => TableStatus::Available->value, 'server_id' => null]);
        $this->assertDatabaseHas('tables', ['id' => $toTable->id, 'status' => TableStatus::Occupied->value, 'guest_count' => 2]);
    }

    public function test_transfer_to_an_occupied_destination_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $fromTable = $this->makeTable(['label' => 'T1', 'status' => TableStatus::Occupied, 'server_id' => $cashier->id]);
        $toTable = $this->makeTable(['label' => 'T2', 'status' => TableStatus::Occupied]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$fromTable->id}/transfer", ['to_table_id' => $toTable->id])
            ->assertUnprocessable();
    }

    public function test_a_table_with_an_unsettled_sale_cannot_be_closed(): void
    {
        $cashier = $this->makeCashier();
        $this->openShiftAndTill($cashier);
        $table = $this->makeTable(['status' => TableStatus::Occupied, 'server_id' => $cashier->id]);

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/sales', [
            'terminal_id' => $this->terminal->id,
            'table_id' => $table->id,
        ])->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/close", [])
            ->assertUnprocessable();
    }

    public function test_a_table_with_no_open_sale_can_be_closed(): void
    {
        $cashier = $this->makeCashier();
        $table = $this->makeTable(['status' => TableStatus::BillRequested, 'server_id' => $cashier->id, 'guest_count' => 3]);

        $response = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tables/{$table->id}/close", []);

        $response->assertOk()
            ->assertJsonPath('table.status', TableStatus::Available->value)
            ->assertJsonPath('table.server_id', null)
            ->assertJsonPath('table.guest_count', null);
    }

    public function test_a_table_from_another_store_is_not_accessible(): void
    {
        $cashier = $this->makeCashier();
        $otherStore = Store::create(['name' => 'Other Store', 'code' => 'OTH-001']);
        $table = $this->makeTable(['store_id' => $otherStore->id]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/occupy", [])
            ->assertUnprocessable();
    }
}
