<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CashupPeriodKeyWidthContractTest extends TestCase
{
    public function test_migration_covers_the_validated_retail_period_key_without_shrinking_on_rollback(): void
    {
        $migrationPath = dirname(__DIR__, 2)
            .'/Modules/Sales/database/migrations/2026_10_10_120000_expand_pos_cashup_period_key.php';
        $source = file_get_contents($migrationPath);
        $periodKey = implode('|', [
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

        $this->assertGreaterThan(160, strlen($periodKey));
        $this->assertLessThanOrEqual(512, strlen($periodKey));
        $this->assertStringContainsString(
            "string('cashup_period_key', 512)->nullable()->change()",
            $source
        );
        $this->assertStringNotContainsString(
            "string('cashup_period_key', 160)",
            $source
        );
        $this->assertStringContainsString('Safe, data-preserving no-op.', $source);
    }
}
