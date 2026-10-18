<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('license_support_phone', 50)->nullable()->after('host_ip');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->string('renewal_source', 32)->nullable()->after('status');
            $table->longText('license_token')->nullable()->after('renewal_source');
            $table->longText('entitlement_payload')->nullable()->after('license_token');
            $table->string('entitlement_signature', 64)->nullable()->after('entitlement_payload');
            $table->timestamp('activated_at')->nullable()->after('entitlement_signature');
            $table->timestamp('revoked_at')->nullable()->after('activated_at');
            $table->index(['company_id', 'status', 'expires_at'], 'licenses_company_status_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex('licenses_company_status_expiry_index');
            $table->dropColumn([
                'renewal_source',
                'license_token',
                'entitlement_payload',
                'entitlement_signature',
                'activated_at',
                'revoked_at',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('license_support_phone');
        });
    }
};
