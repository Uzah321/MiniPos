<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class MobileSyncContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hospitality_context_supplies_a_hashed_authoritative_business_date(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00 Africa/Harare');

        $context = $this->context([]);

        $this->assertSame('hospitality', $context['business_mode']);
        $this->assertTrue($context['hospitality_only']);
        $this->assertSame('2026-10-07', $context['business_date']);
        $this->assertTrue($context['sales_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['effective_window_hash']);
    }

    public function test_hospitality_context_uses_the_open_trading_date(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00 Africa/Harare');
        $settings = array_map(fn (string $key): array => [
            'id' => $key,
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'setting_key' => $key,
            'setting_value' => 'true',
            'is_active' => true,
        ], [
            'boh_enabled',
            'fast_food_mode_enabled',
            'receive_stock_to_boh_first',
            'recipe_consumes_front_stock',
        ]);

        $context = $this->context([
            'general_settings' => $settings,
            'trading_days' => [[
                'id' => 'day-1',
                'company_id' => 'company-1',
                'branch_id' => 'branch-1',
                'trading_date' => '2026-10-06',
                'status' => 'open',
            ]],
        ]);

        $this->assertSame('hospitality', $context['business_mode']);
        $this->assertSame('2026-10-06', $context['business_date']);
        $this->assertTrue($context['sales_allowed']);
    }

    public function test_effective_window_identity_changes_when_a_scheduled_price_activates(): void
    {
        $datasets = [
            'product_prices' => [[
                'id' => 'price-1',
                'starts_at' => '2026-10-07 11:00:00',
                'ends_at' => null,
                'is_active' => true,
            ]],
        ];

        Carbon::setTestNow('2026-10-07 10:00:00 Africa/Harare');
        $before = $this->context($datasets)['effective_window_hash'];
        Carbon::setTestNow('2026-10-07 12:00:00 Africa/Harare');
        $after = $this->context($datasets)['effective_window_hash'];

        $this->assertNotSame($before, $after);
    }

    public function test_context_uses_one_supplied_instant_for_date_and_effective_windows(): void
    {
        $datasets = [
            'product_prices' => [[
                'id' => 'midnight-price',
                'starts_at' => '2026-10-08 00:00:00',
                'ends_at' => null,
                'is_active' => true,
            ]],
        ];
        $beforeMidnight = CarbonImmutable::parse('2026-10-07 23:59:59 Africa/Harare');
        $afterMidnight = CarbonImmutable::parse('2026-10-08 00:00:01 Africa/Harare');

        // Moving the ambient clock must not split one snapshot across the
        // boundary; only the explicitly supplied snapshot instant controls it.
        Carbon::setTestNow($afterMidnight);
        $before = $this->context($datasets, $beforeMidnight);
        $after = $this->context($datasets, $afterMidnight);

        $this->assertSame('2026-10-07', $before['business_date']);
        $this->assertSame('2026-10-08', $after['business_date']);
        $this->assertNotSame($before['effective_window_hash'], $after['effective_window_hash']);
    }

    public function test_hospitality_open_day_uses_the_supplied_instant_at_midnight(): void
    {
        $snapshotAt = CarbonImmutable::parse('2026-10-07 23:59:59 Africa/Harare');
        Carbon::setTestNow('2026-10-08 00:00:01 Africa/Harare');

        $context = $this->context([
            'general_settings' => $this->hospitalitySettings(),
            'trading_days' => [[
                'id' => 'future-day',
                'company_id' => 'company-1',
                'branch_id' => 'branch-1',
                'trading_date' => '2026-10-08',
                'status' => 'open',
            ]],
        ], $snapshotAt);

        $this->assertSame('2026-10-07', $context['system_date']);
        $this->assertNull($context['business_date']);
        $this->assertFalse($context['sales_allowed']);
    }

    public function test_hospitality_next_day_uses_the_supplied_instant_at_midnight(): void
    {
        $snapshotAt = CarbonImmutable::parse('2026-10-07 23:59:59 Africa/Harare');
        Carbon::setTestNow('2026-10-08 00:00:01 Africa/Harare');

        $context = $this->context([
            'general_settings' => $this->hospitalitySettings(),
            'trading_days' => [[
                'id' => 'closed-day',
                'company_id' => 'company-1',
                'branch_id' => 'branch-1',
                'trading_date' => '2026-10-07',
                'status' => 'closed',
            ]],
        ], $snapshotAt);

        $this->assertSame('2026-10-07', $context['system_date']);
        $this->assertNull($context['business_date']);
        $this->assertFalse($context['sales_allowed']);
    }

    public function test_android_license_projection_contains_warning_facts_but_no_entitlement_secrets(): void
    {
        $service = new SyncConfigurationService(new SyncPayloadHasher);
        $method = new ReflectionMethod($service, 'mobileLicenseProjection');
        $projected = $method->invoke($service, [
            'valid' => true,
            'code' => 'LICENSE_EXPIRING',
            'license_id' => 'license-1',
            'license_key' => 'MUST-NOT-LEAVE-THE-CLOUD',
            'expires_at' => '2027-01-06',
            'days_remaining' => 90,
            'warning_days' => 90,
            'warning_required' => true,
            'license_support_phone' => '+263779564852',
            'entitlement_payload_v2' => 'secret-payload',
            'entitlement_signature_v2' => 'secret-signature',
        ]);

        $this->assertTrue($projected['license_status_known']);
        $this->assertSame('license-1', $projected['license_id']);
        $this->assertSame('2027-01-06', $projected['license_expires_at']);
        $this->assertSame(90, $projected['license_warning_days']);
        $this->assertSame('+263779564852', $projected['license_support_phone']);
        $this->assertArrayNotHasKey('license_key', $projected);
        $this->assertArrayNotHasKey('entitlement_payload_v2', $projected);
        $this->assertArrayNotHasKey('entitlement_signature_v2', $projected);
    }

    /** @return array<int, array<string, mixed>> */
    private function hospitalitySettings(): array
    {
        return array_map(fn (string $key): array => [
            'id' => $key,
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'setting_key' => $key,
            'setting_value' => 'true',
            'is_active' => true,
        ], [
            'boh_enabled',
            'fast_food_mode_enabled',
            'receive_stock_to_boh_first',
            'recipe_consumes_front_stock',
        ]);
    }

    /** @param array<string, array<int, array<string, mixed>>> $datasets */
    private function context(array $datasets, ?CarbonImmutable $snapshotAt = null): array
    {
        $node = new SyncNode([
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'till_id' => 'till-1',
            'deployment_mode' => 'android',
        ]);
        $service = new SyncConfigurationService(new SyncPayloadHasher);
        $method = new ReflectionMethod($service, 'mobileContext');

        return $method->invoke($service, $node, $datasets, $snapshotAt);
    }
}
