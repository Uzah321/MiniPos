<?php

namespace Tests\Unit;

use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Enterprise\HospitalityMenuPriceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Modules\Company\Models\Shop;
use Modules\Product\Models\Product;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionMethod;

class EnterpriseHospitalityArchitectureMigrationTest extends TestCase
{
    /** @var object|null */
    private $migration;

    private bool $applicationReady = false;

    protected function setUp(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for architecture migration tests.');
        }

        parent::setUp();
        $this->applicationReady = true;

        $this->createBaseTables();
        $this->migration = require base_path(
            'Modules/Company/database/migrations/2026_10_13_180000_create_enterprise_hospitality_architecture.php'
        );
    }

    protected function tearDown(): void
    {
        if (! $this->applicationReady) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ([
                'hospitality_external_keys', 'hospitality_catalog_imports',
                'shop_menu_item_overrides', 'menu_item_modifier_exclusions',
                'menu_item_modifier_groups', 'modifier_group_options', 'modifier_groups',
                'menu_item_trading_schedules', 'menu_item_channels', 'product_uom_conversions',
                'brand_products', 'shops', 'complexes', 'brands', 'countries',
                'product_modifier_assignments', 'product_modifiers',
                'product_prep_message_assignments', 'product_prep_messages',
                'food_recipe_ingredients', 'food_recipes', 'sync_local_states', 'sync_nodes',
                'pos_tills', 'products', 'uoms', 'company_branches', 'companies',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        parent::tearDown();
    }

    public function test_it_backfills_the_enterprise_hierarchy_and_expands_the_hospitality_catalogue(): void
    {
        $companyId = '10000000-0000-0000-0000-000000000001';
        $branchId = '10000000-0000-0000-0000-000000000002';
        $tillId = '10000000-0000-0000-0000-000000000003';

        DB::table('companies')->insert([
            'id' => $companyId,
            'company_name' => 'Pizza Inn Zimbabwe',
            'country' => 'Zimbabwe',
            'city' => 'Harare',
        ]);
        DB::table('company_branches')->insert([
            'id' => $branchId,
            'company_id' => $companyId,
            'branch_name' => 'Avondale',
            'branch_code' => 'AVD',
            'branch_city' => 'Harare',
            'status' => 'active',
        ]);
        DB::table('pos_tills')->insert([
            'id' => $tillId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_code' => 'TILL-1',
            'till_name' => 'Front Counter',
        ]);
        DB::table('product_prep_messages')->insert([
            'id' => '10000000-0000-0000-0000-000000000010',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'message' => 'Well done',
        ]);

        $this->migration->up();

        foreach ([
            'countries', 'brands', 'complexes', 'shops', 'brand_products',
            'product_uom_conversions', 'menu_item_channels', 'menu_item_trading_schedules',
            'modifier_groups', 'modifier_group_options', 'menu_item_modifier_groups',
            'menu_item_modifier_exclusions', 'shop_menu_item_overrides',
            'hospitality_catalog_imports', 'hospitality_external_keys',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} should exist.");
        }

        $shop = DB::table('shops')->where('branch_id', $branchId)->first();
        $this->assertNotNull($shop);
        $this->assertSame($branchId, $shop->id);
        $this->assertSame($shop->id, DB::table('pos_tills')->where('id', $tillId)->value('shop_id'));
        $this->assertNotNull(DB::table('companies')->where('id', $companyId)->value('country_id'));
        $this->assertSame(
            $shop->brand_id,
            DB::table('product_prep_messages')
                ->where('id', '10000000-0000-0000-0000-000000000010')
                ->value('brand_id')
        );

        DB::table('products')->insert([
            'id' => '10000000-0000-0000-0000-000000000004',
            'company_id' => $companyId,
            'name' => 'Flour',
            'product_type' => 'raw_material',
        ]);
        $this->assertSame(
            'raw_material',
            DB::table('products')->where('name', 'Flour')->value('product_type')
        );

        $menuProductId = '10000000-0000-0000-0000-000000000005';
        DB::table('products')->insert([
            'id' => $menuProductId,
            'company_id' => $companyId,
            'name' => 'Classic Pizza',
            'product_type' => 'finished_good',
        ]);
        DB::table('brand_products')->insert([
            'id' => '10000000-0000-0000-0000-000000000006',
            'company_id' => $companyId,
            'brand_id' => $shop->brand_id,
            'product_id' => $menuProductId,
            'item_kind' => 'menu_item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shop_menu_item_overrides')->insert([
            [
                'id' => '10000000-0000-0000-0000-000000000007',
                'company_id' => $companyId,
                'brand_id' => $shop->brand_id,
                'shop_id' => $shop->id,
                'product_id' => $menuProductId,
                'is_available' => false,
                'channel_code' => null,
                'channel_key' => '*',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '10000000-0000-0000-0000-000000000008',
                'company_id' => $companyId,
                'brand_id' => $shop->brand_id,
                'shop_id' => $shop->id,
                'product_id' => $menuProductId,
                'is_available' => true,
                'channel_code' => 'TAKEAWAY',
                'channel_key' => 'TAKEAWAY',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('menu_item_channels')->insert([
            'id' => '10000000-0000-0000-0000-000000000009',
            'company_id' => $companyId,
            'brand_id' => $shop->brand_id,
            'product_id' => $menuProductId,
            'channel_code' => 'TAKEAWAY',
            'price_inclusive' => 10,
            'currency_code' => 'USD',
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shopModel = Shop::query()->findOrFail($shop->id);
        $visibility = app(EnterpriseHierarchyService::class);
        $this->assertSame(0, $visibility->visibleMenuQuery($shopModel)->count());
        $this->assertSame(1, $visibility->visibleMenuQuery($shopModel, 'takeaway')->count());
        $visibleItem = $visibility->visibleMenuQuery($shopModel, 'takeaway')->first();
        $menuPrices = app(HospitalityMenuPriceService::class);
        $resolvedPrice = $menuPrices->resolve(
            $companyId,
            $branchId,
            $menuProductId,
            (string) $visibleItem->menu_item_channel_id,
            'takeaway'
        );
        $compatiblePrice = $menuPrices->bootstrapPayload($resolvedPrice);
        $this->assertSame('10000000-0000-0000-0000-000000000009', $compatiblePrice['id']);
        $this->assertSame(10.0, $compatiblePrice['price']);
        $this->assertSame('Standard', $compatiblePrice['price_level']['name']);
        $this->assertSame('menu_item_channel', $compatiblePrice['pricing_source']);

        $salePriceResolver = new ReflectionMethod(SalesController::class, 'resolveProductPrice');
        $salePriceResolver->setAccessible(true);
        $originalChannel = config('servnest.catalogue.default_channel');
        config()->set('servnest.catalogue.default_channel', 'TAKEAWAY');
        try {
            $salePrice = $salePriceResolver->invoke(
                app(SalesController::class),
                Product::query()->findOrFail($menuProductId),
                $compatiblePrice['id'],
                $companyId,
                $branchId,
                null
            );
        } finally {
            config()->set('servnest.catalogue.default_channel', $originalChannel);
        }
        $this->assertSame($compatiblePrice['id'], (string) $salePrice->menu_item_channel_id);
        $this->assertSame($compatiblePrice['price'], (float) $salePrice->price);
        $this->assertSame('menu_item_channel', $salePrice->pricing_source);

        DB::table('menu_item_channels')
            ->where('id', '10000000-0000-0000-0000-000000000009')
            ->update(['price_inclusive' => null, 'price_exclusive' => null]);
        $this->assertSame(0, $visibility->visibleMenuQuery($shopModel, 'takeaway')->count());
        DB::table('menu_item_channels')
            ->where('id', '10000000-0000-0000-0000-000000000009')
            ->update(['price_inclusive' => 10]);

        $currentWeekday = now()->isoWeekday();
        $otherWeekday = $currentWeekday === 7 ? 1 : $currentWeekday + 1;
        DB::table('menu_item_trading_schedules')->insert([
            'id' => '10000000-0000-0000-0000-000000000011',
            'company_id' => $companyId,
            'brand_id' => $shop->brand_id,
            'product_id' => $menuProductId,
            'schedule_code' => 'TEST',
            'weekdays' => json_encode([$otherWeekday], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0, $visibility->visibleMenuQuery($shopModel, 'takeaway')->count());
        DB::table('menu_item_trading_schedules')
            ->where('id', '10000000-0000-0000-0000-000000000011')
            ->update(['weekdays' => json_encode([$currentWeekday], JSON_THROW_ON_ERROR)]);
        $this->assertSame(1, $visibility->visibleMenuQuery($shopModel, 'takeaway')->count());

        foreach (['brand_id', 'external_code', 'source_key', 'is_current'] as $column) {
            $this->assertTrue(Schema::hasColumn('food_recipes', $column));
        }
        foreach (['child_recipe_id', 'source_line_key', 'sequence_no', 'unit_cost', 'line_total'] as $column) {
            $this->assertTrue(Schema::hasColumn('food_recipe_ingredients', $column));
        }

        $this->migration->down();
        $this->migration = null;
        $this->assertFalse(Schema::hasTable('shops'));
        $this->assertFalse(Schema::hasColumn('companies', 'country_id'));
        $this->assertFalse(Schema::hasColumn('product_prep_messages', 'brand_id'));
    }

    private function createBaseTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->string('country')->nullable();
            $table->string('city')->nullable();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('branch_address')->nullable();
            $table->string('branch_city')->nullable();
            $table->string('status')->nullable();
        });
        Schema::create('uoms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->enum('product_type', ['simple', 'parent', 'child', 'service'])->default('simple');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('till_code');
            $table->string('till_name');
        });
        foreach (['sync_nodes', 'sync_local_states'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
            });
        }
        Schema::create('food_recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
        });
        Schema::create('food_recipe_ingredients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('food_recipe_id');
        });
        Schema::create('product_prep_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('message');
        });
        Schema::create('product_prep_message_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('product_id');
            $table->uuid('prep_message_id');
        });
        Schema::create('product_modifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
        });
        Schema::create('product_modifier_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('product_id');
            $table->uuid('modifier_id');
        });
    }
}
