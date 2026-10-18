<?php

namespace Tests\Unit;

use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Product\Http\Controllers\ProductImportController;
use ReflectionMethod;
use Tests\TestCase;

class ProductImportInitialStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
        });
        Schema::create('uoms', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('category_id');
            $table->string('base_uom_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            $table->string('product_type');
            $table->string('stock_usage_type')->nullable();
            $table->string('default_issue_destination')->nullable();
            $table->string('branch_id')->nullable();
            $table->boolean('is_global')->default(false);
            $table->boolean('stock_tracking_enabled')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_costs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('product_id');
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('last_cost', 18, 4)->default(0);
            $table->decimal('total_quantity', 18, 4)->default(0);
            $table->decimal('total_value', 18, 4)->default(0);
            $table->string('currency')->nullable();
            $table->string('currency_code')->nullable();
            $table->timestamps();
        });
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('product_id');
            $table->string('stock_area');
            $table->decimal('quantity_on_hand', 18, 4);
            $table->decimal('reserved_quantity', 18, 4);
            $table->decimal('available_quantity', 18, 4);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'product_id', 'stock_area']);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('product_id');
            $table->string('stock_area');
            $table->string('movement_type');
            $table->decimal('quantity_in', 18, 4);
            $table->decimal('quantity_out', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->string('currency')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('reference_type');
            $table->string('reference_id');
            $table->text('notes')->nullable();
            $table->text('metadata')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('product_categories')->insert([
            'id' => 'category-1', 'company_id' => 'company-1', 'name' => 'Beverages',
        ]);
        DB::table('uoms')->insert([
            'id' => 'uom-each', 'company_id' => null, 'name' => 'Each', 'code' => 'EACH',
        ]);
    }

    public function test_retail_opening_import_goes_to_front_and_cannot_be_reimported(): void
    {
        $this->assertInitialImport('retail', 'front', 'simple', true);
    }

    public function test_hospitality_opening_import_goes_to_back_and_cannot_be_reimported(): void
    {
        $this->assertInitialImport('hospitality', 'back', 'raw_material', false);
    }

    private function assertInitialImport(string $mode, string $area, string $type, bool $global): void
    {
        $variance = Mockery::mock(InventoryVarianceAgentService::class);
        $variance->shouldReceive('resetProduct')->once()->withArgs(
            fn ($company, $branch, $product, $reason, $source, $sourceId, $actor, $stockArea) =>
                $company === 'company-1' && $branch === 'branch-1' && $product !== ''
                && $reason === 'opening_import' && $source === 'product_import'
                && $sourceId === 'batch-1:2' && $actor === 'user-1' && $stockArea === $area
        );
        $this->app->instance(InventoryVarianceAgentService::class, $variance);

        $row = [
            'name' => 'Test Beverage',
            'category_name' => 'Beverages',
            'code' => 'BEV-001',
            'uom_name' => 'EACH',
            'product_type' => $type,
            'hs_code' => '01234567',
            'opening_quantity' => '12',
            'average_cost' => '2.50',
        ];
        $controller = new ProductImportController;
        $method = new ReflectionMethod($controller, 'importProductRow');
        $arguments = [$row, 2, 'company-1', 'branch-1', 'user-1', false, false, false, 'USD', 'upsert', $mode, 'batch-1'];
        $result = $method->invokeArgs($controller, $arguments);

        $this->assertSame('created', $result['status']);
        $product = DB::table('products')->where('id', $result['product_id'])->first();
        $this->assertSame($type, $product->product_type);
        $this->assertSame('01234567', $product->hs_code);
        $this->assertSame($global, (bool) $product->is_global);
        $this->assertSame($global ? null : 'branch-1', $product->branch_id);
        $this->assertSame('uom-each', $product->base_uom_id);

        $stock = DB::table('product_stocks')->where('product_id', $product->id)->first();
        $this->assertSame($area, $stock->stock_area);
        $this->assertEquals(12, (float) $stock->quantity_on_hand);
        $movement = DB::table('stock_movements')->where('product_id', $product->id)->first();
        $this->assertSame($area, $movement->stock_area);
        $this->assertEquals(12, (float) $movement->quantity_in);

        try {
            $method->invokeArgs($controller, $arguments);
            $this->fail('A repeated opening import must not overwrite stock.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('initial branch stock only', $exception->getMessage());
        }
        $this->assertEquals(12, (float) DB::table('product_stocks')->where('product_id', $product->id)->value('quantity_on_hand'));
        $this->assertSame(1, DB::table('stock_movements')->where('product_id', $product->id)->count());
    }

    public function test_import_updates_hs_code_only_when_supplied(): void
    {
        $controller = new ProductImportController;
        $method = new ReflectionMethod($controller, 'importProductRow');
        $row = [
            'name' => 'Test Beverage',
            'category_name' => 'Beverages',
            'code' => 'BEV-001',
            'uom_name' => 'EACH',
            'hs_code' => '01234567',
        ];
        $arguments = [$row, 2, 'company-1', 'branch-1', 'user-1', false, false, false, 'USD', 'upsert', 'retail', 'batch-1'];
        $productId = $method->invokeArgs($controller, $arguments)['product_id'];

        $arguments[0]['hs_code'] = '';
        $method->invokeArgs($controller, $arguments);
        $this->assertSame('01234567', DB::table('products')->where('id', $productId)->value('hs_code'));

        $arguments[0]['hs_code'] = '1234';
        $method->invokeArgs($controller, $arguments);
        $this->assertSame('1234', DB::table('products')->where('id', $productId)->value('hs_code'));
    }
}
