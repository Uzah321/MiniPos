<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuthSession extends Model
{
    use HasUuids;

    protected $table = 'auth_sessions';

    protected $fillable = [
        'pos_user_id',
        'personal_access_token_id',
        'channel',
        'workspace',
        'authentication_method',
        'country_id',
        'company_id',
        'brand_id',
        'complex_id',
        'shop_id',
        'till_id',
        'branch_id',
        'credential_version',
        'policy_fingerprint',
        'policy_snapshot',
        'device_identifier_hash',
        'ip_address',
        'user_agent',
        'issued_at',
        'last_activity_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'device_identifier_hash',
        'policy_snapshot',
    ];

    protected $casts = [
        'personal_access_token_id' => 'integer',
        'credential_version' => 'integer',
        'policy_snapshot' => 'array',
        'issued_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }
}
