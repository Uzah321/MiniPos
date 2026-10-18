<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityBrandSyncScopeTest extends TestCase
{
    private const COMPANY = '10000000-0000-0000-0000-000000000001';

    private const BRANCH_A = '10000000-0000-0000-0000-000000000002';

    private const BRANCH_B = '10000000-0000-0000-0000-000000000003';

    private const BRAND_A = '10000000-0000-0000-0000-000000000004';

    private const BRAND_B = '10000000-0000-0000-0000-000000000005';

    /** @var list<string> */
    private array $tables = [
        'hospitality_output_configurations', 'printers', 'pos_tills',
        'product_modifier_assignments', 'product_modifiers',
        'product_prep_message_assignments', 'product_prep_messages',
        'shop_menu_item_overrides', 'menu_item_modifier_exclusions',
        'menu_item_modifier_groups', 'menu_item_trading_schedules', 'menu_item_channels',
        'modifier_group_options', 'modifier_groups',
        'food_recipe_ingredients', 'food_recipes', 'brand_products',
        'products', 'suppliers', 'uoms', 'product_categories', 'shops', 'brands',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        $this->createSchema();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_edge_snapshot_contains_only_the_shop_brand_and_its_dependencies(): void
    {
        $datasets = (new SyncConfigurationService(new SyncPayloadHasher))->datasets(new SyncNode([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A,
            'deployment_mode' => 'edge',
        ]));

        $this->assertEqualsCanonicalizing(
            ['menu-a', 'ingredient-a', 'modifier-a', 'branch-a'],
            collect($datasets['products'])->pluck('id')->all()
        );
        $this->assertSame(['category-a'], collect($datasets['product_categories'])->pluck('id')->all());
        $this->assertSame(['supplier-a'], collect($datasets['suppliers'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            ['uom-global', 'uom-a'],
            collect($datasets['uoms'])->pluck('id')->all()
        );
        $this->assertSame(['prep-a'], collect($datasets['product_prep_messages'])->pluck('id')->all());
        $this->assertSame(
            ['prep-assignment-a'],
            collect($datasets['product_prep_message_assignments'])->pluck('id')->all()
        );
        $this->assertNotContains('menu-b', collect($datasets['products'])->pluck('id')->all());
        $this->assertNotContains('unrelated-global', collect($datasets['products'])->pluck('id')->all());
        $this->assertNotContains('prep-b', collect($datasets['product_prep_messages'])->pluck('id')->all());
        $this->assertNotContains('prep-unassigned-a', collect($datasets['product_prep_messages'])->pluck('id')->all());
        $this->assertSame(['channel-a'], collect($datasets['menu_item_channels'])->pluck('id')->all());
        $this->assertSame(['schedule-a'], collect($datasets['menu_item_trading_schedules'])->pluck('id')->all());
        $this->assertSame(['group-link-a'], collect($datasets['menu_item_modifier_groups'])->pluck('id')->all());
        $this->assertSame(['option-exclusion-a'], collect($datasets['menu_item_modifier_exclusions'])->pluck('id')->all());
        $this->assertSame(['shop-override-a'], collect($datasets['shop_menu_item_overrides'])->pluck('id')->all());
        $this->assertSame(['legacy-modifier-a'], collect($datasets['product_modifiers'])->pluck('id')->all());
        $this->assertSame(['legacy-link-a'], collect($datasets['product_modifier_assignments'])->pluck('id')->all());
        $this->assertSame(['printer-a'], collect($datasets['printers'])->pluck('id')->all());
        $this->assertSame(['output-a'], collect($datasets['hospitality_output_configurations'])->pluck('id')->all());
    }

    public function test_shared_menu_product_does_not_leak_a_sibling_brand_recipe_or_ingredients(): void
    {
        DB::table('products')->insert([
            ['id' => 'shared-menu', 'company_id' => self::COMPANY, 'branch_id' => null, 'name' => 'Shared base item'],
            ['id' => 'sibling-secret-ingredient', 'company_id' => self::COMPANY, 'branch_id' => null, 'name' => 'Sibling recipe ingredient'],
        ]);
        DB::table('brand_products')->insert([
            ['id' => 'shared-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'product_id' => 'shared-menu', 'item_kind' => 'menu_item'],
            ['id' => 'shared-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'product_id' => 'shared-menu', 'item_kind' => 'menu_item'],
        ]);
        DB::table('food_recipes')->insert([
            'id' => 'sibling-shared-recipe', 'company_id' => self::COMPANY,
            'brand_id' => self::BRAND_B, 'menu_product_id' => 'shared-menu',
        ]);
        DB::table('food_recipe_ingredients')->insert([
            'id' => 'sibling-secret-line', 'company_id' => self::COMPANY,
            'food_recipe_id' => 'sibling-shared-recipe',
            'ingredient_product_id' => 'sibling-secret-ingredient',
        ]);

        $datasets = (new SyncConfigurationService(new SyncPayloadHasher))->datasets(new SyncNode([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A,
            'deployment_mode' => 'edge',
        ]));

        $this->assertContains('shared-menu', collect($datasets['products'])->pluck('id')->all());
        $this->assertNotContains('sibling-secret-ingredient', collect($datasets['products'])->pluck('id')->all());
        $this->assertNotContains('sibling-shared-recipe', collect($datasets['food_recipes'])->pluck('id')->all());
        $this->assertNotContains('sibling-secret-line', collect($datasets['food_recipe_ingredients'])->pluck('id')->all());
    }

    public function test_snapshot_context_names_the_exact_terminal_hierarchy_and_public_brand_asset(): void
    {
        $node = new SyncNode([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A,
            'shop_id' => 'shop-a',
            'till_id' => 'till-a',
            'deployment_mode' => 'edge',
        ]);
        $context = (new SyncConfigurationService(new SyncPayloadHasher))->terminalContext($node, [
            'countries' => [[
                'id' => 'country-a', 'code' => 'ZW', 'name' => 'Zimbabwe',
                'image_path' => 'countries/zw.png',
            ]],
            'companies' => [[
                'id' => self::COMPANY, 'country_id' => 'country-a',
                'company_code' => 'COMP-A', 'company_name' => 'Simbisa Zimbabwe',
                'logo' => 'companies/simbisa.png',
            ]],
            'brands' => [[
                'id' => self::BRAND_A, 'code' => 'PIZZA', 'name' => 'Pizza Inn',
                'logo_path' => 'brands/pizza-inn.png', 'logo_url' => null,
                'updated_at' => '2026-10-13 12:00:00',
            ], [
                'id' => self::BRAND_B, 'code' => 'CHICKEN', 'name' => 'Chicken Inn',
                'logo_url' => 'https://cdn.example.test/chicken.png',
            ]],
            'complexes' => [[
                'id' => 'complex-a', 'code' => 'AVON', 'name' => 'Avondale Complex',
            ]],
            'company_branches' => [[
                'id' => self::BRANCH_A, 'branch_code' => 'AVN', 'branch_name' => 'Avondale',
            ]],
            'shops' => [[
                'id' => 'shop-a', 'country_id' => 'country-a', 'brand_id' => self::BRAND_A,
                'complex_id' => 'complex-a', 'branch_id' => self::BRANCH_A,
                'code' => 'PI-AVN', 'name' => 'Pizza Inn Avondale',
                'configuration_version' => 9,
            ]],
            'pos_tills' => [[
                'id' => 'till-a', 'shop_id' => 'shop-a', 'till_code' => 'T01',
                'till_name' => 'Front Counter', 'configuration_version' => 11,
            ], [
                'id' => 'till-b', 'shop_id' => 'shop-a', 'till_code' => 'T02',
                'till_name' => 'Drive Through', 'configuration_version' => 4,
            ]],
        ]);

        $this->assertSame('country-a', data_get($context, 'identity.country.id'));
        $this->assertSame(self::COMPANY, data_get($context, 'identity.company.id'));
        $this->assertSame(self::BRAND_A, data_get($context, 'identity.brand.id'));
        $this->assertSame('complex-a', data_get($context, 'identity.complex.id'));
        $this->assertSame('shop-a', data_get($context, 'identity.shop.id'));
        $this->assertSame('till-a', data_get($context, 'identity.till.id'));
        $this->assertSame('Pizza Inn', data_get($context, 'branding.name'));
        $this->assertStringEndsWith(
            '/storage/brands/pizza-inn.png',
            (string) data_get($context, 'branding.brand_logo.url')
        );
        $this->assertNotSame(
            self::BRAND_B,
            data_get($context, 'identity.brand.id'),
            'A paired till must never inherit a sibling brand identity.'
        );
        $this->assertContains(
            'shop_menu_item_overrides',
            data_get($context, 'configuration_contract.catalogue_datasets')
        );
        $this->assertSame(
            ['printers', 'hospitality_output_configurations'],
            data_get($context, 'configuration_contract.kitchen_output_datasets')
        );
    }

    public function test_android_kitchen_routes_are_bound_to_the_exact_till(): void
    {
        DB::table('pos_tills')->insert([
            'id' => 'till-sibling', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A, 'shop_id' => 'shop-a',
        ]);
        DB::table('printers')->insert([
            'id' => 'printer-sibling', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A, 'till_id' => 'till-sibling',
            'printer_name' => 'Sibling Kitchen', 'target_printing' => 'kitchen', 'is_default' => false,
        ]);
        DB::table('printers')->insert([
            'id' => 'printer-default', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A, 'till_id' => null,
            'printer_name' => 'Branch Kitchen', 'target_printing' => 'kitchen', 'is_default' => true,
        ]);
        DB::table('printers')->insert([
            'id' => 'printer-unselected', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A, 'till_id' => null,
            'printer_name' => 'Unselected Kitchen', 'target_printing' => 'kitchen', 'is_default' => false,
        ]);
        DB::table('hospitality_output_configurations')->insert([
            'id' => 'output-sibling', 'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A, 'till_id' => 'till-sibling',
            'mode' => 'kitchen_printer',
        ]);
        $node = new SyncNode([
            'company_id' => self::COMPANY,
            'branch_id' => self::BRANCH_A,
            'shop_id' => 'shop-a',
            'till_id' => 'till-a',
            'deployment_mode' => 'android',
        ]);
        $service = new SyncConfigurationService(new SyncPayloadHasher);

        $printersScope = new ReflectionMethod($service, 'scopeBranchPrinters');
        $printersScope->setAccessible(true);
        $printers = $printersScope->invoke($service, DB::table('printers'), $node)
            ->orderBy('id')->pluck('id')->all();

        $outputScope = new ReflectionMethod($service, 'scopeNodeOutputConfigurations');
        $outputScope->setAccessible(true);
        $outputs = $outputScope->invoke(
            $service,
            DB::table('hospitality_output_configurations'),
            $node
        )->orderBy('id')->pluck('id')->all();

        $this->assertSame(['printer-a', 'printer-default'], $printers);
        $this->assertSame(['output-a'], $outputs);
    }

    public function test_android_projection_keeps_bom_quantities_and_sanitized_printer_routes_only(): void
    {
        $service = new SyncConfigurationService(new SyncPayloadHasher);
        $definitionsMethod = new ReflectionMethod($service, 'datasetDefinitions');
        $definitionsMethod->setAccessible(true);
        $definitions = $definitionsMethod->invoke($service, new SyncNode([
            'deployment_mode' => 'android',
        ]));

        $this->assertContains('quantity', $definitions['food_recipe_ingredients']['columns']);
        $this->assertContains('yield_quantity', $definitions['food_recipes']['columns']);
        foreach (['ingredient_cost', 'wastage_cost', 'packaging_cost', 'labour_cost', 'overhead_cost', 'total_cost', 'cost_per_unit'] as $column) {
            $this->assertNotContains($column, $definitions['food_recipes']['columns']);
        }
        foreach (['unit_cost', 'line_total'] as $column) {
            $this->assertNotContains($column, $definitions['food_recipe_ingredients']['columns']);
        }
        $this->assertContains('printer_name', $definitions['printers']['columns']);
        $this->assertContains('target_printing', $definitions['printers']['columns']);
        foreach (['ip_address', 'port', 'notes'] as $column) {
            $this->assertNotContains($column, $definitions['printers']['columns']);
        }
        $this->assertNotContains('configured_by', $definitions['hospitality_output_configurations']['columns']);
    }

    private function createSchema(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('branch_id');
        });
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('parent_id')->nullable();
            $table->string('name');
        });
        Schema::create('uoms', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->nullable();
            $table->string('name');
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('category_id')->nullable();
            $table->string('supplier_id')->nullable();
            $table->string('parent_product_id')->nullable();
            $table->string('base_uom_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('brand_products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('item_kind');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('food_recipes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id')->nullable();
            $table->string('menu_product_id')->nullable();
            $table->string('produced_product_id')->nullable();
            $table->string('yield_uom_id')->nullable();
        });
        Schema::create('food_recipe_ingredients', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('food_recipe_id');
            $table->string('ingredient_product_id')->nullable();
            $table->string('uom_id')->nullable();
        });
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('name');
        });
        Schema::create('modifier_group_options', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('modifier_group_id');
            $table->string('modifier_product_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('menu_item_channels', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('channel_code');
            $table->boolean('is_available')->default(true);
        });
        Schema::create('menu_item_trading_schedules', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('schedule_code');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('menu_item_modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id');
            $table->string('modifier_group_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('menu_item_modifier_exclusions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id');
            $table->string('modifier_option_id');
        });
        Schema::create('shop_menu_item_overrides', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('shop_id');
            $table->string('product_id');
            $table->boolean('is_available');
        });
        Schema::create('product_prep_messages', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id')->nullable();
            $table->string('branch_id')->nullable();
            $table->string('message');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('product_prep_message_assignments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id')->nullable();
            $table->string('branch_id')->nullable();
            $table->string('product_id');
            $table->string('prep_message_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('product_modifiers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id')->nullable();
            $table->string('branch_id')->nullable();
            $table->string('name');
            $table->string('modifier_product_id')->nullable();
            $table->string('recipe_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('product_modifier_assignments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id')->nullable();
            $table->string('branch_id')->nullable();
            $table->string('product_id');
            $table->string('modifier_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('shop_id')->nullable();
        });
        Schema::create('printers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('till_id')->nullable();
            $table->string('printer_name');
            $table->string('target_printing');
            $table->string('printer_type')->default('qz');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('paper_size')->nullable();
            $table->string('connection_type')->nullable();
            $table->string('ip_address')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('hospitality_output_configurations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('till_id');
            $table->string('mode');
        });
    }

    private function seedFixtures(): void
    {
        DB::table('brands')->insert([
            ['id' => self::BRAND_A, 'company_id' => self::COMPANY],
            ['id' => self::BRAND_B, 'company_id' => self::COMPANY],
        ]);
        DB::table('shops')->insert([
            ['id' => 'shop-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => self::BRANCH_A],
            ['id' => 'shop-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'branch_id' => self::BRANCH_B],
        ]);
        DB::table('product_categories')->insert([
            ['id' => 'category-a', 'company_id' => self::COMPANY, 'name' => 'Pizza'],
            ['id' => 'category-b', 'company_id' => self::COMPANY, 'name' => 'Chicken'],
        ]);
        DB::table('uoms')->insert([
            ['id' => 'uom-global', 'company_id' => null, 'name' => 'Each'],
            ['id' => 'uom-a', 'company_id' => self::COMPANY, 'name' => 'Pizza unit'],
            ['id' => 'uom-b', 'company_id' => self::COMPANY, 'name' => 'Chicken unit'],
        ]);
        DB::table('suppliers')->insert([
            ['id' => 'supplier-a', 'company_id' => self::COMPANY, 'name' => 'Pizza supplier'],
            ['id' => 'supplier-b', 'company_id' => self::COMPANY, 'name' => 'Chicken supplier'],
        ]);
        DB::table('products')->insert([
            ['id' => 'menu-a', 'company_id' => self::COMPANY, 'branch_id' => null, 'category_id' => 'category-a', 'supplier_id' => 'supplier-a', 'base_uom_id' => 'uom-a', 'name' => 'Pizza'],
            ['id' => 'ingredient-a', 'company_id' => self::COMPANY, 'branch_id' => null, 'category_id' => null, 'supplier_id' => null, 'base_uom_id' => 'uom-a', 'name' => 'Flour'],
            ['id' => 'modifier-a', 'company_id' => self::COMPANY, 'branch_id' => null, 'category_id' => null, 'supplier_id' => null, 'base_uom_id' => 'uom-a', 'name' => 'Extra cheese'],
            ['id' => 'branch-a', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_A, 'category_id' => null, 'supplier_id' => null, 'base_uom_id' => 'uom-a', 'name' => 'Local packaging'],
            ['id' => 'menu-b', 'company_id' => self::COMPANY, 'branch_id' => null, 'category_id' => 'category-b', 'supplier_id' => 'supplier-b', 'base_uom_id' => 'uom-b', 'name' => 'Chicken'],
            ['id' => 'unrelated-global', 'company_id' => self::COMPANY, 'branch_id' => null, 'category_id' => null, 'supplier_id' => null, 'base_uom_id' => 'uom-b', 'name' => 'Unrelated'],
        ]);
        DB::table('brand_products')->insert([
            ['id' => 'catalogue-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'product_id' => 'menu-a', 'item_kind' => 'menu_item'],
            ['id' => 'catalogue-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'product_id' => 'menu-b', 'item_kind' => 'menu_item'],
        ]);
        DB::table('food_recipes')->insert([
            [
                'id' => 'recipe-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A,
                'menu_product_id' => 'menu-a', 'yield_uom_id' => 'uom-a',
            ],
            [
                'id' => 'recipe-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B,
                'menu_product_id' => 'menu-b', 'yield_uom_id' => 'uom-b',
            ],
        ]);
        DB::table('food_recipe_ingredients')->insert([
            'id' => 'line-a', 'company_id' => self::COMPANY, 'food_recipe_id' => 'recipe-a',
            'ingredient_product_id' => 'ingredient-a', 'uom_id' => 'uom-a',
        ]);
        DB::table('modifier_groups')->insert([
            ['id' => 'group-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'name' => 'Extras'],
            ['id' => 'group-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'name' => 'Sides'],
        ]);
        DB::table('modifier_group_options')->insert([
            [
                'id' => 'option-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A,
                'modifier_group_id' => 'group-a', 'modifier_product_id' => 'modifier-a',
            ],
            [
                'id' => 'option-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B,
                'modifier_group_id' => 'group-b', 'modifier_product_id' => 'menu-b',
            ],
        ]);
        DB::table('menu_item_channels')->insert([
            ['id' => 'channel-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'product_id' => 'menu-a', 'channel_code' => 'POS'],
            ['id' => 'channel-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'product_id' => 'menu-b', 'channel_code' => 'POS'],
        ]);
        DB::table('menu_item_trading_schedules')->insert([
            ['id' => 'schedule-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'product_id' => 'menu-a', 'schedule_code' => 'ALL'],
            ['id' => 'schedule-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'product_id' => 'menu-b', 'schedule_code' => 'ALL'],
        ]);
        DB::table('menu_item_modifier_groups')->insert([
            ['id' => 'group-link-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'menu_product_id' => 'menu-a', 'modifier_group_id' => 'group-a'],
            ['id' => 'group-link-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'menu_product_id' => 'menu-b', 'modifier_group_id' => 'group-b'],
        ]);
        DB::table('menu_item_modifier_exclusions')->insert([
            ['id' => 'option-exclusion-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'menu_product_id' => 'menu-a', 'modifier_option_id' => 'option-a'],
            ['id' => 'option-exclusion-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'menu_product_id' => 'menu-b', 'modifier_option_id' => 'option-b'],
        ]);
        DB::table('shop_menu_item_overrides')->insert([
            ['id' => 'shop-override-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'shop_id' => 'shop-a', 'product_id' => 'menu-a', 'is_available' => false],
            ['id' => 'shop-override-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'shop_id' => 'shop-b', 'product_id' => 'menu-b', 'is_available' => false],
        ]);
        DB::table('product_prep_messages')->insert([
            ['id' => 'prep-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => null, 'message' => 'Well done'],
            ['id' => 'prep-unassigned-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => null, 'message' => 'Unused'],
            ['id' => 'prep-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'branch_id' => null, 'message' => 'Hot'],
        ]);
        DB::table('product_prep_message_assignments')->insert([
            ['id' => 'prep-assignment-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => null, 'product_id' => 'menu-a', 'prep_message_id' => 'prep-a'],
            ['id' => 'prep-assignment-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'branch_id' => null, 'product_id' => 'menu-b', 'prep_message_id' => 'prep-b'],
        ]);
        DB::table('product_modifiers')->insert([
            ['id' => 'legacy-modifier-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => null, 'name' => 'Cheese', 'modifier_product_id' => 'modifier-a', 'recipe_id' => 'recipe-a'],
            ['id' => 'legacy-modifier-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'branch_id' => null, 'name' => 'Chicken side', 'modifier_product_id' => 'menu-b', 'recipe_id' => 'recipe-b'],
        ]);
        DB::table('product_modifier_assignments')->insert([
            ['id' => 'legacy-link-a', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_A, 'branch_id' => null, 'product_id' => 'menu-a', 'modifier_id' => 'legacy-modifier-a'],
            ['id' => 'legacy-link-b', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND_B, 'branch_id' => null, 'product_id' => 'menu-b', 'modifier_id' => 'legacy-modifier-b'],
        ]);
        DB::table('pos_tills')->insert([
            ['id' => 'till-a', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_A, 'shop_id' => 'shop-a'],
            ['id' => 'till-b', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_B, 'shop_id' => 'shop-b'],
        ]);
        DB::table('printers')->insert([
            ['id' => 'printer-a', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_A, 'till_id' => 'till-a', 'printer_name' => 'Pizza Kitchen', 'target_printing' => 'kitchen', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'printer-b', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_B, 'till_id' => 'till-b', 'printer_name' => 'Chicken Kitchen', 'target_printing' => 'kitchen', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('hospitality_output_configurations')->insert([
            ['id' => 'output-a', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_A, 'till_id' => 'till-a', 'mode' => 'kitchen_printer'],
            ['id' => 'output-b', 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH_B, 'till_id' => 'till-b', 'mode' => 'kitchen_printer'],
        ]);
    }
}
