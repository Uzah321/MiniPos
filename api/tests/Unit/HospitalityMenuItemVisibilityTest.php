<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Models\Product;
use ReflectionMethod;
use Tests\TestCase;

class HospitalityMenuItemVisibilityTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for menu visibility tests.');
        }

        $this->databaseReady = true;
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('food_recipes');
            Schema::dropIfExists('product_prices');
            Schema::dropIfExists('products');
        }

        parent::tearDown();
    }

    public function test_hospitality_register_recovers_only_strictly_identified_legacy_menu_items(): void
    {
        DB::table('products')->insert([
            $this->product('owned-hospitality', false, 'hospitality-a'),
            $this->product('owned-retail', false, 'retail-a'),
            $this->product('legacy-menu', true, null),
            $this->product('legacy-without-recipe', true, null),
            $this->product('legacy-retail-price', true, null),
            $this->product('unrelated-global', true, null),
        ]);

        DB::table('product_prices')->insert([
            $this->price('price-menu', 'legacy-menu', 'hospitality-a'),
            $this->price('price-no-recipe', 'legacy-without-recipe', 'hospitality-a'),
            $this->price('price-retail', 'legacy-retail-price', 'retail-a'),
        ]);

        DB::table('food_recipes')->insert([
            $this->recipe('recipe-menu', 'legacy-menu'),
            $this->recipe('recipe-retail-price', 'legacy-retail-price'),
        ]);

        $ids = $this->applyVisibility(['hospitality-a'], true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame(['legacy-menu', 'owned-hospitality'], $ids);
    }

    public function test_retail_scope_does_not_enable_hospitality_legacy_compatibility(): void
    {
        DB::table('products')->insert([
            $this->product('owned-retail', false, 'retail-a'),
            $this->product('legacy-menu', true, null),
        ]);
        DB::table('product_prices')->insert([
            $this->price('price-retail', 'legacy-menu', 'retail-a'),
        ]);
        DB::table('food_recipes')->insert([
            $this->recipe('recipe-menu', 'legacy-menu'),
        ]);

        $ids = $this->applyVisibility(['retail-a'], false)->pluck('id')->all();

        $this->assertSame(['owned-retail'], $ids);
    }

    private function applyVisibility(array $branchIds, bool $includeLegacy)
    {
        $controller = new ProductController;
        $method = new ReflectionMethod($controller, 'applyBranchOnlyMenuVisibility');
        $method->setAccessible(true);
        $query = Product::query()->where('company_id', 'company-a');

        $method->invoke($controller, $query, $branchIds, $includeLegacy);

        return $query;
    }

    private function product(string $id, bool $isGlobal, ?string $branchId): array
    {
        return [
            'id' => $id,
            'company_id' => 'company-a',
            'branch_id' => $branchId,
            'is_global' => $isGlobal,
            'name' => $id,
            'product_type' => 'finished_good',
            'deleted_at' => null,
        ];
    }

    private function price(string $id, string $productId, string $branchId): array
    {
        return [
            'id' => $id,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'is_active' => true,
            'deleted_at' => null,
        ];
    }

    private function recipe(string $id, string $productId): array
    {
        return [
            'id' => $id,
            'menu_product_id' => $productId,
            'recipe_type' => 'menu',
            'is_active' => true,
            'deleted_at' => null,
        ];
    }

    private function createSchema(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->boolean('is_global')->default(true);
            $table->string('name');
            $table->string('product_type');
            $table->softDeletes();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('product_id');
            $table->string('branch_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('food_recipes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('menu_product_id')->nullable();
            $table->string('recipe_type');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
    }
}
