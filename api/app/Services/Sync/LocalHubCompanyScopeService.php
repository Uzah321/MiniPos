<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fail closed if a local sync hub is not the one-company installation
 * authorized by a Central-signed provisioning package.
 */
class LocalHubCompanyScopeService
{
    public function isLocalHub(): bool
    {
        return (bool) config('salexa_sync.local_hub', false);
    }

    public function provisionedCompanyId(): ?string
    {
        if (! $this->isLocalHub()
            || config('salexa_sync.deployment_mode') !== 'cloud'
            || config('license.signing.mode') !== 'verify_only'
            || trim((string) config('license.signing.private_key')) !== ''
            || ! Schema::hasTable('companies')
            || ! Schema::hasTable('standalone_provisioning_imports')) {
            return null;
        }

        // LIMIT 2 is enough to detect ambiguity without scanning every row
        // of an accidentally reused multi-tenant Central database. Include
        // soft-deleted companies: they still prove this is not a pristine
        // one-company hub and may hold historical operational data.
        $companyIds = DB::table('companies')->limit(2)->pluck('id');
        $importIds = DB::table('standalone_provisioning_imports')
            ->distinct()
            ->limit(2)
            ->pluck('company_id');

        if ($companyIds->count() !== 1 || $importIds->count() !== 1) {
            return null;
        }
        $companyId = (string) $companyIds->first();
        $importCompanyId = (string) $importIds->first();

        return $companyId !== '' && hash_equals($companyId, $importCompanyId)
            ? $companyId
            : null;
    }

    public function allowsCompany(string $companyId): bool
    {
        if (! $this->isLocalHub()) {
            return true;
        }

        $provisioned = $this->provisionedCompanyId();

        return $provisioned !== null && hash_equals($provisioned, $companyId);
    }
}
