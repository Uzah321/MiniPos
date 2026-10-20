<?php

namespace Tests\Unit;

use App\Services\Enterprise\BrandScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Each brand sees only its own recipes, products, menus and categories in the
 * Back Office; brand-less rows stay shared. The brand comes from the shop.
 */
class BrandScopeServiceTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for brand scope tests.');
        }
        $this->databaseReady = true;

        Schema::create('shops', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('branch_id');
            $table->string('brand_id')->nullable();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
            $table->string('product_type')->nullable();
        });
        Schema::create('brand_products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('brand_id');
            $table->string('product_id');
            $table->string('item_kind');
            $table->text('source_metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('food_recipes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('brand_id')->nullable();
        });

        DB::table('shops')->insert([
            ['id' => 'shop-chicken', 'branch_id' => 'branch-chicken', 'brand_id' => 'brand-chicken', 'deleted_at' => null],
            ['id' => 'shop-pizza', 'branch_id' => 'branch-pizza', 'brand_id' => 'brand-pizza', 'deleted_at' => null],
        ]);
        foreach (['wings' => 'brand-chicken', 'flour' => 'brand-pizza', 'margherita' => 'brand-pizza', 'napkins' => null] as $id => $brand) {
            DB::table('products')->insert(['id' => $id, 'company_id' => 'company-a', 'name' => $id, 'product_type' => 'raw_material']);
            if ($brand) {
                DB::table('brand_products')->insert([
                    'id' => "link-{$id}", 'company_id' => 'company-a', 'brand_id' => $brand,
                    'product_id' => $id, 'item_kind' => 'raw_material',
                ]);
            }
        }
        DB::table('food_recipes')->insert([
            ['id' => 'recipe-wings', 'brand_id' => 'brand-chicken'],
            ['id' => 'recipe-margherita', 'brand_id' => 'brand-pizza'],
            ['id' => 'recipe-shared', 'brand_id' => null],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            foreach (['food_recipes', 'brand_products', 'products', 'shops'] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_the_brand_comes_from_the_shop_on_the_branch(): void
    {
        $scope = app(BrandScopeService::class);

        $this->assertSame(['brand-chicken'], $scope->brandIdsForBranches(['branch-chicken']));
        $this->assertSame([], $scope->brandIdsForBranches(['branch-without-shop']));
        $this->assertSame('brand-pizza', $scope->primaryBrandId(['brand-pizza']));
        $this->assertNull($scope->primaryBrandId(['brand-pizza', 'brand-chicken']));
    }

    public function test_a_brand_sees_its_own_products_and_shared_ones_only(): void
    {
        $scope = app(BrandScopeService::class);

        $chicken = $scope->constrainProducts(DB::table('products'), ['brand-chicken'])->orderBy('id')->pluck('id')->all();
        $pizza = $scope->constrainProducts(DB::table('products'), ['brand-pizza'])->orderBy('id')->pluck('id')->all();

        $this->assertSame(['napkins', 'wings'], $chicken);
        $this->assertSame(['flour', 'margherita', 'napkins'], $pizza);
        $this->assertCount(4, $scope->constrainProducts(DB::table('products'), [])->get(), 'No brand context leaves the list unchanged.');
    }

    public function test_a_brand_sees_its_own_recipes_and_shared_ones_only(): void
    {
        $scope = app(BrandScopeService::class);

        $this->assertSame(
            ['recipe-shared', 'recipe-wings'],
            $scope->constrainBrandColumn(DB::table('food_recipes'), ['brand-chicken'])->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_a_new_back_office_product_is_filed_under_one_brand(): void
    {
        $scope = app(BrandScopeService::class);
        DB::table('products')->insert(['id' => 'chips', 'company_id' => 'company-a', 'name' => 'Chips', 'product_type' => 'finished_good']);

        $scope->linkProductToBrand((object) ['id' => 'chips', 'company_id' => 'company-a', 'product_type' => 'finished_good'], 'brand-chicken');
        $scope->linkProductToBrand((object) ['id' => 'chips', 'company_id' => 'company-a', 'product_type' => 'finished_good'], 'brand-pizza');

        $links = DB::table('brand_products')->where('product_id', 'chips')->get();
        $this->assertCount(1, $links, 'A product already in a catalogue is not added to a second brand.');
        $this->assertSame('brand-chicken', $links->first()->brand_id);
        $this->assertSame('menu_item', $links->first()->item_kind);
        $this->assertNotContains('chips', $scope->constrainProducts(DB::table('products'), ['brand-pizza'])->pluck('id')->all());
    }
}
