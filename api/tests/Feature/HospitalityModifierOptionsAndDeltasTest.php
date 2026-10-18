<?php

namespace Tests\Feature;

use App\Services\Hospitality\HospitalityModifierOptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Models\Product;
use Modules\Sales\Support\FastFoodOrderSupport;
use Tests\TestCase;

/**
 * Imported (BOM) modifier groups must be offered and accepted at the till,
 * and exclusion/substitution options must reduce the menu item's recipe.
 */
class HospitalityModifierOptionsAndDeltasTest extends TestCase
{
    private const COMPANY = 'company-1';

    private const BRAND = 'brand-pizza';

    private const OTHER_BRAND = 'brand-chicken';

    private const BRANCH = 'branch-1';

    /** @var list<string> */
    private array $tables = [
        'menu_item_modifier_exclusions', 'menu_item_modifier_groups', 'modifier_group_options',
        'modifier_groups', 'food_recipe_ingredients', 'food_recipes', 'products', 'shops',
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

    public function test_menu_item_options_follow_assigned_groups_exclusions_and_brand(): void
    {
        $options = app(HospitalityModifierOptionService::class)
            ->optionsForMenuItem(self::COMPANY, self::BRANCH, 'small-classic');

        self::assertSame(['opt-no-feta-small', 'opt-extra-cheese'], $options->pluck('id')->all());

        $extraCheese = $options->firstWhere('id', 'opt-extra-cheese');
        self::assertSame('modifier_group_option', $extraCheese['source']);
        self::assertSame('Extra Cheese', $extraCheese['name']);
        self::assertSame(0.5, $extraCheese['price']);
        self::assertSame('USD', $extraCheese['currency_code']);
        self::assertSame('All Exclusions', $options->firstWhere('id', 'opt-no-feta-small')['group_name']);
        self::assertSame('mod-extra-cheese', $extraCheese['modifier_product_id']);
    }

    public function test_sale_resolution_rejects_excluded_inactive_and_foreign_brand_options(): void
    {
        $resolved = app(HospitalityModifierOptionService::class)->resolveForMenuItem(
            self::COMPANY,
            self::BRANCH,
            'small-classic',
            ['opt-no-feta-small', 'opt-no-feta-mega', 'opt-inactive', 'opt-other-brand', 'unknown-id']
        );

        self::assertSame(['opt-no-feta-small'], $resolved->keys()->all());
        self::assertTrue(app(HospitalityModifierOptionService::class)
            ->resolveForMenuItem(self::COMPANY, 'branch-without-shop', 'small-classic', ['opt-no-feta-small'])
            ->isEmpty());
    }

    public function test_exclusion_and_substitution_options_produce_recipe_reductions(): void
    {
        $host = new class
        {
            use FastFoodOrderSupport;

            public function adjustments(Product $menu, float $sold, array $modifiers): array
            {
                return $this->fastFoodModifierRecipeAdjustments($menu, $sold, $modifiers);
            }
        };
        $menu = Product::query()->findOrFail('small-classic');

        $adjustments = $host->adjustments($menu, 2, [
            ['id' => 'opt-no-feta-small', 'modifier_product_id' => 'mod-no-feta-small', 'quantity' => 1, 'deduct_recipe_stock' => true],
            ['id' => 'opt-deep-pan', 'modifier_product_id' => 'mod-deep-pan', 'quantity' => 1, 'deduct_recipe_stock' => true],
            // A modifier whose stock deduction is switched off contributes nothing.
            ['id' => 'opt-no-feta-mega', 'modifier_product_id' => 'mod-no-feta-mega', 'quantity' => 1, 'deduct_recipe_stock' => false],
        ]);

        ksort($adjustments);
        self::assertEqualsWithDelta(-0.014, $adjustments['ing-feta'], 0.000001);
        self::assertEqualsWithDelta(-2.0, $adjustments['ing-standard-dough'], 0.000001);
        // Positive substitution lines are consumed by the modifier itself, not netted.
        self::assertArrayNotHasKey('ing-deep-pan-dough', $adjustments);
        self::assertCount(2, $adjustments);
    }

    private function createSchema(): void
    {
        Schema::create('shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('branch_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('product_type')->default('finished_good');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('food_recipes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('menu_product_id')->nullable();
            $table->string('name');
            $table->string('recipe_type')->default('menu');
            $table->unsignedInteger('version_no')->default(1);
            $table->decimal('yield_quantity', 18, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('food_recipe_ingredients', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('food_recipe_id');
            $table->string('ingredient_product_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
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
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('menu_item_modifier_groups', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('menu_product_id');
            $table->string('modifier_group_id');
            $table->unsignedInteger('sort_order')->default(0);
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
    }

    private function seedFixtures(): void
    {
        $now = now();
        DB::table('shops')->insert([
            'id' => 'shop-1', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND,
            'branch_id' => self::BRANCH, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $products = [
            ['small-classic', 'Small Classic', 'finished_good', true],
            ['mod-no-feta-small', 'No Feta Cheese - Small', 'finished_good', true],
            ['mod-no-feta-mega', 'No Feta Cheese - Mega', 'finished_good', true],
            ['mod-extra-cheese', 'Extra Cheese', 'finished_good', true],
            ['mod-deep-pan', 'Deep Pan Medium Dough', 'finished_good', true],
            ['mod-inactive', 'Retired Option', 'finished_good', false],
            ['mod-chicken', 'No Mayo', 'finished_good', true],
            ['ing-feta', 'Feta Cheese - KG', 'raw_material', true],
            ['ing-standard-dough', 'Prep Medium Dough - EA', 'raw_material', true],
            ['ing-deep-pan-dough', 'Prep Medium Deep Pan Dough - EA', 'raw_material', true],
        ];
        foreach ($products as [$id, $name, $type, $active]) {
            DB::table('products')->insert([
                'id' => $id, 'company_id' => self::COMPANY, 'name' => $name, 'code' => strtoupper($id),
                'product_type' => $type, 'is_active' => $active, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([
            ['grp-exclusions', self::BRAND, '1', 'All Exclusions'],
            ['grp-extras', self::BRAND, '6', 'Extras'],
            ['grp-chicken', self::OTHER_BRAND, '1', 'All Exclusions'],
        ] as [$id, $brand, $code, $name]) {
            DB::table('modifier_groups')->insert([
                'id' => $id, 'company_id' => self::COMPANY, 'brand_id' => $brand, 'code' => $code,
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([
            ['opt-no-feta-small', 'grp-exclusions', 'mod-no-feta-small', 1, 0, true, self::BRAND],
            ['opt-no-feta-mega', 'grp-exclusions', 'mod-no-feta-mega', 2, 0, true, self::BRAND],
            ['opt-inactive', 'grp-exclusions', 'mod-inactive', 3, 0, true, self::BRAND],
            ['opt-extra-cheese', 'grp-extras', 'mod-extra-cheese', 1, 0.5, true, self::BRAND],
            ['opt-other-brand', 'grp-chicken', 'mod-chicken', 1, 0, true, self::OTHER_BRAND],
        ] as [$id, $group, $product, $sort, $price, $active, $brand]) {
            DB::table('modifier_group_options')->insert([
                'id' => $id, 'company_id' => self::COMPANY, 'brand_id' => $brand,
                'modifier_group_id' => $group, 'modifier_product_id' => $product,
                'external_code' => strtoupper($id), 'sort_order' => $sort, 'price_delta' => $price,
                'is_active' => $active, 'metadata' => json_encode(['currency' => 'USD']),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([
            ['rel-1', self::BRAND, 'grp-exclusions', 1],
            ['rel-2', self::BRAND, 'grp-extras', 2],
            // A foreign-brand relation must never leak into this shop.
            ['rel-3', self::OTHER_BRAND, 'grp-chicken', 3],
        ] as [$id, $brand, $group, $sort]) {
            DB::table('menu_item_modifier_groups')->insert([
                'id' => $id, 'company_id' => self::COMPANY, 'brand_id' => $brand,
                'menu_product_id' => 'small-classic', 'modifier_group_id' => $group,
                'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('menu_item_modifier_exclusions')->insert([
            'id' => 'exc-1', 'company_id' => self::COMPANY, 'brand_id' => self::BRAND,
            'menu_product_id' => 'small-classic', 'modifier_option_id' => 'opt-no-feta-mega',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([
            ['rcp-no-feta-small', 'mod-no-feta-small', [['ing-feta', -0.007]]],
            ['rcp-no-feta-mega', 'mod-no-feta-mega', [['ing-feta', -0.1]]],
            ['rcp-deep-pan', 'mod-deep-pan', [['ing-deep-pan-dough', 1], ['ing-standard-dough', -1]]],
        ] as [$recipeId, $menuProductId, $lines]) {
            DB::table('food_recipes')->insert([
                'id' => $recipeId, 'company_id' => self::COMPANY, 'menu_product_id' => $menuProductId,
                'name' => $recipeId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($lines as $index => [$ingredient, $quantity]) {
                DB::table('food_recipe_ingredients')->insert([
                    'id' => $recipeId.'-'.$index, 'company_id' => self::COMPANY,
                    'food_recipe_id' => $recipeId, 'ingredient_product_id' => $ingredient,
                    'quantity' => $quantity, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
