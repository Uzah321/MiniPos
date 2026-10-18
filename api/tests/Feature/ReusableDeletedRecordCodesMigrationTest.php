<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReusableDeletedRecordCodesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is required for migration tests.');
        }

        Schema::dropIfExists('pos_tills');
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id');
            $table->string('till_code');
            $table->softDeletes();
            $table->unique(['company_id', 'branch_id', 'till_code'], 'pos_tills_company_branch_code_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_tills');
        parent::tearDown();
    }

    public function test_a_deleted_tills_code_can_be_reused_after_the_migration(): void
    {
        DB::table('pos_tills')->insert([
            'id' => 'till-old', 'company_id' => 'company-a', 'branch_id' => 'branch-a',
            'till_code' => 'T01', 'deleted_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_10_14_230000_allow_reusing_codes_of_deleted_records.php');
        $migration->up();

        $this->assertFalse(Schema::hasIndex('pos_tills', 'pos_tills_company_branch_code_unique'));
        $this->assertTrue(Schema::hasIndex('pos_tills', 'pos_tills_company_branch_code_unique_live', 'unique'));

        DB::table('pos_tills')->insert([
            'id' => 'till-new', 'company_id' => 'company-a', 'branch_id' => 'branch-a', 'till_code' => 'T01',
        ]);
        $this->assertSame(2, DB::table('pos_tills')->where('till_code', 'T01')->count());

        $migration->up();
        $this->assertTrue(Schema::hasIndex('pos_tills', 'pos_tills_company_branch_code_unique_live', 'unique'));
    }
}
