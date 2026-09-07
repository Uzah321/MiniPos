<?php

namespace Tests\Feature;

use App\Customers\Customer;
use App\Discounts\Coupon;
use App\Discounts\Promotion;
use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleAdjustmentsTest extends TestCase
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

    public function test_customer_can_be_attached_and_removed(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $customer = Customer::create(['name' => 'Jane Shopper', 'loyalty_id' => 'LOY-001']);

        $attached = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => 'LOY-001']);
        $attached->assertOk()->assertJsonPath('sale.customer_id', $customer->id);

        $removed = $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/sales/{$sale['id']}/customer");
        $removed->assertOk()->assertJsonPath('sale.customer_id', null);
    }

    public function test_quick_customer_registration_creates_and_attaches_a_customer(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/customer/register", [
                'name' => 'New Customer',
                'phone' => '0821234567',
                'consent' => true,
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('sale.customer_id'));
        $this->assertDatabaseHas('customers', ['phone' => '0821234567']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.customer.registered']);
    }

    public function test_quick_registration_rejects_duplicate_phone(): void
    {
        Customer::create(['name' => 'Existing', 'phone' => '0821234567']);
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/customer/register", [
                'name' => 'Duplicate',
                'phone' => '0821234567',
                'consent' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_manual_line_discount_below_threshold_applies_without_manager(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $added = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $lineId = $added->json('line.id');

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/discounts/manual", [
                'line_id' => $lineId,
                'type' => 'percent',
                'value' => 10,
                'reason' => 'Loyal customer',
            ]);

        $response->assertOk()->assertJsonPath('sale.total', '90.0000');
    }

    public function test_manual_line_discount_at_threshold_requires_manager_pin(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $added = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $lineId = $added->json('line.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/discounts/manual", [
                'line_id' => $lineId,
                'type' => 'percent',
                'value' => 20,
                'reason' => 'Manager special',
            ])
            ->assertUnprocessable();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/discounts/manual", [
                'line_id' => $lineId,
                'type' => 'percent',
                'value' => 20,
                'reason' => 'Manager special',
                'manager_pin' => '9999',
            ])
            ->assertOk()
            ->assertJsonPath('sale.total', '80.0000');
    }

    public function test_manual_basket_discount_spreads_proportionally_across_lines(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $itemA = $this->makeItem(['price' => 30, 'tax_rate' => 0]);
        $itemB = $this->makeItem(['price' => 70, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $itemA->id]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $itemB->id]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/discounts/manual", [
                'type' => 'fixed',
                'value' => 10,
                'reason' => 'Basket-wide promo',
            ]);

        $response->assertOk();
        $response->assertJsonPath('sale.discount_total', '10.0000');
        $response->assertJsonPath('sale.total', '90.0000');

        $lines = collect($response->json('sale.lines'))->keyBy('item_id');
        $this->assertSame('3.0000', $lines[$itemA->id]['discount_amount']);
        $this->assertSame('7.0000', $lines[$itemB->id]['discount_amount']);
    }

    public function test_automatic_promotion_applies_once_minimum_quantity_is_met(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0]);

        Promotion::create([
            'item_id' => $item->id,
            'min_quantity' => 3,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'active' => true,
        ]);

        $belowThreshold = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 2]);
        $this->assertSame('0.0000', $belowThreshold->json('line.promotion_discount_amount'));

        $atThreshold = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => 1]);
        $this->assertSame('6.0000', $atThreshold->json('line.promotion_discount_amount'));
        $atThreshold->assertJsonPath('sale.total', '24.0000');
    }

    public function test_coupon_redemption_applies_discount_and_enforces_usage_limit(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 50, 'tax_rate' => 0]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        Coupon::create(['code' => 'SAVE10', 'discount_type' => 'fixed', 'discount_value' => 10, 'usage_limit' => 1, 'active' => true]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/coupon", ['code' => 'SAVE10']);
        $response->assertOk()->assertJsonPath('sale.total', '40.0000');

        // Usage limit of 1 has now been reached; a second sale (same open shift/till) cannot reuse it.
        $secondSale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()
            ->json('sale');
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$secondSale['id']}/lines", ['item_id' => $item->id]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$secondSale['id']}/coupon", ['code' => 'SAVE10'])
            ->assertUnprocessable();
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 50, 'tax_rate' => 0]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);

        Coupon::create([
            'code' => 'EXPIRED5', 'discount_type' => 'fixed', 'discount_value' => 5, 'active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/coupon", ['code' => 'EXPIRED5'])
            ->assertUnprocessable();
    }

    public function test_loyalty_points_are_earned_on_completed_sale(): void
    {
        $cashier = $this->makeCashier();
        $customer = Customer::create(['name' => 'Points Earner', 'loyalty_id' => 'LOY-EARN']);
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => 'LOY-EARN']);

        $payment = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", [
                'tender_type' => 'cash', 'idempotency_key' => 'idem-loyalty-earn', 'amount' => 100, 'amount_tendered' => 100,
            ]);

        $payment->assertCreated()->assertJsonPath('sale.loyalty_points_earned', 10);
        $this->assertSame(10, $customer->fresh()->loyalty_points_balance);
    }

    public function test_loyalty_redemption_reduces_total_and_deducts_balance_immediately(): void
    {
        $cashier = $this->makeCashier();
        $customer = Customer::create(['name' => 'Redeemer', 'loyalty_id' => 'LOY-REDEEM', 'loyalty_points_balance' => 50]);
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => 'LOY-REDEEM']);

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/loyalty/redeem", ['points' => 20]);

        $response->assertOk()->assertJsonPath('sale.total', '98.0000');
        $this->assertSame(30, $customer->fresh()->loyalty_points_balance);
    }

    public function test_redeeming_more_points_than_the_balance_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        Customer::create(['name' => 'Low Balance', 'loyalty_id' => 'LOY-LOW', 'loyalty_points_balance' => 5]);
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => 'LOY-LOW']);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/loyalty/redeem", ['points' => 20])
            ->assertUnprocessable();
    }

    public function test_removing_customer_is_blocked_after_loyalty_redemption(): void
    {
        $cashier = $this->makeCashier();
        Customer::create(['name' => 'Redeemer', 'loyalty_id' => 'LOY-BLOCK', 'loyalty_points_balance' => 50]);
        $sale = $this->startSale($cashier);
        $item = $this->makeItem(['price' => 100, 'tax_rate' => 0]);

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => 'LOY-BLOCK']);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/loyalty/redeem", ['points' => 10]);

        $this->actingAs($cashier, 'sanctum')
            ->deleteJson("/api/v1/sales/{$sale['id']}/customer")
            ->assertUnprocessable();
    }
}
