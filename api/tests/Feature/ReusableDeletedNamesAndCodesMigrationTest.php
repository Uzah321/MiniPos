<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReusableDeletedNamesAndCodesMigrationTest extends TestCase
{
    private const TABLES = ['suppliers', 'pos_users', 'products', 'product_barcodes'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for migration tests.');
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('username');
            $table->string('login_code')->nullable();
            $table->string('login_code_lookup')->nullable();
            $table->softDeletes();
            $table->unique(['company_id', 'username'], 'pos_users_company_username_unique');
            $table->unique(['company_id', 'login_code_lookup'], 'pos_users_company_code_lookup_uq');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->softDeletes();
        });
        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('product_id');
            $table->string('barcode');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'barcode']);
        });
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_deleted_names_codes_pins_and_barcodes_become_reusable(): void
    {
        // The data steps run on Central; edges receive users and barcodes by sync.
        config(['salexa_sync.deployment_mode' => 'cloud']);

        DB::table('suppliers')->insert(['id' => 's-old', 'company_id' => 'c', 'name' => 'Acme', 'code' => 'ACM', 'deleted_at' => now()]);
        DB::table('pos_users')->insert(['id' => 'u-old', 'company_id' => 'c', 'username' => 'jo', 'login_code' => 'hash', 'login_code_lookup' => 'pin-1234', 'deleted_at' => now()]);
        DB::table('products')->insert(['id' => 'p-old', 'company_id' => 'c', 'deleted_at' => now()]);
        DB::table('product_barcodes')->insert(['id' => 'b-old', 'company_id' => 'c', 'product_id' => 'p-old', 'barcode' => '600123']);

        $migration = require base_path('database/migrations/2026_10_15_090000_allow_reusing_deleted_names_and_codes.php');
        $migration->up();

        DB::table('suppliers')->insert(['id' => 's-new', 'company_id' => 'c', 'name' => 'Acme', 'code' => 'ACM']);
        $this->assertSame(2, DB::table('suppliers')->where('code', 'ACM')->count());

        $this->assertNull(DB::table('pos_users')->where('id', 'u-old')->value('login_code_lookup'));
        DB::table('pos_users')->insert(['id' => 'u-new', 'company_id' => 'c', 'username' => 'jo', 'login_code' => 'hash', 'login_code_lookup' => 'pin-1234']);
        $this->assertSame(2, DB::table('pos_users')->where('username', 'jo')->count());

        $this->assertNotNull(DB::table('product_barcodes')->where('id', 'b-old')->value('deleted_at'));
        DB::table('product_barcodes')->insert(['id' => 'b-new', 'company_id' => 'c', 'product_id' => 'p-new', 'barcode' => '600123']);
        $this->assertSame(2, DB::table('product_barcodes')->where('barcode', '600123')->count());
    }

    public function test_two_live_users_still_cannot_share_a_pin(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_090000_allow_reusing_deleted_names_and_codes.php');
        $migration->up();

        DB::table('pos_users')->insert(['id' => 'u-1', 'company_id' => 'c', 'username' => 'a', 'login_code_lookup' => 'pin-1']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('pos_users')->insert(['id' => 'u-2', 'company_id' => 'c', 'username' => 'b', 'login_code_lookup' => 'pin-1']);
    }
}
