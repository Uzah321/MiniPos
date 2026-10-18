<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncConfigurationTenantScopeTest extends TestCase
{
    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';
    private const BRANCH_ID = '10000000-0000-7000-8000-000000000002';
    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';
    private const OTHER_BRANCH_ID = '20000000-0000-7000-8000-000000000002';

    private bool $databaseReady = false;

    /** @var array<int, string> */
    private array $tables = [
        'product_categories',
        'products',
        'currencies',
        'currency_rates',
        'payment_types',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for sync tenant-scope tests.');
        }

        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->boolean('is_global')->default(false);
            $table->string('name');
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('code');
        });
        Schema::create('currency_rates', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('currency_id');
            $table->decimal('rate', 18, 6);
        });
        Schema::create('payment_types', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id')->index();
            $table->string('branch_id')->nullable()->index();
            $table->string('name');
        });

        $this->databaseReady = true;

        $this->insertFixtureRows();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_initial_snapshot_keeps_current_company_catalogue_and_excludes_cross_tenant_branch_rows(): void
    {
        $datasets = (new SyncConfigurationService(new SyncPayloadHasher))->datasets(
            new SyncNode([
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'deployment_mode' => 'edge',
            ])
        );

        $this->assertSame(
            ['category-current'],
            collect($datasets['product_categories'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['product-global', 'product-current'],
            collect($datasets['products'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['currency-global', 'currency-current'],
            collect($datasets['currencies'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['rate-global', 'rate-current'],
            collect($datasets['currency_rates'])->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['payment-global', 'payment-current'],
            collect($datasets['payment_types'])->pluck('id')->all()
        );

        foreach (['products', 'currencies', 'currency_rates', 'payment_types'] as $table) {
            $ids = collect($datasets[$table])->pluck('id')->all();
            $this->assertNotContains("{$this->prefix($table)}-hybrid", $ids);
            $this->assertNotContains("{$this->prefix($table)}-foreign", $ids);
        }
    }

    private function insertFixtureRows(): void
    {
        DB::table('product_categories')->insert([
            ['id' => 'category-current', 'company_id' => self::COMPANY_ID, 'name' => 'Current'],
            ['id' => 'category-foreign', 'company_id' => self::OTHER_COMPANY_ID, 'name' => 'Foreign'],
        ]);
        DB::table('products')->insert([
            ['id' => 'product-global', 'company_id' => self::COMPANY_ID, 'branch_id' => null, 'is_global' => true, 'name' => 'Global'],
            ['id' => 'product-current', 'company_id' => self::COMPANY_ID, 'branch_id' => self::BRANCH_ID, 'is_global' => false, 'name' => 'Current'],
            ['id' => 'product-hybrid', 'company_id' => self::COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'is_global' => false, 'name' => 'Hybrid'],
            ['id' => 'product-foreign', 'company_id' => self::OTHER_COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'is_global' => true, 'name' => 'Foreign'],
        ]);

        DB::table('currencies')->insert([
            ['id' => 'currency-global', 'company_id' => self::COMPANY_ID, 'branch_id' => null, 'code' => 'USD'],
            ['id' => 'currency-current', 'company_id' => self::COMPANY_ID, 'branch_id' => self::BRANCH_ID, 'code' => 'ZIG'],
            ['id' => 'currency-hybrid', 'company_id' => self::COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'code' => 'HYB'],
            ['id' => 'currency-foreign', 'company_id' => self::OTHER_COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'code' => 'EUR'],
        ]);
        DB::table('currency_rates')->insert([
            ['id' => 'rate-global', 'company_id' => self::COMPANY_ID, 'branch_id' => null, 'currency_id' => 'currency-global', 'rate' => 1],
            ['id' => 'rate-current', 'company_id' => self::COMPANY_ID, 'branch_id' => self::BRANCH_ID, 'currency_id' => 'currency-current', 'rate' => 2],
            ['id' => 'rate-hybrid', 'company_id' => self::COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'currency_id' => 'currency-hybrid', 'rate' => 3],
            ['id' => 'rate-foreign', 'company_id' => self::OTHER_COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'currency_id' => 'currency-foreign', 'rate' => 4],
        ]);
        DB::table('payment_types')->insert([
            ['id' => 'payment-global', 'company_id' => self::COMPANY_ID, 'branch_id' => null, 'name' => 'Global'],
            ['id' => 'payment-current', 'company_id' => self::COMPANY_ID, 'branch_id' => self::BRANCH_ID, 'name' => 'Current'],
            ['id' => 'payment-hybrid', 'company_id' => self::COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'name' => 'Hybrid'],
            ['id' => 'payment-foreign', 'company_id' => self::OTHER_COMPANY_ID, 'branch_id' => self::OTHER_BRANCH_ID, 'name' => 'Foreign'],
        ]);
    }

    private function prefix(string $table): string
    {
        return match ($table) {
            'products' => 'product',
            'currencies' => 'currency',
            'currency_rates' => 'rate',
            'payment_types' => 'payment',
        };
    }
}
