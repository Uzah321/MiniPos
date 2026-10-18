<?php

namespace Database\Seeders;

use App\Services\Settings\GeneralSettingsProvisioner;
use Illuminate\Database\Seeder;
use Modules\Company\Models\Company;

class GeneralSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Seed definitions without resetting a company's live configuration.
        // Administrators may safely rerun this seeder after new settings are added.
        $provisioner = app(GeneralSettingsProvisioner::class);

        foreach (Company::all() as $company) {
            $provisioner->ensureCompanyDefaults((string) $company->id);
        }
    }
}
