<?php

use App\Services\Settings\GeneralSettingsProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Companies created after the settings seeder ran had no general settings, so
 * the General Settings screen was empty. Give every company the settings it is
 * missing, using the values the system already applies when a setting is
 * absent, so nothing changes until an administrator edits them. Edge
 * installations receive general settings from Central through sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('salexa_sync.deployment_mode') === 'edge') {
            return;
        }

        if (! Schema::hasTable('general_settings') || ! Schema::hasTable('companies')) {
            return;
        }

        $provisioner = app(GeneralSettingsProvisioner::class);

        DB::table('companies')
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($companyId) => $provisioner->ensureCompanyDefaults((string) $companyId, keepCurrentBehaviour: true));
    }

    public function down(): void
    {
        // Settings may already have been edited by administrators.
    }
};
