<?php

namespace Tests\Unit;

use App\Services\SalesAnalyticsService;
use Tests\TestCase;

class SalesAnalyticsServiceConfigurationTest extends TestCase
{
    public function test_it_uses_environment_backed_service_configuration(): void
    {
        config([
            'services.simbisa.base' => 'https://analytics.example.test/root/',
            'services.simbisa.key' => 'test-only-key',
        ]);

        $service = new SalesAnalyticsService;
        $reflection = new \ReflectionClass($service);

        $this->assertSame(
            'https://analytics.example.test/root/sales',
            $reflection->getProperty('baseUrl')->getValue($service)
        );
        $this->assertSame(
            'test-only-key',
            $reflection->getProperty('apiKey')->getValue($service)
        );
    }

    public function test_it_fails_closed_when_the_integration_key_is_missing(): void
    {
        config([
            'services.simbisa.base' => 'https://analytics.example.test',
            'services.simbisa.key' => null,
        ]);

        $result = (new SalesAnalyticsService)->getSalesData();

        $this->assertSame(
            'The Simbisa API integration is not configured.',
            $result['error'] ?? null
        );
    }
}
