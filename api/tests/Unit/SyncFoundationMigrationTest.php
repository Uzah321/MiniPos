<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncFoundationMigrationTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for sync migration portability tests.');
        }

        $this->databaseReady = true;
        $this->createBaseTables();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            foreach ([
                'sync_replay_nonces',
                'sync_node_cursors',
                'sync_changes',
                'sync_inbox_receipts',
                'sync_outbox_events',
                'sync_local_states',
                'sync_nodes',
                'pos_tills',
                'company_branches',
                'companies',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_sync_migration_is_portable_and_backfills_company_codes(): void
    {
        DB::table('companies')->insert([
            'id' => '11111111-2222-3333-4444-555555555555',
            'company_name' => 'Existing Company',
            'domain' => 'existing.local',
        ]);

        $migration = require base_path('database/migrations/2026_10_05_130000_create_salexa_sync_foundation.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('companies', 'company_code'));
        $this->assertSame(
            'CMP-111111112222',
            DB::table('companies')->where('company_name', 'Existing Company')->value('company_code')
        );

        DB::table('companies')->insert([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'company_code' => 'CMP-AAAAAAAAAAAA',
            'company_name' => 'Offline Company',
            'domain' => null,
        ]);

        foreach ([
            'sync_nodes',
            'sync_local_states',
            'sync_outbox_events',
            'sync_inbox_receipts',
            'sync_changes',
            'sync_node_cursors',
            'sync_replay_nonces',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
        }

        DB::table('sync_changes')->insert([
            $this->changeRow('aaaaaaaa-1111-1111-1111-111111111111'),
            $this->changeRow('aaaaaaaa-2222-2222-2222-222222222222'),
        ]);

        $this->assertSame([1, 2], DB::table('sync_changes')->orderBy('id')->pluck('id')->all());

        $migration->down();

        $this->assertFalse(Schema::hasTable('sync_nodes'));
        $this->assertFalse(Schema::hasColumn('companies', 'company_code'));
        $this->assertSame(1, DB::table('companies')->whereNull('domain')->count());
    }

    private function changeRow(string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'company_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'branch_id' => null,
            'source_node_id' => null,
            'resource_type' => 'company',
            'resource_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'operation' => 'updated',
            'schema_version' => 1,
            'payload' => json_encode(['status' => 'active'], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', '{"status":"active"}'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createBaseTables(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->string('domain')->unique();
        });

        Schema::create('company_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
        });

        Schema::create('pos_tills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
        });
    }
}
