<?php

namespace Tests\Unit;

use App\Services\CompanySettingService;
use App\Services\Sync\MobileSaleSnapshot;
use Carbon\Carbon;
use Mockery;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionClass;
use Tests\TestCase;

class MobileSaleHistoricalReplayTest extends TestCase
{
    public function test_retail_replay_keeps_original_business_date_after_server_date_advances(): void
    {
        $payload = $this->historicalPayload('retail');

        $this->assertSame('2026-10-07', $payload['business_date']);
        $this->assertSame('2026-10-07', $payload['system_date']);
        $this->assertFalse($payload['food_industry_business_date_enabled']);
        $this->assertArrayNotHasKey('reconciliation_reason', $payload);
    }

    public function test_mode_changed_since_offline_sale_is_staged_not_posted(): void
    {
        $payload = $this->historicalPayload('hospitality');

        $this->assertSame('BUSINESS_MODE_CHANGED_SINCE_SALE', $payload['reconciliation_reason']);
    }

    private function historicalPayload(string $currentMode): array
    {
        $snapshot = new MobileSaleSnapshot([
            'schema_version' => 3,
            'company_id' => 'company-1',
            'content_hash' => str_repeat('a', 64),
            'generated_at' => '2026-10-07T09:00:00+02:00',
            'datasets' => ['mobile_context' => [[
                'company_id' => 'company-1', 'branch_id' => 'branch-1', 'till_id' => 'till-1',
                'business_mode' => 'retail', 'business_date' => '2026-10-07',
                'system_timezone' => 'Africa/Harare', 'sales_allowed' => true,
            ]]],
        ], 'branch-1', 'till-1');
        $reflection = new ReflectionClass(SalesController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $settings = Mockery::mock(CompanySettingService::class);
        $settings->shouldReceive('businessMode')->once()->with('company-1', 'branch-1')->andReturn($currentMode);
        $reflection->getProperty('companySettingService')->setValue($controller, $settings);

        return $reflection->getMethod('historicalMobileSaleDatePayload')->invoke(
            $controller, $snapshot, 'company-1', 'branch-1',
            Carbon::parse('2026-10-07T10:00:00+02:00'), []
        );
    }
}
