<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Intentionally follows the 2026_10_05_120000 licensing hardening
        // migration, which creates the legacy entitlement signature columns.
        Schema::table('licenses', function (Blueprint $table) {
            $table->longText('entitlement_payload_v2')->nullable()->after('entitlement_signature');
            // TEXT accommodates base64 signatures from RSA-4096 as well as EC
            // and Ed25519 without changing the legacy 64-character HMAC field.
            $table->text('entitlement_signature_v2')->nullable()->after('entitlement_payload_v2');
            $table->string('signature_algorithm', 32)->nullable()->after('entitlement_signature_v2');
            $table->string('signature_key_id', 64)->nullable()->after('signature_algorithm');
            $table->unsignedSmallInteger('entitlement_version')->default(1)->after('signature_key_id');
            $table->unsignedBigInteger('entitlement_revision')->default(1)->after('entitlement_version');
            $table->timestamp('signed_at')->nullable()->after('entitlement_revision');
            $table->index(
                ['signature_algorithm', 'signature_key_id'],
                'licenses_signature_algorithm_key_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex('licenses_signature_algorithm_key_index');
            $table->dropColumn([
                'entitlement_payload_v2',
                'entitlement_signature_v2',
                'signature_algorithm',
                'signature_key_id',
                'entitlement_version',
                'entitlement_revision',
                'signed_at',
            ]);
        });
    }
};
