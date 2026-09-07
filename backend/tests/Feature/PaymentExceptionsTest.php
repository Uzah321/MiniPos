<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Item;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentExceptionsTest extends TestCase
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

    /**
     * Opens a shift + till for the cashier, starts a sale and adds one line, returning the sale payload.
     */
    private function startSaleWithLine(User $cashier, float $price = 50): array
    {
        $shift = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/tills/open', ['shift_id' => $shift->json('shift.id'), 'opening_float' => 200])
            ->assertCreated();

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $item = $this->makeItem(['price' => $price, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->json('sale.id')}/lines", ['item_id' => $item->id])
            ->assertCreated();

        return $sale->json('sale');
    }

    public function test_card_timeout_marks_payment_unknown_and_leaves_sale_unpaid(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card', 'idempotency_key' => 'idem-timeout-1', 'amount' => 50, 'simulate' => 'timeout',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('payment.status', 'unknown');
        $response->assertJsonPath('sale.status', SaleStatus::Priced->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.unknown']);
    }

    public function test_querying_an_unknown_payment_resolves_to_approved_and_completes_the_sale(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        $timedOut = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card', 'idempotency_key' => 'idem-timeout-2', 'amount' => 50, 'simulate' => 'timeout',
            ]);
        $paymentId = $timedOut->json('payment.id');

        $stillUnknown = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments/{$paymentId}/query", []);
        $stillUnknown->assertStatus(202)->assertJsonPath('payment.status', 'unknown');

        $resolved = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments/{$paymentId}/query", ['resolve' => 'approved']);

        $resolved->assertCreated();
        $resolved->assertJsonPath('payment.status', 'captured');
        $resolved->assertJsonPath('sale.status', SaleStatus::Completed->value);
        $this->assertNotNull($resolved->json('sale.receipt_number'));
    }

    public function test_querying_an_unknown_payment_can_resolve_to_declined(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        $timedOut = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card', 'idempotency_key' => 'idem-timeout-3', 'amount' => 50, 'simulate' => 'timeout',
            ]);
        $paymentId = $timedOut->json('payment.id');

        $resolved = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments/{$paymentId}/query", ['resolve' => 'declined']);

        $resolved->assertUnprocessable();
        $resolved->assertJsonPath('payment.status', 'declined');
        $resolved->assertJsonPath('sale.status', SaleStatus::Priced->value);
    }

    public function test_cancelling_an_unknown_payment_leaves_other_payments_intact(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        // First leg: a real cash payment for part of the total.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash', 'idempotency_key' => 'idem-cancel-cash', 'amount' => 20, 'amount_tendered' => 20,
            ])->assertCreated();

        // Second leg: a card attempt that times out.
        $timedOut = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card', 'idempotency_key' => 'idem-cancel-card', 'amount' => 30, 'simulate' => 'timeout',
            ]);
        $paymentId = $timedOut->json('payment.id');

        $cancelled = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments/{$paymentId}/cancel", ['reason' => 'Customer changed tender']);

        $cancelled->assertOk()->assertJsonPath('payment.status', 'cancelled');

        $payments = $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/sales/{$sale['id']}/payments")
            ->json('payments');
        $this->assertCount(2, $payments);

        $sale = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/sales/{$sale['id']}")->json('sale');
        $this->assertSame(SaleStatus::PaymentPending->value, $sale['status']);
    }

    public function test_cancelling_a_captured_payment_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        $payment = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash', 'idempotency_key' => 'idem-captured', 'amount' => 50, 'amount_tendered' => 50,
            ]);
        $paymentId = $payment->json('payment.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments/{$paymentId}/cancel", [])
            ->assertUnprocessable();
    }

    public function test_simulated_commit_failure_after_authorisation_reverses_the_payment(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSaleWithLine($cashier, 50);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card', 'idempotency_key' => 'idem-commit-fail', 'amount' => 50, 'simulate' => 'commit_failure',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('payment.status', 'reversed');
        $response->assertJsonPath('sale.status', SaleStatus::Priced->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reversed']);
    }
}
