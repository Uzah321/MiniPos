<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashupPeriodKeyWidthMigrationTest extends TestCase
{
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'sqlite'
            && ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The SQLite PDO driver is required for migration portability tests.');
        }

        Schema::create('pos_cashups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('cashup_period_key', 160)->nullable();
        });
        $this->databaseReady = true;
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            Schema::dropIfExists('pos_cashups');
        }

        parent::tearDown();
    }

    public function test_width_upgrade_preserves_existing_rows_and_accepts_a_retail_period_key(): void
    {
        DB::table('pos_cashups')->insert([
            'id' => '10000000-0000-7000-8000-000000000001',
            'cashup_period_key' => 'legacy-period-key',
        ]);

        $migrationPath = base_path(
            'Modules/Sales/database/migrations/2026_10_10_120000_expand_pos_cashup_period_key.php'
        );
        $migration = require $migrationPath;
        $migration->up();

        $this->assertSame(
            'legacy-period-key',
            DB::table('pos_cashups')
                ->where('id', '10000000-0000-7000-8000-000000000001')
                ->value('cashup_period_key')
        );

        $retailPeriodKey = $this->retailPeriodKey();
        $this->assertGreaterThan(160, strlen($retailPeriodKey));
        $this->assertLessThanOrEqual(512, strlen($retailPeriodKey));

        DB::table('pos_cashups')->insert([
            'id' => '10000000-0000-7000-8000-000000000002',
            'cashup_period_key' => $retailPeriodKey,
        ]);

        $this->assertSame(
            $retailPeriodKey,
            DB::table('pos_cashups')
                ->where('id', '10000000-0000-7000-8000-000000000002')
                ->value('cashup_period_key')
        );

        // The migration must remain safe to re-run after an interrupted
        // deployment and must not rewrite either old or new identities.
        $migration->up();
        $this->assertSame(2, DB::table('pos_cashups')->count());
        $this->assertSame(
            $retailPeriodKey,
            DB::table('pos_cashups')
                ->where('id', '10000000-0000-7000-8000-000000000002')
                ->value('cashup_period_key')
        );

        $source = file_get_contents($migrationPath);
        $this->assertStringContainsString("string('cashup_period_key', 512)", $source);
        $this->assertStringNotContainsString("string('cashup_period_key', 160)", $source);
    }

    private function retailPeriodKey(): string
    {
        return implode('|', [
            'RETAIL',
            '10000000-0000-7000-8000-000000000001',
            '10000000-0000-7000-8000-000000000002',
            '10000000-0000-7000-8000-000000000003',
            '10000000-0000-7000-8000-000000000004',
            '10000000-0000-7000-8000-000000000005',
            'ABCDEFGHIJ',
            '202610100955',
            '202610101610',
        ]);
    }
}
