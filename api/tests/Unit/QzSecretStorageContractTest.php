<?php

namespace Tests\Unit;

use Modules\GeneralSettings\Models\QzCertificate;
use Tests\TestCase;

class QzSecretStorageContractTest extends TestCase
{
    public function test_qz_disk_is_private_non_served_and_preserves_existing_relative_paths(): void
    {
        $disk = config('filesystems.disks.qz_private');

        $this->assertIsArray($disk);
        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private'), $disk['root']);
        $this->assertSame(config('filesystems.disks.local.root'), $disk['root']);
        $this->assertFalse($disk['serve']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertNotContains($disk['root'], array_values(config('filesystems.links')));
    }

    public function test_qz_storage_paths_are_available_server_side_but_hidden_from_serialization(): void
    {
        $certificate = new QzCertificate([
            'name' => 'Counter certificate',
            'certificate_path' => 'qz-certificates/company/certificate.pem',
            'private_key_path' => 'qz-certificates/company/private-key.pem',
            'is_active' => true,
        ]);

        $serialized = $certificate->toArray();

        $this->assertSame(
            'qz-certificates/company/certificate.pem',
            $certificate->certificate_path
        );
        $this->assertSame(
            'qz-certificates/company/private-key.pem',
            $certificate->private_key_path
        );
        $this->assertArrayNotHasKey('certificate_path', $serialized);
        $this->assertArrayNotHasKey('private_key_path', $serialized);
        $this->assertStringNotContainsString('private-key.pem', $certificate->toJson());
    }

    public function test_apache_denies_direct_access_to_the_storage_tree(): void
    {
        $rules = file_get_contents(storage_path('.htaccess'));

        $this->assertIsString($rules);
        $this->assertStringContainsString('Options -Indexes', $rules);
        $this->assertMatchesRegularExpression('/Require\s+all\s+denied/i', $rules);
        $this->assertMatchesRegularExpression('/Deny\s+from\s+all/i', $rules);
    }

    public function test_legacy_qz_asset_directory_rejects_pem_and_key_files(): void
    {
        $ignoreRules = file_get_contents(base_path('.gitignore'));
        $apacheRules = file_get_contents(base_path('assets/qzcets/.htaccess'));

        $this->assertIsString($ignoreRules);
        $this->assertIsString($apacheRules);
        $this->assertStringContainsString('/assets/qzcets/*.pem', $ignoreRules);
        $this->assertStringContainsString('/assets/qzcets/*.key', $ignoreRules);
        $this->assertStringContainsString('/assets/qzcets/**/*.pem', $ignoreRules);
        $this->assertStringContainsString('/assets/qzcets/**/*.key', $ignoreRules);
        $this->assertMatchesRegularExpression('/Require\s+all\s+denied/i', $apacheRules);
        $this->assertMatchesRegularExpression('/Deny\s+from\s+all/i', $apacheRules);
        $this->assertFileDoesNotExist(base_path('assets/qzcets/private-key.pem'));
    }
}
