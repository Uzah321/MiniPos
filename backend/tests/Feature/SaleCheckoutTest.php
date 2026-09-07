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

class SaleCheckoutTest extends TestCase
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
            'active' => true,
        ], $overrides));
    }

    /**
     * Opens a shift + till for the cashier and starts a draft sale, returning the sale payload.
     */
    private function startSale(User $cashier, float $openingFloat = 200): array
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

        $sale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        return $sale->json('sale');
    }

    public function test_sale_cannot_start_without_an_open_till(): void
    {
        $cashier = $this->makeCashier();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/shifts/open', ['terminal_id' => $this->terminal->id])
            ->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertUnprocessable();
    }

    public function test_cashier_can_scan_search_and_build_a_basket(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0.15]);

        $scanned = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['barcode' => $item->barcode]);
        $scanned->assertCreated();

        // Scanning the same item again merges into the existing line's quantity.
        $scannedAgain = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['sku' => $item->sku]);
        $scannedAgain->assertCreated();

        $this->assertCount(1, $scannedAgain->json('sale.lines'));
        $this->assertSame('2.0000', $scannedAgain->json('sale.lines.0.quantity'));
        $this->assertSame('20.0000', $scannedAgain->json('sale.subtotal'));
        $this->assertSame('3.0000', $scannedAgain->json('sale.tax_total'));
        $this->assertSame('23.0000', $scannedAgain->json('sale.total'));
        $this->assertSame(SaleStatus::Priced->value, $scannedAgain->json('sale.status'));
    }

    public function test_price_enquiry_does_not_create_a_sale_line(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 25]);

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/items/price-enquiry/{$item->sku}")
            ->assertOk()
            ->assertJsonPath('item.price', '25.0000');

        $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/sales/{$sale['id']}")
            ->assertJsonCount(0, 'sale.lines');
    }

    public function test_basket_line_quantity_can_be_changed(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 5, 'tax_rate' => 0]);

        $added = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1]);
        $lineId = $added->json('line.id');

        $updated = $this->actingAs($cashier, 'sanctum')
            ->patchJson("/api/v1/sales/{$sale['id']}/lines/{$lineId}", ['quantity' => 4]);

        $updated->assertOk()->assertJsonPath('sale.total', '20.0000');
    }

    public function test_removing_a_high_value_line_requires_manager_pin(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 150, 'tax_rate' => 0]);

        $added = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $lineId = $added->json('line.id');

        $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/sales/{$sale['id']}/lines/{$lineId}", ['reason' => 'Customer changed mind'])
            ->assertUnprocessable();

        $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/sales/{$sale['id']}/lines/{$lineId}", [
                'reason' => 'Customer changed mind',
                'manager_pin' => '9999',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'sale.lines');

        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.line.removed']);
    }

    public function test_terminal_peripheral_check_reports_ready_or_exception(): void
    {
        $cashier = $this->makeCashier();

        $ready = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/terminals/{$this->terminal->id}/peripheral-check", [
                'checks' => [
                    ['peripheral' => 'scanner', 'status' => 'ok'],
                    ['peripheral' => 'receipt_printer', 'status' => 'ok'],
                ],
            ]);
        $ready->assertOk()->assertJsonPath('overall', 'ready');

        $exception = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/terminals/{$this->terminal->id}/peripheral-check", [
                'checks' => [
                    ['peripheral' => 'cash_drawer', 'status' => 'fail', 'detail' => 'Drawer jammed'],
                ],
            ]);
        $exception->assertOk()->assertJsonPath('overall', 'exception');
    }

    public function test_cash_sale_completes_with_change_and_allocates_receipt(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        $payment = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash',
                'idempotency_key' => 'idem-cash-1',
                'amount' => 10,
                'amount_tendered' => 15,
            ]);

        $payment->assertCreated();
        $payment->assertJsonPath('change', '5.0000');
        $payment->assertJsonPath('sale.status', SaleStatus::Completed->value);
        $this->assertNotNull($payment->json('sale.receipt_number'));
        $this->assertDatabaseHas('cash_movements', ['type' => 'sale', 'amount' => '10.0000']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.completed']);
    }

    public function test_card_payment_can_be_declined_and_leaves_sale_unpaid(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 40, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        $declined = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card',
                'idempotency_key' => 'idem-card-declined',
                'amount' => 40,
                'simulate' => 'decline',
            ]);

        $declined->assertUnprocessable();
        $declined->assertJsonPath('payment.status', 'declined');
        $declined->assertJsonPath('sale.status', SaleStatus::Priced->value);
    }

    public function test_split_tender_sale_completes_once_fully_paid(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 30, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        $first = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash',
                'idempotency_key' => 'idem-split-1',
                'amount' => 10,
                'amount_tendered' => 10,
            ]);
        $first->assertCreated();
        $first->assertJsonPath('sale.status', SaleStatus::PaymentPending->value);

        $second = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'card',
                'idempotency_key' => 'idem-split-2',
                'amount' => 20,
            ]);
        $second->assertCreated();
        $second->assertJsonPath('sale.status', SaleStatus::Completed->value);

        $this->assertCount(2, $this->actingAs($cashier, 'sanctum')
            ->getJson("/api/v1/sales/{$sale['id']}/payments")
            ->json('payments'));
    }

    public function test_duplicate_payment_idempotency_key_is_not_double_charged(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        $body = [
            'tender_type' => 'cash',
            'idempotency_key' => 'idem-retry',
            'amount' => 10,
            'amount_tendered' => 10,
        ];

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", $body)
            ->assertCreated();

        // Simulated network retry with the same idempotency key must not create a second payment.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", $body)
            ->assertCreated();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_receipt_reprint_is_marked_and_audited(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash',
                'idempotency_key' => 'idem-receipt',
                'amount' => 10,
                'amount_tendered' => 10,
            ])->assertCreated();

        $reprint = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/receipt/reprint", ['reason' => 'Customer lost original']);

        $reprint->assertOk();
        $reprint->assertJsonPath('receipt.is_reprint', true);
        $reprint->assertJsonPath('receipt.reprint_count', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.receipt.reprint']);
    }
}
