<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Shifts\ShiftStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PosFoundationTest extends TestCase
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

    public function test_terminal_can_self_register(): void
    {
        $response = $this->postJson('/api/v1/terminals/register', [
            'registration_code' => 'TERM-NEW-001',
            'store_id' => $this->store->id,
            'name' => 'New Terminal',
        ]);

        $response->assertOk()->assertJsonPath('terminal.status', 'active');
    }

    public function test_cashier_can_login_with_password(): void
    {
        $this->makeCashier();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'cashier1@test.local',
            'credential' => 'password',
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()->assertJsonPath('user.roles.0', 'cashier');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_cashier_can_login_with_pin(): void
    {
        $this->makeCashier();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'CSH100',
            'credential' => '1111',
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk();
    }

    public function test_login_fails_with_wrong_credential(): void
    {
        $this->makeCashier();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'cashier1@test.local',
            'credential' => 'wrong-password',
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $cashier = $this->makeCashier();
        $cashier->update(['active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'cashier1@test.local',
            'credential' => 'password',
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_cashier_can_open_shift_and_till(): void
    {
        $cashier = $this->makeCashier();

        $shiftResponse = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id]);

        $shiftResponse->assertCreated()->assertJsonPath('shift.status', ShiftStatus::Open->value);

        $tillResponse = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', [
                'shift_id' => $shiftResponse->json('shift.id'),
                'opening_float' => 200,
            ]);

        $tillResponse->assertCreated();
        $this->assertDatabaseHas('cash_movements', ['type' => 'open']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.open']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'till.open']);
    }

    public function test_second_shift_on_same_terminal_is_rejected_while_one_is_open(): void
    {
        $cashierOne = $this->makeCashier();
        $cashierTwo = User::create([
            'name' => 'Cashier Two',
            'email' => 'cashier2@test.local',
            'password' => Hash::make('password'),
            'employee_code' => 'CSH200',
            'store_id' => $this->store->id,
            'active' => true,
        ]);
        $cashierTwo->assignRole('cashier');

        $this->actingAs($cashierOne, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashierTwo, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertUnprocessable();
    }

    public function test_till_open_above_threshold_requires_manager_pin(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');

        $shiftResponse = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id]);

        $withoutPin = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', [
                'shift_id' => $shiftResponse->json('shift.id'),
                'opening_float' => 999,
            ]);
        $withoutPin->assertUnprocessable();

        $withPin = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', [
                'shift_id' => $shiftResponse->json('shift.id'),
                'opening_float' => 999,
                'manager_pin' => '9999',
            ]);
        $withPin->assertCreated();
        $this->assertNotNull($withPin->json('till.manager_verified_by'));
    }
}
