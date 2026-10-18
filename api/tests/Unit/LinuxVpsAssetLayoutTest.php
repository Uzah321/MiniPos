<?php

namespace Tests\Unit;

use Tests\TestCase;

class LinuxVpsAssetLayoutTest extends TestCase
{
    public function test_linux_guide_uses_the_runtime_asset_root_and_safe_permissions(): void
    {
        $guide = file_get_contents(
            base_path('../docs/deployment/LINUX_VPS_CENTRAL_BACK_OFFICE_INSTALLATION.md')
        );

        $this->assertIsString($guide);
        $this->assertStringContainsString('alias /var/www/salexapos/assets/;', $guide);
        $this->assertStringContainsString(
            'sudo install -d -o salexapos -g www-data -m 2775 /var/www/salexapos/assets',
            $guide
        );
        $this->assertStringContainsString(
            'sudo chmod 0750 /var/www/salexapos',
            $guide
        );
        $this->assertStringContainsString(
            'sudo find /var/www/salexapos/assets /var/www/salexapos/api/assets -type d -exec chmod 2775 {} +',
            $guide
        );
        $this->assertStringContainsString(
            'sudo find /var/www/salexapos/assets /var/www/salexapos/api/assets -type f -exec chmod 0664 {} +',
            $guide
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w /var/www/salexapos/assets',
            $guide
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w /var/www/salexapos/api/assets',
            $guide
        );
        $this->assertStringContainsString(
            'sudo -u www-data test -w /var/www/salexapos/api/storage/app/public',
            $guide
        );
        $this->assertStringNotContainsString('chmod 0777', $guide);
        $this->assertStringNotContainsString('alias /var/www/salexa/assets/;', $guide);
    }
}
