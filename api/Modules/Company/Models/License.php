<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// use Modules\Company\Database\Factories\LicenseFactory;

class License extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'license_key',
        'license_type',
        'max_branches',
        'max_users',
        'max_devices',
        'starts_at',
        'expires_at',
        'status',
        'renewal_source',
        'license_token',
        'entitlement_payload',
        'entitlement_signature',
        'entitlement_payload_v2',
        'entitlement_signature_v2',
        'signature_algorithm',
        'signature_key_id',
        'entitlement_version',
        'entitlement_revision',
        'signed_at',
        'activated_at',
        'revoked_at',
    ];

    protected $casts = [
        'starts_at' => 'date:Y-m-d',
        'expires_at' => 'date:Y-m-d',
        'entitlement_version' => 'integer',
        'entitlement_revision' => 'integer',
        'signed_at' => 'datetime',
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'license_token',
        'entitlement_payload',
        'entitlement_signature',
        'entitlement_payload_v2',
        'entitlement_signature_v2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
