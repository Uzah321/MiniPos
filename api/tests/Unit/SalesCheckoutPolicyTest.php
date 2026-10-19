<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Enterprise\HospitalityMenuPriceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Models\PaymentType;
use Modules\Product\Models\Product;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class SalesCheckoutPolicyTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return;
        }

        $this->databaseReady = true;
        Schema::dropIfExists('shop_menu_item_overrides');
        Schema::dropIfExists('menu_item_channels');
        Schema::dropIfExists('brand_products');
        Schema::dropIfExists('shops');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->boolean('is_global')->default(false);
            $table->string('name');
            $table->string('product_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('countries', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('timezone')->nullable();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id');
            $table->string('brand_id');
            $table->string('branch_id');
            $table->string('timezone')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('brand_products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('item_kind');
            $table->string('external_code')->nullable();
            $table->string('legacy_code')->nullable();
            $table->string('market')->nullable();
            $table->string('department')->nullable();
            $table->string('flavour')->nullable();
            $table->string('size_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('menu_item_channels', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('channel_code');
            $table->decimal('price_exclusive', 18, 4)->nullable();
            $table->decimal('price_inclusive', 18, 4)->nullable();
            $table->string('currency_code', 3);
            $table->boolean('is_available')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('shop_menu_item_overrides', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shop_id');
            $table->string('product_id');
            $table->string('channel_key')->default('*');
            $table->boolean('is_available')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
        });
        DB::table('countries')->insert([
            'id' => 'country-a',
            'timezone' => 'Africa/Harare',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('shop_menu_item_overrides');
            Schema::dropIfExists('menu_item_channels');
            Schema::dropIfExists('brand_products');
            Schema::dropIfExists('shops');
            Schema::dropIfExists('countries');
            Schema::dropIfExists('products');
        }

        parent::tearDown();
    }

    public function test_hospitality_only_product_types_ignore_a_legacy_retail_branch_mode(): void
    {
        $settings = new class extends CompanySettingService
        {
            public array $calls = [];

            public function businessMode(string $companyId, ?string $branchId = null): string
            {
                $this->calls[] = [$companyId, $branchId];

                return $branchId === 'hospitality-branch' ? 'hospitality' : 'retail';
            }
        };
        $controller = $this->controller($settings);

        $this->assertSame(
            ['finished_good'],
            $this->invoke($controller, 'allowedPosSaleProductTypes', 'company-a', 'hospitality-branch')
        );
        $this->assertSame(
            ['finished_good'],
            $this->invoke($controller, 'allowedPosSaleProductTypes', 'company-a', 'retail-branch')
        );
        $this->assertSame([], $settings->calls);
    }

    public function test_pos_product_scope_enforces_company_branch_active_and_hospitality_type_boundaries(): void
    {
        $this->requireDatabase();

        $this->insertShop('branch-a');
        // Only what Back Office Menu Item Management lists for branch-a is
        // sellable there: unowned or imported catalogue items are not.
        $this->insertProduct('denied-unowned-catalogue', 'finished_good');
        $this->insertProduct('allowed-current-branch', 'finished_good', branchId: 'branch-a');
        $this->insertProduct('denied-global-other-branch', 'finished_good', branchId: 'branch-b', isGlobal: true);
        $this->insertProduct('denied-other-branch', 'finished_good', branchId: 'branch-b');
        $this->insertProduct('denied-simple', 'simple');
        $this->insertProduct('denied-raw', 'raw_material');
        $this->insertProduct('denied-inactive', 'finished_good', isActive: false);
        $this->insertProduct('denied-deleted', 'finished_good', deletedAt: '2026-10-07 10:00:00');
        $this->insertProduct('denied-foreign-company', 'finished_good', companyId: 'company-b');
        foreach ([
            'denied-unowned-catalogue',
            'allowed-current-branch',
            'denied-global-other-branch',
            'denied-other-branch',
            'denied-simple',
            'denied-raw',
            'denied-inactive',
            'denied-deleted',
            'denied-foreign-company',
        ] as $productId) {
            $this->insertMenuItem($productId);
        }

        $controller = $this->controller();
        $query = Product::query();
        $this->invoke(
            $controller,
            'scopePosSaleProducts',
            $query,
            'company-a',
            'branch-a',
            ['finished_good']
        );

        $this->assertSame([
            'allowed-current-branch',
        ], $query->orderBy('id')->pluck('id')->all());
    }

    public function test_hospitality_product_scope_only_allows_finished_goods(): void
    {
        $this->requireDatabase();

        $this->insertShop('hospitality-branch');
        $this->insertProduct('finished', 'finished_good', branchId: 'hospitality-branch');
        $this->insertProduct('uncatalogued-finished', 'finished_good', branchId: 'hospitality-branch');
        $this->insertProduct('simple', 'simple', branchId: 'hospitality-branch');
        $this->insertProduct('raw', 'raw_material', branchId: 'hospitality-branch');
        foreach (['finished', 'simple', 'raw'] as $productId) {
            $this->insertMenuItem($productId);
        }

        $settings = new class extends CompanySettingService
        {
            public function businessMode(string $companyId, ?string $branchId = null): string
            {
                return 'hospitality';
            }
        };
        $controller = $this->controller($settings);
        $types = $this->invoke(
            $controller,
            'allowedPosSaleProductTypes',
            'company-a',
            'hospitality-branch'
        );
        $query = Product::query();
        $this->invoke(
            $controller,
            'scopePosSaleProducts',
            $query,
            'company-a',
            'hospitality-branch',
            $types
        );

        $this->assertSame(['finished'], $query->pluck('id')->all());
    }

    public function test_product_policy_is_applied_before_new_sale_work_and_again_under_lock(): void
    {
        $scope = $this->methodSource('scopePosSaleProducts');
        $store = $this->methodSource('store');
        $receiptLines = $this->methodSource('createReceiptLines');

        $this->assertStringContainsString("->where('company_id', \$companyId)", $scope);
        $this->assertStringContainsString("->where('is_active', true)", $scope);
        $this->assertStringContainsString('LOWER(product_type)', $scope);
        $this->assertStringContainsString("->where('is_global', true)", $scope);
        $this->assertStringContainsString("->orWhere('branch_id', \$branchId)", $scope);
        $this->assertGreaterThan(
            strpos($store, 'buildDuplicateSaleCreatedResponse'),
            strpos($store, '$productPolicyValidator = Validator::make')
        );
        $this->assertStringContainsString('$this->scopePosSaleProducts(', $receiptLines);
        $this->assertStringContainsString('->lockForUpdate()', $receiptLines);
    }

    public function test_allowlisted_platform_owner_can_cash_for_a_target_company_without_role_pivot_leakage(): void
    {
        $store = $this->methodSource('store');

        $this->assertStringContainsString(
            'SuperAdminService::class)->isPlatformAdministrator($user)',
            $store
        );
        $this->assertStringContainsString(
            "hasPermission(\$user->role_id, 'create_sale', \$company->id)",
            $store
        );
        $this->assertGreaterThan(
            strpos($store, 'isPlatformAdministrator($user)'),
            strpos($store, "hasPermission(\$user->role_id, 'create_sale'")
        );
    }

    public function test_single_non_tender_payment_is_canonical_exact_total(): void
    {
        $settlement = $this->settle([
            ['payment_type_id' => 'card', 'amount' => 99],
        ], [$this->paymentType('card', false)], 12.5);

        $this->assertSame(12.5, $settlement['amount_tendered']);
        $this->assertSame(0.0, $settlement['change']);
        $this->assertSame(12.5, $settlement['rows'][0]['amount_tendered']);
        $this->assertSame(12.5, $settlement['rows'][0]['sale_total']);
    }

    public function test_tender_payment_keeps_over_tender_and_change(): void
    {
        $settlement = $this->settle([
            ['payment_type_id' => 'cash', 'amount' => 20],
        ], [$this->paymentType('cash', true)], 12.5);

        $this->assertSame(20.0, $settlement['amount_tendered']);
        $this->assertSame(7.5, $settlement['change']);
        $this->assertSame(12.5, $settlement['rows'][0]['sale_total']);
        $this->assertSame('cash', $settlement['change_payment_type_id']);
    }

    public function test_split_payments_keep_allocations_and_only_tender_rows_create_change(): void
    {
        $types = [
            $this->paymentType('card', false),
            $this->paymentType('cash', true),
        ];

        $cardThenCash = $this->settle([
            ['payment_type_id' => 'card', 'amount' => 5],
            ['payment_type_id' => 'cash', 'amount' => 10],
        ], $types, 12);

        $this->assertSame(15.0, $cardThenCash['amount_tendered']);
        $this->assertSame(3.0, $cardThenCash['change']);
        $this->assertSame(5.0, $cardThenCash['rows'][0]['sale_total']);
        $this->assertSame(7.0, $cardThenCash['rows'][1]['sale_total']);
        $this->assertSame(3.0, $cardThenCash['rows'][1]['change_amount']);

        $cashThenCard = $this->settle([
            ['payment_type_id' => 'cash', 'amount' => 8],
            ['payment_type_id' => 'card', 'amount' => 5],
        ], $types, 10);

        $this->assertSame(10.0, $cashThenCard['amount_tendered']);
        $this->assertSame(0.0, $cashThenCard['change']);
        $this->assertSame(8.0, $cashThenCard['rows'][0]['sale_total']);
        $this->assertSame(2.0, $cashThenCard['rows'][1]['amount_tendered']);
        $this->assertSame(2.0, $cashThenCard['rows'][1]['sale_total']);
    }

    public function test_split_payment_shortfall_is_rejected_as_a_validation_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount tendered is less than sale total.');

        $this->settle([
            ['payment_type_id' => 'card', 'amount' => 4],
            ['payment_type_id' => 'cash', 'amount' => 5],
        ], [
            $this->paymentType('card', false),
            $this->paymentType('cash', true),
        ], 10);
    }

    public function test_change_bearing_split_row_must_be_the_final_positive_payment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A payment row that creates change must be the final positive split payment.');

        $this->settle([
            ['payment_type_id' => 'cash', 'amount' => 15],
            ['payment_type_id' => 'card', 'amount' => 2],
        ], [
            $this->paymentType('cash', true),
            $this->paymentType('card', false),
        ], 10);
    }

    private function controller(?CompanySettingService $settings = null): SalesController
    {
        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();

        $menuPrices = new ReflectionProperty($controller, 'hospitalityMenuPrices');
        $menuPrices->setAccessible(true);
        $menuPrices->setValue(
            $controller,
            new HospitalityMenuPriceService(new EnterpriseHierarchyService)
        );

        if ($settings) {
            $property = new ReflectionProperty($controller, 'companySettingService');
            $property->setAccessible(true);
            $property->setValue($controller, $settings);
        }

        return $controller;
    }

    private function insertShop(string $branchId): void
    {
        DB::table('shops')->insert([
            'id' => "shop-{$branchId}",
            'company_id' => 'company-a',
            'country_id' => 'country-a',
            'brand_id' => 'brand-a',
            'branch_id' => $branchId,
            'timezone' => 'Africa/Harare',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMenuItem(string $productId): void
    {
        DB::table('brand_products')->insert([
            'id' => "catalogue-{$productId}",
            'company_id' => 'company-a',
            'brand_id' => 'brand-a',
            'product_id' => $productId,
            'item_kind' => 'menu_item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_item_channels')->insert([
            'id' => "channel-{$productId}",
            'company_id' => 'company-a',
            'brand_id' => 'brand-a',
            'product_id' => $productId,
            'channel_code' => 'POS',
            'price_inclusive' => 1,
            'currency_code' => 'USD',
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settle(array $rows, array $paymentTypes, float $saleTotal): array
    {
        return $this->invoke(
            $this->controller(),
            'settleSalePaymentRows',
            collect($rows),
            collect($paymentTypes)->keyBy('id'),
            $saleTotal
        );
    }

    private function paymentType(string $id, bool $requiresAmountTendered): PaymentType
    {
        return (new PaymentType)->forceFill([
            'id' => $id,
            'name' => ucfirst($id),
            'requiresAmountTendered' => $requiresAmountTendered,
        ]);
    }

    private function invoke(object $target, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$arguments);
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(SalesController::class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    private function insertProduct(
        string $id,
        string $productType,
        ?string $branchId = null,
        bool $isGlobal = false,
        bool $isActive = true,
        ?string $deletedAt = null,
        string $companyId = 'company-a'
    ): void {
        DB::table('products')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'is_global' => $isGlobal,
            'name' => $id,
            'product_type' => $productType,
            'is_active' => $isActive,
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => '2026-10-07 09:00:00',
            'deleted_at' => $deletedAt,
        ]);
    }

    private function requireDatabase(): void
    {
        if (! $this->databaseReady) {
            $this->markTestSkipped('The SQLite PDO driver is required for POS product scope tests.');
        }
    }
}
