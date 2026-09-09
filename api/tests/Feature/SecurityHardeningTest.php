<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
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

    private function makeCashier(?Store $store = null): User
    {
        $store ??= $this->store;

        $cashier = User::create([
            'name' => 'Cashier One',
            'email' => 'cashier1@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1111'),
            'employee_code' => 'CSH100',
            'store_id' => $store->id,
            'active' => true,
        ]);
        $cashier->assignRole('cashier');

        return $cashier;
    }

    private function makeManager(Store $store, string $pin = '9999'): User
    {
        $manager = User::create([
            'name' => 'Manager One',
            'email' => 'manager-'.$store->id.'@test.local',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make($pin),
            'employee_code' => 'MGR'.$store->id,
            'store_id' => $store->id,
            'active' => true,
        ]);
        $manager->assignRole('manager');

        return $manager;
    }

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

    public function test_a_managers_pin_does_not_authorise_actions_at_a_different_store(): void
    {
        $otherStore = Store::create(['name' => 'Other Store', 'code' => 'OTH-001']);
        $this->makeManager($otherStore, '9999');

        $cashier = $this->makeCashier();
        ['till' => $till] = $this->openShiftAndTill($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Float top-up', 'manager_pin' => '9999'])
            ->assertUnprocessable();
    }

    public function test_a_managers_pin_at_the_same_store_still_works(): void
    {
        $this->makeManager($this->store, '9999');

        $cashier = $this->makeCashier();
        ['till' => $till] = $this->openShiftAndTill($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Float top-up', 'manager_pin' => '9999'])
            ->assertCreated();
    }

    public function test_repeated_wrong_manager_pins_lock_out_further_attempts(): void
    {
        $this->makeManager($this->store, '9999');

        $cashier = $this->makeCashier();
        ['till' => $till] = $this->openShiftAndTill($cashier);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($cashier, 'sanctum')
                ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Attempt', 'manager_pin' => '0000'])
                ->assertUnprocessable();
        }

        // The 6th attempt is locked out even with the CORRECT pin now.
        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/tills/{$till['id']}/paid-in", ['amount' => 50, 'reason' => 'Attempt', 'manager_pin' => '9999']);

        $response->assertUnprocessable();
        $this->assertStringContainsString('Too many', $response->json('errors.manager_pin.0'));
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('login:127.0.0.1');
        $cashier = $this->makeCashier();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => $cashier->employee_code,
                'credential' => 'wrong-password',
                'terminal_id' => $this->terminal->id,
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $cashier->employee_code,
            'credential' => 'password',
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(429);
    }

    public function test_peripheral_check_is_rejected_for_a_terminal_at_a_different_store(): void
    {
        $cashier = $this->makeCashier();

        $otherStore = Store::create(['name' => 'Other Store', 'code' => 'OTH-002']);
        $otherTerminal = Terminal::create([
            'store_id' => $otherStore->id,
            'registration_code' => 'TERM-OTHER-002',
            'name' => 'Other Terminal',
            'status' => 'active',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/terminals/{$otherTerminal->id}/peripheral-check", [
                'checks' => [['peripheral' => 'scanner', 'status' => 'ok']],
            ])
            ->assertUnprocessable();
    }

    public function test_peripheral_check_succeeds_for_the_cashiers_own_store_terminal(): void
    {
        $cashier = $this->makeCashier();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/terminals/{$this->terminal->id}/peripheral-check", [
                'checks' => [['peripheral' => 'scanner', 'status' => 'ok']],
            ])
            ->assertOk();
    }
}
