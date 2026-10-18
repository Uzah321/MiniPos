<?php

namespace App\Console\Commands;

use App\Services\License\LicenseService;
use App\Services\License\LicenseSignatureService;
use Illuminate\Console\Command;
use Modules\Company\Models\License;
use Throwable;

class ResignLicenseEntitlements extends Command
{
    protected $signature = 'licenses:resign-entitlements
        {--all : Re-sign licenses that already have a v2 signature}
        {--chunk=200 : Number of licenses to process per database page}';

    protected $description = 'Issue asymmetric v2 signatures for legacy license entitlements';

    public function handle(
        LicenseService $licenses,
        LicenseSignatureService $signatures
    ): int {
        if ($signatures->mode() !== 'issuer' || ! $signatures->canSign()) {
            $this->error('An issuer node with a configured private key is required.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $query = License::query()->orderBy('id');
        if (! $this->option('all')) {
            $query->where(function ($builder) {
                $builder->whereNull('entitlement_signature_v2')
                    ->orWhere('entitlement_version', '<', 2);
            });
        }

        $signed = 0;
        $failed = 0;
        $query->chunk($chunk, function ($batch) use ($licenses, &$signed, &$failed): void {
            foreach ($batch as $license) {
                try {
                    $licenses->signEntitlement($license);
                    $signed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn("License {$license->id}: {$exception->getMessage()}");
                }
            }
        });

        $this->info("Signed {$signed} license entitlement(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
