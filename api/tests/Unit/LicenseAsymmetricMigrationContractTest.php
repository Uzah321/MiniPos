<?php

namespace Tests\Unit;

use Modules\Company\Models\License;
use Tests\TestCase;

class LicenseAsymmetricMigrationContractTest extends TestCase
{
    public function test_migration_is_additive_and_sizes_asymmetric_signature_storage(): void
    {
        $migration = file_get_contents(base_path(
            'database/migrations/2026_10_05_140000_add_asymmetric_license_entitlements.php'
        ));

        $this->assertStringContainsString("->longText('entitlement_payload_v2')", $migration);
        $this->assertStringContainsString("->text('entitlement_signature_v2')", $migration);
        $this->assertStringContainsString("->string('signature_algorithm', 32)", $migration);
        $this->assertStringContainsString("->string('signature_key_id', 64)", $migration);
        $this->assertStringContainsString("->unsignedSmallInteger('entitlement_version')", $migration);
        $this->assertStringContainsString("->unsignedBigInteger('entitlement_revision')", $migration);
        $this->assertStringNotContainsString("dropColumn('entitlement_signature')", $migration);
        $this->assertStringContainsString('2026_10_05_120000', $migration);
    }

    public function test_v2_secrets_are_hidden_but_public_verification_metadata_is_available(): void
    {
        $license = new License([
            'entitlement_payload_v2' => 'payload',
            'entitlement_signature_v2' => 'signature',
            'signature_algorithm' => 'openssl-sha256',
            'signature_key_id' => 'primary-2026',
            'entitlement_version' => 2,
            'entitlement_revision' => 4,
        ]);
        $serialized = $license->toArray();

        $this->assertArrayNotHasKey('entitlement_payload_v2', $serialized);
        $this->assertArrayNotHasKey('entitlement_signature_v2', $serialized);
        $this->assertSame('openssl-sha256', $serialized['signature_algorithm']);
        $this->assertSame('primary-2026', $serialized['signature_key_id']);
        $this->assertSame(2, $serialized['entitlement_version']);
        $this->assertSame(4, $serialized['entitlement_revision']);
    }

    public function test_edge_signing_mode_uses_the_canonical_deployment_setting_with_legacy_alias(): void
    {
        $config = file_get_contents(base_path('config/license.php'));

        $this->assertStringContainsString("'SALEXA_DEPLOYMENT_MODE'", $config);
        $this->assertStringContainsString("env('SALEXA_SYNC_DEPLOYMENT_MODE'", $config);
        $this->assertStringContainsString(
            '$deploymentMode === \'cloud\' ? \'issuer\' : \'verify_only\'',
            $config
        );
        $this->assertStringContainsString('$deploymentMode === \'edge\'', $config);
    }
}
