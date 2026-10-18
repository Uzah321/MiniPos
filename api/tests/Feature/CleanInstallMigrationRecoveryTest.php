<?php

namespace Tests\Feature;

use App\Services\Sales\DiscountEngineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\Discount;
use Tests\TestCase;

class CleanInstallMigrationRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'sqlite'
            && ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for migration recovery tests.');
        }
    }

    public function test_sales_migration_preserves_product_discounts_and_can_resume(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 15, 4)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name']);
        });

        $companyId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $legacyId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        DB::table('discounts')->insert([
            'id' => $legacyId,
            'company_id' => $companyId,
            'name' => 'Legacy fixed',
            'type' => 'fixed',
            'value' => 5,
        ]);

        $migration = require base_path('Modules/Sales/database/migrations/2026_05_18_150421_create_discount_modules_tables.php');
        $migration->up();
        $migration->up(); // Simulate retry after DDL committed but migration row did not.

        $legacy = DB::table('discounts')->where('id', $legacyId)->first();
        $this->assertSame('fixed', $legacy->type);
        $this->assertSame('fixed_amount', $legacy->discount_basis);
        $this->assertSame('LEGACY-'.$legacyId, $legacy->code);
        $this->assertSame(5.0, (float) $legacy->value);
        $this->assertNull($legacy->discount_type_id);

        $typeId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $saleId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        DB::table('discount_types')->insert([
            'id' => $typeId,
            'company_id' => $companyId,
            'code' => 'SALE',
            'name' => 'Sale discount',
        ]);
        DB::table('discounts')->insert([
            'id' => $saleId,
            'company_id' => $companyId,
            'discount_type_id' => $typeId,
            'code' => 'SALE-10',
            'name' => 'Sale 10 percent',
            'discount_basis' => 'percentage',
            'value' => 10,
        ]);

        $sale = Discount::with('discountType')->findOrFail($saleId);
        $this->assertSame('percentage', $sale->type);
        $this->assertSame('Sale discount', $sale->discountType->name);
        $this->assertSame('Sale discount', $sale->toArray()['discount_type_name']);

        $eligible = app(DiscountEngineService::class)->listEligibleDiscounts([
            'company_id' => $companyId,
            'sale_amount' => 100,
        ]);
        $this->assertSame([$saleId], array_column($eligible, 'id'));

        $migration->down();
        $this->assertTrue(Schema::hasTable('discounts'));
        $this->assertSame(2, DB::table('discounts')->count());
    }

    public function test_zimra_counter_foreign_key_is_added_after_fiscal_devices_exist(): void
    {
        Schema::create('companies', fn (Blueprint $table) => $table->uuid('id')->primary());
        Schema::create('company_branches', fn (Blueprint $table) => $table->uuid('id')->primary());

        $counters = require base_path('Modules/ZimraIntegration/database/migrations/2026_05_20_164331_create_zimra_global_counters_table.php');
        $devices = require base_path('Modules/FiscalDevice/database/migrations/2026_05_20_164814_create_fiscal_devices_table.php');

        $counters->up();
        $this->assertTrue(Schema::hasTable('zimra_global_counters'));
        $devices->up();
        $devices->up();

        $deviceKeys = array_filter(
            Schema::getForeignKeys('zimra_global_counters'),
            fn (array $key) => in_array('fiscal_device_id', $key['columns'], true)
                && $key['foreign_table'] === 'fiscal_devices'
        );
        $this->assertCount(1, $deviceKeys);
    }

    public function test_food_manufacturing_indexes_have_portable_names_and_survive_retry(): void
    {
        $migration = require base_path('Modules/FoodManufacturing/database/migrations/2026_07_15_121514_add_sub_category_id_to_products_table.php');
        $migration->up();
        $migration->up();

        foreach ([
            'food_mfg_plans_company_branch_date_idx',
            'food_mfg_plans_company_recipe_idx',
            'food_mfg_plans_company_product_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
            $this->assertTrue(Schema::hasIndex('food_manufacturing_production_plans', $name));
        }
    }
}
