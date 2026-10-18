<?php

namespace App\Console\Commands;

use App\Services\Provisioning\StandaloneProvisioningPackageService;
use Illuminate\Console\Command;
use Throwable;

class ImportStandaloneProvisioningPackage extends Command
{
    protected $signature = 'salexa:standalone-import
        {package : Absolute path to the Central-issued .salexa-company file}';

    protected $description = 'Verify and import a Central-issued standalone company provisioning package';

    public function handle(StandaloneProvisioningPackageService $packages): int
    {
        try {
            $path = trim((string) $this->argument('package'));
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                $this->error('The standalone provisioning package file cannot be read.');

                return self::INVALID;
            }

            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                $this->error('The standalone provisioning package file cannot be read.');

                return self::FAILURE;
            }

            $result = $packages->import($packages->deserialize($contents));
            if ($result['already_imported']) {
                $this->info('This signed standalone provisioning package was already imported.');
            } else {
                $this->info('Standalone company provisioning completed successfully.');
            }
            $this->table(['Property', 'Value'], [
                ['Company code', $result['company_code']],
                ['Company ID', $result['company_id']],
                ['Branches', (string) $result['branch_count']],
                ['Licence ID', $result['license_id']],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Package contents include a reusable signed entitlement. Never
            // print input values or a nested exception trace to the console.
            $this->error($this->safeMessage($exception));

            return self::FAILURE;
        }
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        $containsSensitiveMaterial = str_contains(strtolower($message), 'license_token')
            || preg_match('/v2\.[A-Za-z0-9_-]{20,}/', $message) === 1;

        return ! $containsSensitiveMaterial && $message !== '' && strlen($message) <= 500
            ? $message
            : 'Standalone provisioning failed. Check the application log for the non-secret diagnostic.';
    }
}
