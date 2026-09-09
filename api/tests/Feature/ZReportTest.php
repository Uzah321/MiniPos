<?php

namespace Tests\Feature;

use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ZReportTest extends TestCase
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

    public function test_z_report_is_unavailable_until_the_shift_is_closed(): void
    {
        $cashier = $this->makeCashier();

        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/shifts/{$shift['id']}/z-report")
            ->assertUnprocessable();
    }

    public function test_z_report_summarises_a_completed_shift(): void
    {
        $cashier = $this->makeCashier();

        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');

        $till = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift['id'], 'opening_float' => 200])
            ->assertCreated()->json('till');

        $item = Item::create([
            'sku' => 'SKU-Z1', 'barcode' => '6001112223334', 'name' => 'Z Item', 'price' => 50, 'tax_rate' => 0, 'active' => true,
        ]);

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'tender_type' => 'cash',
            'idempotency_key' => 'z-report-idem-1',
            'amount' => 50,
            'amount_tendered' => 50,
        ])->assertCreated();

        // Expected cash = 200 opening + 50 cash sale = 250.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 250])
            ->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/shifts/{$shift['id']}/close")->assertOk();

        $response = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/shifts/{$shift['id']}/z-report");

        $response->assertOk()
            ->assertJsonPath('report.sales.count', 1)
            ->assertJsonPath('report.sales.net_total', '50.0000')
            ->assertJsonPath('report.payments_by_tender.cash', '50.0000')
            ->assertJsonPath('report.tills.0.variance', '0.0000');
    }

    public function test_z_report_is_visible_to_a_manager_but_not_another_cashier(): void
    {
        $cashier = $this->makeCashier();
        $manager = $this->makeManager();

        $otherCashier = User::create([
            'name' => 'Cashier Two',
            'email' => 'cashier2@test.local',
            'password' => Hash::make('password'),
            'employee_code' => 'CSH200',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $otherCashier->assignRole('cashier');

        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated()->json('shift');

        $till = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift['id'], 'opening_float' => 200])
            ->assertCreated()->json('till');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/count", ['counted_amount' => 200])->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/tills/{$till['id']}/close")->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/shifts/{$shift['id']}/close")->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/shifts/{$shift['id']}/z-report")
            ->assertOk();

        $this->actingAs($otherCashier, 'sanctum')
            ->getJson("/api/v1/shifts/{$shift['id']}/z-report")
            ->assertUnprocessable();
    }
}
