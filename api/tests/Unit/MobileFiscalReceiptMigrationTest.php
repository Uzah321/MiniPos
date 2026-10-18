<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileFiscalReceiptMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for migration recovery tests.');
        }
    }

    protected function tearDown(): void
    {
        foreach (['mobile_fiscal_file_confirmations', 'mobile_fiscal_receipts', 'mobile_fiscal_devices'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_replaying_interrupted_migration_restores_short_index_without_losing_rows(): void
    {
        $migration = require base_path('database/migrations/2026_10_13_120000_create_mobile_fiscal_receipt_tables.php');
        $migration->up();

        $index = 'mobile_fiscal_file_co_br_day_idx';
        $this->assertLessThanOrEqual(64, strlen($index));
        $this->assertTrue(Schema::hasIndex('mobile_fiscal_file_confirmations', $index));

        $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        DB::table('mobile_fiscal_file_confirmations')->insert([
            'id' => $id,
            'company_id' => '11111111-2222-3333-4444-555555555555',
            'branch_id' => '22222222-3333-4444-5555-666666666666',
            'till_id' => '33333333-4444-5555-6666-777777777777',
            'sync_node_id' => '44444444-5555-6666-7777-888888888888',
            'device_id' => 123456,
            'fiscal_day_no' => 1,
            'file_sequence' => 1,
            'file_sha256' => str_repeat('a', 64),
            'operation_id' => 'operation-1',
            'receipt_hashes' => '[]',
            'get_file_status' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('mobile_fiscal_file_confirmations', function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
            $table->dropUnique('mobile_fiscal_file_device_day_sequence_unique');
        });
        $this->assertFalse(Schema::hasIndex('mobile_fiscal_file_confirmations', $index));

        $migration->up();

        $this->assertTrue(Schema::hasIndex('mobile_fiscal_file_confirmations', $index));
        $this->assertTrue(Schema::hasIndex('mobile_fiscal_file_confirmations', 'mobile_fiscal_file_device_day_sequence_unique'));
        $this->assertSame(1, DB::table('mobile_fiscal_file_confirmations')->where('id', $id)->count());
    }
}
