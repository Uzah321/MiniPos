<?php

namespace Tests\Feature;

use App\Customers\Customer;
use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Sales\SaleStatus;
use App\Terminals\Terminal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditNoteReturnTest extends TestCase
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
     * Opens a shift + till, starts a sale, adds one line and pays it in
     * full with the given tender, returning the completed sale payload.
     */
    private function completeSale(User $cashier, Item $item, string $quantity = '1', string $tender = 'cash', ?string $customerQuery = null): array
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

        if ($customerQuery) {
            $this->actingAs($cashier, 'sanctum')
                ->postJson("/api/v1/sales/{$sale['id']}/customer", ['query' => $customerQuery])
                ->assertOk();
        }

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/lines", ['item_id' => $item->id, 'quantity' => $quantity])
            ->assertCreated();

        $sale = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/sales/{$sale['id']}")->json('sale');

        $payment = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/payments", array_filter([
                'tender_type' => $tender,
                'idempotency_key' => 'complete-'.Str::random(10),
                'amount' => $sale['total'],
                'amount_tendered' => $tender === 'cash' ? $sale['total'] : null,
            ]));
        $payment->assertCreated();

        return $payment->json('sale');
    }

    public function test_full_return_refunds_original_cash_tender_and_completes(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 20, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $return = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Customer changed mind',
            ]);
        $return->assertCreated()->assertJsonPath('credit_note.refund_method', 'cash');
        $creditNoteId = $return->json('credit_note.id');

        $refund = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/refund", []);

        $refund->assertOk();
        $refund->assertJsonPath('credit_note.status', 'completed');
        $this->assertNotNull($refund->json('credit_note.credit_note_number'));
        $this->assertDatabaseHas('cash_movements', ['type' => 'refund', 'amount' => '20.0000']);

        $saleAfter = $this->actingAs($cashier, 'sanctum')->getJson("/api/v1/sales/{$sale['id']}")->json('sale');
        $this->assertSame(SaleStatus::Returned->value, $saleAfter['status']);
    }

    public function test_partial_return_calculates_proportional_amount_and_prevents_over_return(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 10, 'tax_rate' => 0.10]);
        $sale = $this->completeSale($cashier, $item, '4', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $partial = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'One item was faulty',
            ]);
        $partial->assertCreated();
        // 4 units total 44.00 (40 + 10% tax); one unit's share is 11.00.
        $partial->assertJsonPath('credit_note.total', '11.0000');

        // Only 3 of the 4 units remain returnable now.
        $overReturn = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 4]],
                'reason' => 'Trying to return too many',
            ]);
        $overReturn->assertUnprocessable();
    }

    public function test_return_after_the_return_period_is_rejected(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 20, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $this->travel(31)->days();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Too late',
            ])
            ->assertUnprocessable();
    }

    public function test_return_above_manager_threshold_requires_manager_pin(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        $item = $this->makeItem(['price' => 150, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Too expensive to auto-approve',
            ])
            ->assertUnprocessable();

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Manager approved',
                'manager_pin' => '9999',
            ])
            ->assertCreated();
    }

    public function test_return_rejection_moves_no_money(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 20, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $return = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Suspicious return',
            ]);
        $creditNoteId = $return->json('credit_note.id');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/reject", ['reason' => 'Item condition unacceptable'])
            ->assertOk()
            ->assertJsonPath('credit_note.status', 'rejected');

        $this->assertDatabaseMissing('cash_movements', ['type' => 'refund']);

        // A rejected return can no longer be refunded.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/refund", [])
            ->assertUnprocessable();
    }

    public function test_card_refund_timeout_can_be_resolved_via_query(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 30, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'card');
        $lineId = $sale['lines'][0]['id'];

        $return = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Wrong item',
            ]);
        $creditNoteId = $return->json('credit_note.id');
        $return->assertJsonPath('credit_note.refund_method', 'card');

        $timedOut = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/refund", ['simulate' => 'timeout']);
        $timedOut->assertStatus(202)->assertJsonPath('credit_note.status', 'refund_unknown');

        $resolved = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/refund/query", ['resolve' => 'approved']);
        $resolved->assertOk()->assertJsonPath('credit_note.status', 'completed');
    }

    public function test_no_receipt_return_requires_manager_and_issues_store_credit(): void
    {
        $cashier = $this->makeCashier();
        $this->makeManager('9999');
        $customer = Customer::create(['name' => 'Walk In', 'loyalty_id' => 'LOY-NR-001']);
        $item = $this->makeItem(['price' => 40, 'tax_rate' => 0]);

        $withoutManager = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns/no-receipt', [
                'customer_query' => 'LOY-NR-001',
                'item_id' => $item->id,
                'quantity' => 1,
                'reason' => 'No receipt available',
            ]);
        $withoutManager->assertUnprocessable();

        $created = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns/no-receipt', [
                'customer_query' => 'LOY-NR-001',
                'item_id' => $item->id,
                'quantity' => 1,
                'reason' => 'No receipt available',
                'manager_pin' => '9999',
            ]);
        $created->assertCreated();
        $created->assertJsonPath('credit_note.refund_method', 'store_credit');
        $created->assertJsonPath('credit_note.total', '40.0000');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$created->json('credit_note.id')}/refund", [])
            ->assertOk()
            ->assertJsonPath('credit_note.status', 'completed');

        $this->assertSame('40.0000', (string) $customer->fresh()->store_credit_balance);
    }

    public function test_exchange_via_store_credit_refund_and_store_credit_tender(): void
    {
        $cashier = $this->makeCashier();
        Customer::create(['name' => 'Exchanger', 'loyalty_id' => 'LOY-EXCH']);
        $oldItem = $this->makeItem(['price' => 50, 'tax_rate' => 0]);
        $newItem = $this->makeItem(['price' => 70, 'tax_rate' => 0]);

        $sale = $this->completeSale($cashier, $oldItem, '1', 'cash', customerQuery: 'LOY-EXCH');
        $lineId = $sale['lines'][0]['id'];

        $return = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Exchange for a different item',
                'refund_method' => 'store_credit',
                'allow_alternate_method' => true,
            ]);
        $return->assertCreated();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$return->json('credit_note.id')}/refund", [])
            ->assertOk();

        $newSale = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/sales', ['terminal_id' => $this->terminal->id])
            ->assertCreated()
            ->json('sale');

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$newSale['id']}/customer", ['query' => 'LOY-EXCH'])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$newSale['id']}/lines", ['item_id' => $newItem->id])
            ->assertCreated();

        $storeCreditLeg = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$newSale['id']}/payments", [
                'tender_type' => 'store_credit', 'idempotency_key' => 'exchange-sc', 'amount' => 50,
            ]);
        $storeCreditLeg->assertCreated()->assertJsonPath('sale.status', SaleStatus::PaymentPending->value);

        $cashLeg = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/sales/{$newSale['id']}/payments", [
                'tender_type' => 'cash', 'idempotency_key' => 'exchange-cash', 'amount' => 20, 'amount_tendered' => 20,
            ]);
        $cashLeg->assertCreated()->assertJsonPath('sale.status', SaleStatus::Completed->value);
    }

    public function test_credit_note_reprint_is_audited(): void
    {
        $cashier = $this->makeCashier();
        $item = $this->makeItem(['price' => 20, 'tax_rate' => 0]);
        $sale = $this->completeSale($cashier, $item, '1', 'cash');
        $lineId = $sale['lines'][0]['id'];

        $return = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/v1/returns', [
                'sale_id' => $sale['id'],
                'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
                'reason' => 'Reprint test',
            ]);
        $creditNoteId = $return->json('credit_note.id');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/credit-notes/{$creditNoteId}/refund", [])->assertOk();

        $reprint = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/credit-notes/{$creditNoteId}/reprint", ['reason' => 'Customer lost the copy']);

        $reprint->assertOk()->assertJsonPath('credit_note.reprint_count', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'credit_note.reprint']);
    }
}
