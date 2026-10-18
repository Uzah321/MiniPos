<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuthenticationEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'outcome',
        'pos_user_id',
        'actor_pos_user_id',
        'auth_session_id',
        'channel',
        'workspace',
        'authentication_method',
        'identifier_hash',
        'country_id',
        'company_id',
        'brand_id',
        'complex_id',
        'shop_id',
        'till_id',
        'branch_id',
        'ip_address',
        'user_agent',
        'reason_code',
        'metadata',
        'occurred_at',
    ];

    protected $hidden = [
        'identifier_hash',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Authentication events are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Authentication events are append-only.');
        });
    }

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function actor()
    {
        return $this->belongsTo(PosUser::class, 'actor_pos_user_id');
    }

    public function session()
    {
        return $this->belongsTo(AuthSession::class, 'auth_session_id');
    }
}
