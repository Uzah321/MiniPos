<?php

namespace Tests\Feature;

use App\Services\Enterprise\EnterpriseHierarchyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Company;
use Modules\GeneralSettings\Http\Controllers\TerminalConfigurationBootstrapController;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductPrice;
use Tests\TestCase;

class TerminalBrandConfigurationBootstrapTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'hospitality_output_configurations', 'printers',
        'menu_item_modifier_exclusions', 'menu_item_modifier_groups',
        'modifier_group_options', 'modifier_groups',
        'product_prep_message_assignments', 'product_prep_messages',
        'food_recipe_ingredients', 'food_recipes',
        'menu_item_trading_schedules', 'menu_item_channels',
        'shop_menu_item_overrides', 'brand_products', 'product_prices', 'products',
        'product_sub_categories', 'product_categories',
        'pos_tills', 'shops', 'complexes', 'brands', 'company_branches',
        'companies', 'countries',
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
        config()->set('servnest.deployment_mode', 'standalone');
        config()->set('servnest.catalogue.default_channel', 'POS');
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_terminal_bootstrap_is_exactly_scoped_and_complete_for_its_brand_and_shop(): void
    {
        $company = Company::query()->findOrFail('company-a');
        $till = PosTill::query()->findOrFail('till-a');
        app()->instance('company', $company);
        app()->instance('till', $till);

        $response = app(TerminalConfigurationBootstrapController::class)->show(
            Request::create('/api/pos/configuration/bootstrap', 'GET', ['channel' => 'POS'])
        );
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];

        $this->assertSame('country-a', data_get($data, 'identity.country.id'));
        $this->assertSame('company-a', data_get($data, 'identity.company.id'));
        $this->assertSame('brand-a', data_get($data, 'identity.brand.id'));
        $this->assertSame('complex-a', data_get($data, 'identity.complex.id'));
        $this->assertSame('shop-a', data_get($data, 'identity.shop.id'));
        $this->assertSame('till-a', data_get($data, 'identity.till.id'));
        $this->assertStringEndsWith('/storage/brands/pizza.png', data_get($data, 'branding.logo'));

        $items = collect(data_get($data, 'menu.items'));
        $this->assertSame(['menu-visible'], $items->pluck('id')->all());
        $this->assertNotContains('menu-hidden', $items->pluck('id')->all());
        $this->assertNotContains('menu-other-brand', $items->pluck('id')->all());
        $this->assertSame(
            ['menu-hidden'],
            data_get($data, 'menu.visibility.excluded_product_ids')
        );

        $item = $items->first();
        $this->assertSame('POS', data_get($item, 'channels.0.channel_code'));
        $this->assertSame('ALL-DAY', data_get($item, 'trading_schedules.0.schedule_code'));
        $this->assertSame('Well done', data_get($item, 'prep_messages.0.message'));
        $this->assertSame('Extras', data_get($item, 'modifier_groups.0.name'));
        $this->assertSame(
            ['Extra cheese'],
            collect(data_get($item, 'modifier_groups.0.options'))->pluck('name')->all(),
            'The excluded modifier option must not be delivered for this menu item.'
        );
        $this->assertSame('Pizza recipe', data_get($item, 'recipes.0.name'));
        $this->assertSame('Flour', data_get($item, 'recipes.0.ingredients.0.ingredient_name'));

        $this->assertSame('kitchen_printer', data_get($data, 'kitchen_output.mode'));
        $this->assertSame('printer-a', data_get($data, 'kitchen_output.printer.id'));
        $this->assertSame('order_submit', data_get($data, 'kitchen_output.dispatch_on'));
    }

    public function test_terminal_bootstrap_fails_closed_for_invalid_brand_and_complex_ownership(): void
    {
        foreach ([
            ['brands', 'brand-a', 'status', 'inactive'],
            ['brands', 'brand-a', 'company_id', 'company-b'],
            ['brands', 'brand-a', 'country_id', 'country-b'],
            ['brands', 'brand-a', 'deleted_at', now()],
            ['complexes', 'complex-a', 'status', 'inactive'],
            ['complexes', 'complex-a', 'company_id', 'company-b'],
            ['complexes', 'complex-a', 'country_id', 'country-b'],
            ['complexes', 'complex-a', 'deleted_at', now()],
        ] as [$table, $id, $column, $invalid]) {
            $original = DB::table($table)->where('id', $id)->value($column);
            DB::table($table)->where('id', $id)->update([$column => $invalid]);

            $response = $this->bootstrapResponse();
            $this->assertSame(409, $response->getStatusCode(), "{$table}.{$column}");
            $this->assertSame('TERMINAL_HIERARCHY_NOT_READY', $response->getData(true)['code']);

            DB::table($table)->where('id', $id)->update([$column => $original]);
        }
    }

    public function test_terminal_bootstrap_requires_till_identity_to_exactly_match_its_shop(): void
    {
        foreach ([
            'company_id' => 'company-b',
            'country_id' => 'country-b',
            'brand_id' => 'brand-b',
            'complex_id' => 'complex-b',
            'branch_id' => 'branch-b',
            'shop_id' => 'shop-missing',
        ] as $column => $invalid) {
            $original = DB::table('pos_tills')->where('id', 'till-a')->value($column);
            DB::table('pos_tills')->where('id', 'till-a')->update([$column => $invalid]);

            $response = $this->bootstrapResponse();
            $expectedStatus = $column === 'company_id' ? 403 : 409;
            $this->assertSame($expectedStatus, $response->getStatusCode(), $column);

            DB::table('pos_tills')->where('id', 'till-a')->update([$column => $original]);
        }
    }

    public function test_a_back_office_menu_item_reaches_only_its_own_shop_at_its_selling_price(): void
    {
        config()->set('salexa_sync.deployment_mode', 'standalone');
        $now = now();
        DB::table('company_branches')->insert([
            'id' => 'branch-b', 'company_id' => 'company-a', 'branch_name' => 'Borrowdale',
            'branch_code' => 'BRW', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('shops')->insert([
            'id' => 'shop-b', 'company_id' => 'company-a', 'country_id' => 'country-a',
            'brand_id' => 'brand-a', 'complex_id' => 'complex-a', 'branch_id' => 'branch-b',
            'code' => 'PI-BRW', 'name' => 'Pizza Inn Borrowdale', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $burger = $this->backOfficeMenuItem('Burger', 'PRD00005', 'branch-a');
        $this->assertSame(['menu-visible'], $this->menuItemIds(), 'An unpriced menu item is not sellable.');
        $this->assertSame('menu_item', DB::table('brand_products')->where('product_id', $burger->id)->value('item_kind'));

        $price = ProductPrice::create([
            'company_id' => 'company-a', 'product_id' => $burger->id, 'branch_id' => 'branch-a',
            'price_level_id' => 'level-a', 'price' => 3, 'currency' => 'USD',
            'is_default' => true, 'is_active' => true,
        ]);
        $this->assertEqualsCanonicalizing(['menu-visible', (string) $burger->id], $this->menuItemIds());
        $this->assertSame(3.0, $this->menuPrice($burger->id));

        $price->update(['price' => 3.5]);
        $this->assertSame(3.5, $this->menuPrice($burger->id));

        $sibling = $this->backOfficeMenuItem('Borrowdale Special', 'PRD00006', 'branch-b');
        ProductPrice::create([
            'company_id' => 'company-a', 'product_id' => $sibling->id, 'branch_id' => 'branch-b',
            'price' => 5, 'currency' => 'USD', 'is_default' => true, 'is_active' => true,
        ]);
        $this->assertNotContains((string) $sibling->id, $this->menuItemIds(), "Another shop's own item must not reach this till.");

        $price->delete();
        $this->assertSame(['menu-visible'], $this->menuItemIds(), 'Removing the price takes the item off the till.');

        $global = Product::create([
            'company_id' => 'company-a', 'is_global' => true, 'name' => 'Shared Item',
            'code' => 'SHARED', 'product_type' => 'finished_good',
        ]);
        ProductPrice::create([
            'company_id' => 'company-a', 'product_id' => $global->id, 'branch_id' => 'branch-a',
            'price' => 1, 'currency' => 'USD', 'is_active' => true,
        ]);
        $this->assertFalse(DB::table('brand_products')->where('product_id', $global->id)->exists());
    }

    public function test_configuration_etag_tracks_all_material_brand_menu_relations(): void
    {
        $service = app(EnterpriseHierarchyService::class);
        $till = PosTill::query()->findOrFail('till-a');
        $previous = $service->configurationManifest($till)['configuration_etag'];
        $sequence = 1;

        foreach ([
            ['products', 'menu-visible'],
            ['product_categories', 'category-a'],
            ['product_sub_categories', 'subcategory-a'],
            ['food_recipe_ingredients', 'ingredient-line'],
            ['modifier_group_options', 'option-cheese'],
            ['menu_item_modifier_groups', 'group-link'],
            ['menu_item_modifier_exclusions', 'exclude-olive'],
            ['product_prep_message_assignments', 'prep-link'],
        ] as [$table, $id]) {
            DB::table($table)->where('id', $id)->update([
                'updated_at' => now()->addSeconds($sequence++),
            ]);
            $current = $service->configurationManifest($till)['configuration_etag'];
            $this->assertNotSame($previous, $current, $table);
            $previous = $current;
        }
    }

    private function createSchema(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('timezone')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('country_id');
            $table->string('company_code');
            $table->string('company_name');
            $table->string('logo')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->boolean('is_main_branch')->default(false);
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id');
            $table->string('code');
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('complexes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id');
            $table->string('code');
            $table->string('name');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id');
            $table->string('brand_id');
            $table->string('complex_id');
            $table->string('branch_id');
            $table->string('code');
            $table->string('name');
            $table->string('timezone')->nullable();
            $table->string('pos_login_mode')->default('code');
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('country_id');
            $table->string('brand_id');
            $table->string('complex_id');
            $table->string('branch_id');
            $table->string('shop_id');
            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();
            $table->boolean('is_registered')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('parent_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_sub_categories', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('category_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->boolean('is_global')->default(true);
            $table->string('branch_id')->nullable();
            $table->string('category_id')->nullable();
            $table->string('sub_category_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('product_type')->default('finished_good');
            $table->boolean('allow_discount')->default(true);
            $table->boolean('taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_id');
            $table->string('product_id');
            $table->string('branch_id')->nullable();
            $table->string('price_level_id')->nullable();
            $table->decimal('price', 18, 4);
            $table->string('currency')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('brand_products', function (Blueprint $table): void {
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
            $table->json('source_metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shop_menu_item_overrides', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('shop_id');
            $table->string('product_id');
            $table->boolean('is_available');
            $table->string('channel_code')->nullable();
            $table->string('channel_key')->default('*');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->timestamps();
        });
        Schema::create('menu_item_channels', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('channel_code');
            $table->decimal('price_exclusive', 18, 4)->nullable();
            $table->decimal('price_inclusive', 18, 4)->nullable();
            $table->string('currency_code');
            $table->boolean('is_available')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('menu_item_trading_schedules', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('schedule_code');
            $table->json('weekdays')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('food_recipes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id')->nullable();
            $table->string('produced_product_id')->nullable();
            $table->string('name');
            $table->string('recipe_type')->default('menu');
            $table->unsignedInteger('version_no')->default(1);
            $table->decimal('yield_quantity', 18, 4)->default(1);
            $table->string('yield_uom_id')->nullable();
            $table->boolean('is_current')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('food_recipe_ingredients', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('food_recipe_id');
            $table->string('child_recipe_id')->nullable();
            $table->string('ingredient_product_id')->nullable();
            $table->string('uom_id')->nullable();
            $table->string('line_type')->default('ingredient');
            $table->unsignedInteger('sequence_no')->default(1);
            $table->decimal('quantity', 18, 4);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('product_prep_messages', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('branch_id')->nullable();
            $table->string('code');
            $table->string('message');
            $table->boolean('allow_free_text')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_prep_message_assignments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('branch_id')->nullable();
            $table->string('product_id');
            $table->string('prep_message_id');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('minimum_selections')->default(0);
            $table->unsignedInteger('maximum_selections')->default(1);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('modifier_group_options', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('modifier_group_id');
            $table->string('modifier_product_id');
            $table->string('external_code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('price_delta', 18, 4)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('menu_item_modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id');
            $table->string('modifier_group_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('minimum_selections')->nullable();
            $table->unsignedInteger('maximum_selections')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('menu_item_modifier_exclusions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id');
            $table->string('modifier_option_id');
            $table->timestamps();
        });
        Schema::create('printers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('till_id')->nullable();
            $table->string('printer_name');
            $table->string('target_printing');
            $table->string('printer_type');
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
            $table->boolean('auto_print')->default(true);
            $table->unsignedInteger('copies')->default(1);
            $table->string('dispatch_on')->default('order_submit');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('configured_by')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        $now = now();
        DB::table('countries')->insert([
            'id' => 'country-a', 'code' => 'ZW', 'name' => 'Zimbabwe',
            'timezone' => 'Africa/Harare', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('companies')->insert([
            'id' => 'company-a', 'country_id' => 'country-a', 'company_code' => 'SIM-ZW',
            'company_name' => 'Simbisa Zimbabwe', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('company_branches')->insert([
            'id' => 'branch-a', 'company_id' => 'company-a', 'branch_name' => 'Avondale',
            'branch_code' => 'AVN', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('brands')->insert([
            [
                'id' => 'brand-a', 'company_id' => 'company-a', 'country_id' => 'country-a',
                'code' => 'PIZZA', 'name' => 'Pizza Inn', 'logo_path' => 'brands/pizza.png',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 'brand-b', 'company_id' => 'company-a', 'country_id' => 'country-a',
                'code' => 'CHICKEN', 'name' => 'Chicken Inn', 'logo_path' => null,
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('complexes')->insert([
            'id' => 'complex-a', 'company_id' => 'company-a', 'country_id' => 'country-a',
            'code' => 'AVON', 'name' => 'Avondale Complex',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('shops')->insert([
            'id' => 'shop-a', 'company_id' => 'company-a', 'country_id' => 'country-a',
            'brand_id' => 'brand-a', 'complex_id' => 'complex-a', 'branch_id' => 'branch-a',
            'code' => 'PI-AVN', 'name' => 'Pizza Inn Avondale', 'timezone' => 'Africa/Harare',
            'pos_login_mode' => 'code', 'configuration_version' => 5, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pos_tills')->insert([
            'id' => 'till-a', 'company_id' => 'company-a', 'country_id' => 'country-a',
            'brand_id' => 'brand-a', 'complex_id' => 'complex-a', 'branch_id' => 'branch-a',
            'shop_id' => 'shop-a', 'till_code' => 'T01', 'till_name' => 'Front Counter',
            'is_registered' => true, 'is_active' => true, 'configuration_version' => 5,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_categories')->insert([
            'id' => 'category-a', 'company_id' => 'company-a', 'name' => 'Pizza',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_sub_categories')->insert([
            'id' => 'subcategory-a', 'company_id' => 'company-a', 'category_id' => 'category-a',
            'name' => 'Classic Pizza', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('products')->insert([
            ['id' => 'menu-visible', 'company_id' => 'company-a', 'category_id' => 'category-a', 'sub_category_id' => 'subcategory-a', 'name' => 'Classic Pizza', 'code' => 'PIZZA1', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'menu-hidden', 'company_id' => 'company-a', 'category_id' => null, 'sub_category_id' => null, 'name' => 'Hidden Pizza', 'code' => 'PIZZA2', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'menu-other-brand', 'company_id' => 'company-a', 'category_id' => null, 'sub_category_id' => null, 'name' => 'Chicken Meal', 'code' => 'CHK1', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ingredient-flour', 'company_id' => 'company-a', 'category_id' => null, 'sub_category_id' => null, 'name' => 'Flour', 'code' => 'FLOUR', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'modifier-cheese', 'company_id' => 'company-a', 'category_id' => null, 'sub_category_id' => null, 'name' => 'Extra cheese', 'code' => 'CHEESE', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'modifier-olive', 'company_id' => 'company-a', 'category_id' => null, 'sub_category_id' => null, 'name' => 'Olives', 'code' => 'OLIVE', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('brand_products')->insert([
            ['id' => 'catalog-visible', 'company_id' => 'company-a', 'brand_id' => 'brand-a', 'product_id' => 'menu-visible', 'item_kind' => 'menu_item', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'catalog-hidden', 'company_id' => 'company-a', 'brand_id' => 'brand-a', 'product_id' => 'menu-hidden', 'item_kind' => 'menu_item', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'catalog-other', 'company_id' => 'company-a', 'brand_id' => 'brand-b', 'product_id' => 'menu-other-brand', 'item_kind' => 'menu_item', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('shop_menu_item_overrides')->insert([
            'id' => 'override-hidden', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'shop_id' => 'shop-a', 'product_id' => 'menu-hidden', 'is_available' => false,
            'channel_key' => '*', 'reason' => 'Not sold here', 'configuration_version' => 5,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([
            ['channel-visible', 'brand-a', 'menu-visible'],
            ['channel-hidden', 'brand-a', 'menu-hidden'],
            ['channel-other', 'brand-b', 'menu-other-brand'],
        ] as [$id, $brand, $product]) {
            DB::table('menu_item_channels')->insert([
                'id' => $id, 'company_id' => 'company-a', 'brand_id' => $brand,
                'product_id' => $product, 'channel_code' => 'POS',
                'price_inclusive' => 10, 'currency_code' => 'USD', 'is_available' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('menu_item_trading_schedules')->insert([
            'id' => 'schedule-visible', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'product_id' => 'menu-visible', 'schedule_code' => 'ALL-DAY',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('food_recipes')->insert([
            'id' => 'recipe-visible', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'menu_product_id' => 'menu-visible', 'name' => 'Pizza recipe', 'is_current' => true,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('food_recipe_ingredients')->insert([
            'id' => 'ingredient-line', 'company_id' => 'company-a',
            'food_recipe_id' => 'recipe-visible', 'ingredient_product_id' => 'ingredient-flour',
            'sequence_no' => 1, 'quantity' => 0.25, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_prep_messages')->insert([
            'id' => 'prep-well', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'code' => 'WELL', 'message' => 'Well done', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_prep_message_assignments')->insert([
            'id' => 'prep-link', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'product_id' => 'menu-visible', 'prep_message_id' => 'prep-well',
            'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('modifier_groups')->insert([
            'id' => 'group-extra', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'code' => 'EXTRA', 'name' => 'Extras', 'maximum_selections' => 2,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('modifier_group_options')->insert([
            ['id' => 'option-cheese', 'company_id' => 'company-a', 'brand_id' => 'brand-a', 'modifier_group_id' => 'group-extra', 'modifier_product_id' => 'modifier-cheese', 'sort_order' => 1, 'price_delta' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'option-olive', 'company_id' => 'company-a', 'brand_id' => 'brand-a', 'modifier_group_id' => 'group-extra', 'modifier_product_id' => 'modifier-olive', 'sort_order' => 2, 'price_delta' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('menu_item_modifier_groups')->insert([
            'id' => 'group-link', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'menu_product_id' => 'menu-visible', 'modifier_group_id' => 'group-extra',
            'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('menu_item_modifier_exclusions')->insert([
            'id' => 'exclude-olive', 'company_id' => 'company-a', 'brand_id' => 'brand-a',
            'menu_product_id' => 'menu-visible', 'modifier_option_id' => 'option-olive',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('printers')->insert([
            'id' => 'printer-a', 'company_id' => 'company-a', 'branch_id' => 'branch-a',
            'till_id' => 'till-a', 'printer_name' => 'Kitchen Pass',
            'target_printing' => 'kitchen', 'printer_type' => 'qz', 'is_default' => true,
            'is_active' => true, 'connection_type' => 'system',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('hospitality_output_configurations')->insert([
            'id' => 'output-a', 'company_id' => 'company-a', 'branch_id' => 'branch-a',
            'till_id' => 'till-a', 'mode' => 'kitchen_printer', 'auto_print' => true,
            'copies' => 1, 'dispatch_on' => 'order_submit', 'version' => 3,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function backOfficeMenuItem(string $name, string $code, string $branchId): Product
    {
        return Product::create([
            'company_id' => 'company-a', 'branch_id' => $branchId, 'is_global' => false,
            'category_id' => 'category-a', 'name' => $name, 'code' => $code,
            'product_type' => 'finished_good', 'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function menuItemIds(): array
    {
        return collect(data_get($this->bootstrapResponse()->getData(true), 'data.menu.items'))
            ->pluck('id')
            ->all();
    }

    private function menuPrice(string $productId): ?float
    {
        $item = collect(data_get($this->bootstrapResponse()->getData(true), 'data.menu.items'))
            ->firstWhere('id', $productId);

        return data_get($item, 'prices.0.price');
    }

    private function bootstrapResponse(): JsonResponse
    {
        app()->instance('company', Company::query()->findOrFail('company-a'));
        app()->instance('till', PosTill::query()->findOrFail('till-a'));

        return app(TerminalConfigurationBootstrapController::class)->show(
            Request::create('/api/pos/configuration/bootstrap', 'GET', ['channel' => 'POS'])
        );
    }
}
