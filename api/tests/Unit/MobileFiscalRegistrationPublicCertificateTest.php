<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Zimra\MobileFiscalRegistrationService;
use Tests\TestCase;

class MobileFiscalRegistrationPublicCertificateTest extends TestCase
{
    public function test_private_key_material_is_rejected_before_registration(): void
    {
        $node = new SyncNode([
            'deployment_mode' => 'android',
            'status' => 'active',
            'activated_at' => new \DateTimeImmutable,
            'till_id' => 'till-1',
        ]);

        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY', 'ENCRYPTED PRIVATE KEY'] as $type) {
            try {
                (new MobileFiscalRegistrationService)->register([
                    'device_id' => 123,
                    'device_serial_no' => 'SERIAL-1',
                    'certificate_pem' => "-----BEGIN CERTIFICATE-----\npublic\n-----END CERTIFICATE-----\n-----BEGIN {$type}-----\nsecret\n-----END {$type}-----",
                ], $node, 'admin-1');
                self::fail("{$type} was accepted");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Never upload the Android private key', $exception->getMessage());
            }
        }
    }
}
