<?php

namespace Tests\Unit;

use App\Services\Inventory\InventoryAllocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class InventoryAllocationRecipeCostingTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for recipe allocation tests.');
        }

        $this->databaseReady = true;

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_session_id')->nullable();
            $table->uuid('product_id');
            $table->string('movement_type');
            $table->string('stock_area')->nullable();
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
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('stock_movements');
        }

        parent::tearDown();
    }

    public function test_batch_allocation_preserves_exact_recipe_costing_context(): void
    {
        $movement = new ReflectionMethod(InventoryAllocationService::class, 'movement');
        $movement->setAccessible(true);

        $movement->invoke(
            app(InventoryAllocationService::class),
            [
                'company_id' => 'company-a',
                'branch_id' => 'branch-a',
                'product_id' => 'ingredient-a',
                'sale_id' => 'sale-a',
                'receipt_line_id' => 'line-a',
                'unit_cost' => 3.25,
                'currency_code' => 'USD',
                'balance_after' => 7,
                'stock_area' => 'front',
                'reference_type' => 'sale_recipe',
                'movement_notes' => 'Recipe ingredient consumed.',
                'movement_metadata' => [
                    'recipe_id' => 'recipe-a',
                    'recipe_version_no' => 4,
                    'menu_product_id' => 'menu-a',
                ],
                'user_id' => 'user-a',
            ],
            (object) [
                'id' => 'batch-a',
                'warehouse_id' => 'warehouse-a',
                'location_id' => 'location-a',
                'lot_number' => 'LOT-1',
                'base_unit_cost' => 3.25,
                'unit_cost' => 3.25,
                'currency_code' => 'USD',
            ],
            2.0,
            false
        );

        $row = DB::table('stock_movements')->first();
        $metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('sale_recipe', $row->reference_type);
        $this->assertSame('front', $row->stock_area);
        $this->assertSame('USD', $row->currency_code);
        $this->assertSame(2.0, (float) $row->quantity_out);
        $this->assertSame(6.5, (float) $row->total_cost);
        $this->assertSame('line-a', $metadata['receipt_line_id']);
        $this->assertSame('recipe-a', $metadata['recipe_id']);
        $this->assertSame(4, $metadata['recipe_version_no']);
        $this->assertSame('menu-a', $metadata['menu_product_id']);
        $this->assertSame('FEFO', $metadata['allocation_method']);
    }
}
