<?php

use App\Services\Sales\DiscountTypeProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Companies created after the database seeder ran had no discount types, so
 * no discount could be saved for them. Give every company the missing system
 * types. Edge installations receive discount types from Central through
 * configuration sync and must not mint their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('salexa_sync.deployment_mode') === 'edge') {
            return;
        }

        if (! Schema::hasTable('discount_types') || ! Schema::hasTable('companies')) {
            return;
        }

        $provisioner = app(DiscountTypeProvisioner::class);

        DB::table('companies')
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($companyId) => $provisioner->ensureCompanyDefaults((string) $companyId));
    }

    public function down(): void
    {
        // Provisioned types may already be referenced by discounts.
    }
};
