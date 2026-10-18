<?php

namespace Tests\Unit;

use App\Services\Sync\SyncTransportService;
use Illuminate\Http\Client\PendingRequest;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class SyncTransportTlsTest extends TestCase
{
    public function test_normal_sync_client_keeps_tls_verification_enabled(): void
    {
        config()->set('salexa_sync.http.verify_tls', true);
        config()->set('salexa_sync.http.ca_bundle', null);

        $this->assertTrue($this->clientOptions()['verify']);
    }

    public function test_readable_custom_ca_bundle_is_used_for_sync_only(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'salexa-sync-ca-');
        $this->assertNotFalse($bundle);

        try {
            file_put_contents($bundle, "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n");
            config()->set('salexa_sync.http.verify_tls', true);
            config()->set('salexa_sync.http.ca_bundle', $bundle);

            $this->assertSame(realpath($bundle), $this->clientOptions()['verify']);
        } finally {
            unlink($bundle);
        }
    }

    public function test_missing_or_non_file_ca_bundle_fails_before_a_request(): void
    {
        config()->set('salexa_sync.http.verify_tls', true);
        config()->set('salexa_sync.http.ca_bundle', sys_get_temp_dir().'/missing-salexa-ca-'.uniqid().'.pem');

        try {
            $this->clientOptions();
            $this->fail('Expected a missing trust bundle to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing or unreadable', $exception->getMessage());
        }

        config()->set('salexa_sync.http.ca_bundle', sys_get_temp_dir());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');
        $this->clientOptions();
    }

    public function test_custom_ca_bundle_cannot_be_combined_with_disabled_tls_verification(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'salexa-sync-ca-');
        $this->assertNotFalse($bundle);

        try {
            config()->set('salexa_sync.http.verify_tls', false);
            config()->set('salexa_sync.http.ca_bundle', $bundle);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('requires TLS verification');
            $this->clientOptions();
        } finally {
            unlink($bundle);
        }
    }

    /** @return array<string, mixed> */
    private function clientOptions(): array
    {
        $client = (new ReflectionMethod(SyncTransportService::class, 'client'))
            ->invoke(app(SyncTransportService::class));
        $this->assertInstanceOf(PendingRequest::class, $client);

        return (new ReflectionProperty(PendingRequest::class, 'options'))->getValue($client);
    }
}
