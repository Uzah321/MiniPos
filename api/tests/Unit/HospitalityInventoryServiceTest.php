<?php

namespace Tests\Unit;

use App\Services\Inventory\HospitalityStockIssueService;
use App\Services\Inventory\StockAreaOverviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Product\Models\StockMovement;
use Tests\TestCase;

class HospitalityInventoryServiceTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for hospitality inventory database tests.');
        }

        $this->databaseReady = true;

        Schema::create('company_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('branch_name')->nullable();
            $table->string('branch_code')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable();
            $table->boolean('is_global')->default(true);
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('product_type')->default('simple');
            $table->string('stock_usage_type')->nullable();
            $table->string('default_issue_destination')->nullable();
            $table->string('expense_account_code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->string('stock_area')->default('front');
            $table->decimal('quantity_on_hand', 15, 4)->default(0);
            $table->decimal('reserved_quantity', 15, 4)->default(0);
            $table->decimal('available_quantity', 15, 4)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'product_id', 'stock_area'], 'test_stock_area_unique');
        });

        Schema::create('product_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->decimal('last_cost', 15, 4)->default(0);
            $table->decimal('total_quantity', 15, 4)->default(0);
            $table->decimal('total_value', 15, 4)->default(0);
            $table->string('currency_code')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('product_id');
            $table->string('movement_type');
            $table->string('stock_area')->nullable();
            $table->string('from_stock_area')->nullable();
            $table->string('to_stock_area')->nullable();
            $table->decimal('quantity_in', 15, 4)->default(0);
            $table->decimal('quantity_out', 15, 4)->default(0);
            $table->decimal('balance_after', 15, 4)->nullable();
            $table->decimal('unit_cost', 15, 6)->default(0);
            $table->decimal('total_cost', 15, 6)->default(0);
            $table->string('currency')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('issue_destination')->nullable();
            $table->string('stock_usage_type')->nullable();
            $table->string('expense_account_code')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('company_branches')->insert([
            ['id' => 'branch-a', 'company_id' => 'company-a', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'branch-b', 'company_id' => 'company-b', 'branch_name' => 'Foreign', 'branch_code' => 'OTHER', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('stock_movements');
            Schema::dropIfExists('product_costs');
            Schema::dropIfExists('product_stocks');
            Schema::dropIfExists('products');
            Schema::dropIfExists('company_branches');
        }

        parent::tearDown();
    }

    public function test_issue_to_front_is_costed_atomic_auditable_and_idempotent(): void
    {
        $this->product('turnover-product', 'turnover', 'raw_material');
        $this->stock('back-stock', 'turnover-product', 'back', 10);
        $this->stock('front-stock', 'turnover-product', 'front', 2);
        $this->cost('turnover-product', 4.5, 'USD');

        $context = [
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'reference_type' => 'enterprise_stock_issue',
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'issue_name' => 'Kitchen replenishment',
            'issue_type' => 'front',
            'issue_destination' => 'front',
            'requested_by' => 'Logged In User',
            'requested_by_id' => 'user-a',
            'issue_purpose' => 'Service stock',
            'items' => [['product_id' => 'turnover-product', 'quantity' => 3]],
            'user_id' => 'user-a',
        ];

        $result = app(HospitalityStockIssueService::class)->issue($context);

        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame(7.0, (float) DB::table('product_stocks')->where('id', 'back-stock')->value('available_quantity'));
        $this->assertSame(5.0, (float) DB::table('product_stocks')->where('id', 'front-stock')->value('available_quantity'));
        $this->assertSame(13.5, (float) $result['summary']['total_value']);
        $this->assertMatchesRegularExpression('/^ISS-\d{8}-[A-Z0-9]{12}$/', $result['header']['issue_number']);
        $this->assertSame($result['header']['issue_number'], $result['issue_number']);
        $this->assertSame('Logged In User', $result['header']['requested_by']);
        $this->assertSame('user-a', $result['header']['requested_by_id']);

        $movement = StockMovement::query()->firstOrFail();
        $this->assertSame('back', $movement->from_stock_area);
        $this->assertSame('front', $movement->to_stock_area);
        $this->assertSame('front', $movement->issue_destination);
        $this->assertSame('turnover', $movement->stock_usage_type);
        $this->assertSame('USD', $movement->currency_code);
        $this->assertSame(3.0, (float) $movement->quantity_in);
        $this->assertSame(3.0, (float) $movement->quantity_out);
        $this->assertSame(5.0, (float) $movement->balance_after);
        $this->assertSame(7.0, (float) data_get($movement->metadata, 'back_available_after'));
        $this->assertSame($result['header']['issue_number'], data_get($movement->metadata, 'issue_number'));
        $this->assertSame('user-a', data_get($movement->metadata, 'requested_by_id'));

        $replay = app(HospitalityStockIssueService::class)->issue($context);

        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($result['header']['issue_number'], $replay['header']['issue_number']);
        $this->assertSame($result['issue_number'], $replay['issue_number']);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(7.0, (float) DB::table('product_stocks')->where('id', 'back-stock')->value('available_quantity'));
        $this->assertSame(5.0, (float) DB::table('product_stocks')->where('id', 'front-stock')->value('available_quantity'));

        $changedRequest = $context;
        $changedRequest['items'][0]['quantity'] = 1;
        $this->expectException(ValidationException::class);
        app(HospitalityStockIssueService::class)->issue($changedRequest);
    }

    public function test_non_turnover_stock_cannot_be_issued_to_front_and_is_not_mutated(): void
    {
        $this->product('consumable-product', 'non_turnover', 'consumable');
        $this->stock('back-consumable', 'consumable-product', 'back', 8);

        try {
            app(HospitalityStockIssueService::class)->issue([
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'reference_type' => 'enterprise_stock_issue',
                'idempotency_key' => '22222222-2222-4222-8222-222222222222',
                'issue_destination' => 'front',
                'items' => [['product_id' => 'consumable-product', 'quantity' => 2]],
            ]);
            $this->fail('Expected a non-turnover validation exception.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Non-Turnover Stock', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(8.0, (float) DB::table('product_stocks')->where('id', 'back-consumable')->value('available_quantity'));
        $this->assertDatabaseMissing('product_stocks', ['product_id' => 'consumable-product', 'stock_area' => 'front']);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_stock_area_overview_separates_back_front_and_non_turnover_stock_by_tenant(): void
    {
        $this->product('turnover-product', 'turnover', 'raw_material');
        $this->product('consumable-product', 'non_turnover', 'consumable', 'Cleaning supplies', 'expense', 'OPS-CLEAN');
        $this->product('foreign-product', 'non_turnover', 'consumable', 'Foreign supplies', 'expense', null, 'company-b', 'branch-b');

        $this->stock('turnover-back', 'turnover-product', 'back', 10);
        $this->stock('turnover-front', 'turnover-product', 'front', 4);
        $this->stock('consumable-back', 'consumable-product', 'back', 7);
        $this->stock('consumable-front', 'consumable-product', 'front', 1);
        $this->stock('foreign-back', 'foreign-product', 'back', 100, 'company-b', 'branch-b');
        $this->cost('turnover-product', 2, 'USD');
        $this->cost('consumable-product', 3, 'USD', quantity: 8);
        $this->cost('foreign-product', 99, 'USD', 'company-b', 'branch-b');

        $overview = app(StockAreaOverviewService::class)->forCompany('company-a', 'branch-a');

        $this->assertSame(17.0, (float) $overview['summary']['back_stock_quantity_on_hand']);
        $this->assertSame(5.0, (float) $overview['summary']['front_stock_quantity_on_hand']);
        $this->assertSame(8.0, (float) $overview['summary']['non_turnover_quantity_on_hand']);
        $this->assertSame(24.0, (float) $overview['summary']['non_turnover_stock_value']);
        $this->assertCount(1, $overview['non_turnover_stock']['products']);

        $row = $overview['non_turnover_stock']['products'][0];
        $this->assertSame('consumable-product', $row['product_id']);
        $this->assertSame('non_turnover', $row['stock_usage_type']);
        $this->assertSame(7.0, (float) $row['back_quantity_on_hand']);
        $this->assertSame(1.0, (float) $row['front_quantity_on_hand']);
        $this->assertSame('OPS-CLEAN', $row['expense_account_code']);
    }

    public function test_stock_area_grouping_uses_an_unambiguous_alias_for_mysql_strict_mode(): void
    {
        $source = file_get_contents((new \ReflectionClass(StockAreaOverviewService::class))->getFileName());

        $this->assertStringContainsString('as normalized_stock_area', $source);
        $this->assertStringContainsString("groupByRaw('normalized_stock_area')", $source);
        $this->assertStringContainsString('CASE normalized_stock_area', $source);
    }

    public function test_non_turnover_internal_issue_is_classified_costed_and_kept_out_of_front_stock(): void
    {
        $this->product('consumable-product', 'non_turnover', 'consumable', 'Cleaning supplies', 'expense', 'OPS-CLEAN');
        $this->stock('back-consumable', 'consumable-product', 'back', 8);
        $this->cost('consumable-product', 3, 'USD', quantity: 8);

        $result = app(HospitalityStockIssueService::class)->issue([
            'company_id' => 'company-a',
            'branch_id' => 'branch-a',
            'reference_type' => 'enterprise_stock_issue',
            'idempotency_key' => '33333333-3333-4333-8333-333333333333',
            'issue_name' => 'Cleaning supplies issue',
            'issue_type' => 'expense',
            'issue_destination' => 'expense',
            'issue_purpose' => 'Housekeeping',
            'items' => [['product_id' => 'consumable-product', 'quantity' => 2]],
        ]);

        $this->assertSame(6.0, (float) DB::table('product_stocks')->where('id', 'back-consumable')->value('available_quantity'));
        $this->assertSame(6.0, (float) DB::table('product_costs')->where('product_id', 'consumable-product')->value('total_quantity'));
        $this->assertSame(18.0, (float) DB::table('product_costs')->where('product_id', 'consumable-product')->value('total_value'));
        $this->assertDatabaseMissing('product_stocks', ['product_id' => 'consumable-product', 'stock_area' => 'front']);
        $this->assertSame(1.0, (float) $result['summary']['non_turnover_lines']);
        $this->assertSame(6.0, (float) $result['summary']['internal_value']);

        $movement = StockMovement::query()->firstOrFail();
        $this->assertSame('boh_issue_to_expense', $movement->movement_type);
        $this->assertSame('expense', $movement->issue_destination);
        $this->assertSame('non_turnover', $movement->stock_usage_type);
        $this->assertSame('OPS-CLEAN', $movement->expense_account_code);
        $this->assertSame(0.0, (float) $movement->quantity_in);
        $this->assertSame(2.0, (float) $movement->quantity_out);
    }

    private function product(
        string $id,
        string $usage,
        string $type,
        ?string $name = null,
        ?string $destination = null,
        ?string $expenseAccount = null,
        string $companyId = 'company-a',
        string $branchId = 'branch-a'
    ): void {
        DB::table('products')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'is_global' => false,
            'name' => $name ?: $id,
            'code' => strtoupper(substr($id, 0, 8)),
            'sku' => strtoupper(substr($id, 0, 8)),
            'product_type' => $type,
            'stock_usage_type' => $usage,
            'default_issue_destination' => $destination,
            'expense_account_code' => $expenseAccount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stock(
        string $id,
        string $productId,
        string $area,
        float $quantity,
        string $companyId = 'company-a',
        string $branchId = 'branch-a'
    ): void {
        DB::table('product_stocks')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'stock_area' => $area,
            'quantity_on_hand' => $quantity,
            'reserved_quantity' => 0,
            'available_quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cost(
        string $productId,
        float $amount,
        string $currency,
        string $companyId = 'company-a',
        string $branchId = 'branch-a',
        float $quantity = 0
    ): void {
        DB::table('product_costs')->insert([
            'id' => 'cost-'.$productId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'average_cost' => $amount,
            'last_cost' => $amount,
            'total_quantity' => $quantity,
            'total_value' => $quantity * $amount,
            'currency_code' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
