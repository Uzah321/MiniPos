<?php

namespace Tests\Unit;

use App\Services\Sync\SyncSaleImportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SyncCashupSemanticReconciliationContractTest extends TestCase
{
    public function test_equivalent_immutable_cashup_values_ignore_storage_format_but_not_scope_or_totals(): void
    {
        $service = new SyncSaleImportService;
        $mismatches = new ReflectionMethod($service, 'cashupImmutableMismatches');
        $existing = (object) [
            'till_id' => 'till-1',
            'shift_session_id' => 'shift-1',
            'cashier_id' => 'cashier-1',
            'business_mode' => 'FAST_FOOD_BOH',
            'trading_date' => '2026-10-07 00:00:00',
            'counted_cash' => '20.0000',
            'counted_total' => '40.0000',
            'transaction_count' => 2,
            'cashup_currency_code' => 'usd',
            'payment_summary' => json_encode([
                ['currency' => 'USD', 'amount' => 20],
                ['currency' => 'ZWG', 'amount' => 20],
            ]),
            'cashup_closed_at' => '2026-10-07 17:00:00',
        ];
        $incoming = [
            'till_id' => 'till-1',
            'shift_session_id' => 'shift-1',
            'cashier_id' => 'cashier-1',
            'business_mode' => 'fast_food_boh',
            'trading_date' => '2026-10-07',
            'counted_cash' => 20,
            'counted_total' => 40.0,
            'transaction_count' => '2',
            'cashup_currency_code' => 'USD',
            'payment_summary' => [
                ['amount' => 20, 'currency' => 'ZWG'],
                ['amount' => 20, 'currency' => 'USD'],
            ],
            'cashup_closed_at' => '2026-10-07 17:00:00',
        ];

        $this->assertSame([], $mismatches->invoke($service, $existing, $incoming));

        $incoming['counted_total'] = 39.99;
        $incoming['till_id'] = 'till-2';
        $this->assertSame(
            ['till_id', 'counted_total'],
            $mismatches->invoke($service, $existing, $incoming)
        );

        $incoming['till_id'] = 'till-1';
        $incoming['counted_total'] = 40;
        unset($incoming['payment_summary']);
        $this->assertSame(
            ['payment_summary'],
            $mismatches->invoke($service, $existing, $incoming),
            'A missing immutable value cannot be treated as proof of equivalence.'
        );
    }

    public function test_denomination_signature_is_uuid_independent_but_total_and_terminal_sensitive(): void
    {
        $service = new SyncSaleImportService;
        $signature = new ReflectionMethod($service, 'cashupDenominationSignature');
        $base = [
            'id' => 'edge-line-id',
            'cashup_id' => 'edge-cashup-id',
            'till_id' => 'till-1',
            'currency_code' => 'usd',
            'denomination_value' => 10,
            'quantity' => 2,
            'line_total' => 20,
            'denomination_type' => 'NOTE',
            'sort_order' => 1,
        ];
        $cloud = [
            ...$base,
            'id' => 'cloud-line-id',
            'cashup_id' => 'cloud-cashup-id',
            'currency_code' => 'USD',
            'denomination_value' => '10.0000',
            'line_total' => '20.0000',
            'denomination_type' => 'note',
        ];

        $this->assertSame(
            $signature->invoke($service, $base),
            $signature->invoke($service, $cloud)
        );

        $cloud['line_total'] = 19;
        $this->assertNotSame(
            $signature->invoke($service, $base),
            $signature->invoke($service, $cloud)
        );
    }

    public function test_importer_uses_existing_shift_cashup_as_canonical_only_after_semantic_proof(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Sync/SyncSaleImportService.php');

        $this->assertStringContainsString('assertEquivalentNaturalCashup(', $source);
        $this->assertStringContainsString("'cashup_id' => (string) \$natural->id", $source);
        $this->assertStringContainsString("'source_cashup_id' => \$cashupId", $source);
        $this->assertStringContainsString("'reconciled_by' => 'shift_session'", $source);
        $this->assertStringContainsString("'reconciled_by' => 'cashup_id'", $source);
        $this->assertStringContainsString('SYNC_CASHUP_CONFLICT:', $source);
        $this->assertStringContainsString("\$mismatches[] = 'denominations'", $source);
        $this->assertStringContainsString(
            'Closed cashups are immutable even when their UUID matches.',
            $source
        );
        $this->assertStringNotContainsString(
            "throw new InvalidArgumentException('This shift already has a cashup under another UUID.')",
            $source
        );
    }

    public function test_only_the_obsolete_uuid_conflict_dead_letter_is_requeued_once(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Sync/SyncSaleBundleService.php');

        $this->assertStringContainsString('requeueLegacyNaturalCashupConflicts($state)', $source);
        $this->assertStringContainsString("->where('event_type', 'shift.cashup.completed')", $source);
        $this->assertStringContainsString("->whereNull('delivered_at')", $source);
        $this->assertStringContainsString("->whereNull('available_at')", $source);
        $this->assertStringContainsString(
            "%This shift already has a cashup under another UUID.%",
            $source
        );
        $this->assertStringContainsString(
            "'last_error' => 'REQUEUED_CASHUP_SEMANTIC_RECONCILIATION'",
            $source
        );
        $this->assertStringContainsString('return $requeued + $queued;', $source);
    }
}
