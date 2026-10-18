<?php

namespace Tests\Feature;

use App\Services\Sync\SyncConfigurationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;
use Throwable;

class EnterpriseHierarchyOwnershipMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useAvailableDatabaseDriver();
        $this->createLegacyTables();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['pos_tills', 'shops', 'complexes', 'brands', 'companies', 'countries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_it_makes_complexes_company_owned_deduplicates_codes_and_backfills_till_scope(): void
    {
        $now = now();
        DB::table('countries')->insert(['id' => 'country-1']);
        DB::table('companies')->insert(['id' => 'company-1', 'country_id' => 'country-1']);
        DB::table('brands')->insert([
            ['id' => 'brand-1', 'company_id' => 'company-1', 'country_id' => 'country-1'],
            ['id' => 'brand-2', 'company_id' => 'company-1', 'country_id' => 'country-1'],
        ]);
        DB::table('complexes')->insert([
            [
                'id' => 'complex-1', 'company_id' => 'company-1', 'country_id' => 'country-1',
                'brand_id' => 'brand-1', 'code' => 'MALL', 'created_at' => $now->copy()->subMinute(),
            ],
            [
                'id' => 'complex-2', 'company_id' => 'company-1', 'country_id' => 'country-1',
                'brand_id' => 'brand-2', 'code' => 'mall', 'created_at' => $now,
            ],
        ]);
        DB::table('shops')->insert([
            'id' => 'shop-1', 'company_id' => 'company-1', 'country_id' => 'country-1',
            'brand_id' => 'brand-1', 'complex_id' => 'complex-1',
        ]);
        DB::table('pos_tills')->insert([
            'id' => 'till-1', 'company_id' => 'company-1', 'shop_id' => 'shop-1',
        ]);

        $migration = require base_path('Modules/Company/database/migrations/2026_10_13_200000_correct_enterprise_hierarchy_ownership.php');
        $migration->up();

        $this->assertNull(DB::table('complexes')->where('id', 'complex-1')->value('brand_id'));
        $this->assertNull(DB::table('complexes')->where('id', 'complex-2')->value('brand_id'));
        $this->assertSame('MALL', DB::table('complexes')->where('id', 'complex-1')->value('code'));
        $this->assertSame('MALL-2', DB::table('complexes')->where('id', 'complex-2')->value('code'));
        $this->assertTrue(Schema::hasIndex('complexes', 'complexes_company_code_uq'));
        $this->assertDatabaseHas('pos_tills', [
            'id' => 'till-1',
            'country_id' => 'country-1',
            'brand_id' => 'brand-1',
            'complex_id' => 'complex-1',
        ]);

        try {
            DB::table('complexes')->insert([
                'id' => 'complex-3', 'company_id' => 'company-1', 'country_id' => 'country-1',
                'brand_id' => null, 'code' => 'MALL', 'created_at' => now(),
            ]);
            $this->fail('The company and complex code unique constraint was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_it_refuses_a_lossy_partial_rollback(): void
    {
        $migration = require base_path('Modules/Company/database/migrations/2026_10_13_200000_correct_enterprise_hierarchy_ownership.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('irreversible');
        $migration->down();
    }

    public function test_sync_contract_treats_complex_as_company_owned_and_till_scope_as_explicit(): void
    {
        $reflection = new \ReflectionClass(SyncConfigurationService::class);
        $datasets = $reflection->getConstant('DATASETS');
        $foreign = $reflection->getConstant('FOREIGN_DATASETS');

        $this->assertNotContains('brand_id', $datasets['complexes']['columns']);
        $this->assertArrayNotHasKey('brand_id', $foreign['complexes']);
        foreach (['country_id', 'brand_id', 'complex_id', 'shop_id'] as $column) {
            $this->assertContains($column, $datasets['pos_tills']['columns']);
            $this->assertArrayHasKey($column, $foreign['pos_tills']);
        }
    }

    private function createLegacyTables(): void
    {
        Schema::create('countries', fn (Blueprint $table) => $table->uuid('id')->primary());
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('country_id');
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
        });
        Schema::create('complexes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
            $table->uuid('brand_id');
            $table->string('code', 80);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->unique(['brand_id', 'code'], 'complexes_brand_code_uq');
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
            $table->uuid('brand_id');
            $table->uuid('complex_id');
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shop_id')->nullable();
        });
    }

    private function useAvailableDatabaseDriver(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return;
        }

        $mysql = config('database.connections.mysql');
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $mysql['host'], $mysql['port']),
                $mysql['username'],
                $mysql['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `servenest_hierarchy_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            $this->markTestSkipped('SQLite is unavailable and an isolated MySQL test database could not be created: '.$exception->getMessage());
        }

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.database', 'servenest_hierarchy_test');
        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}
