<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CredentialHistory extends Model
{
    use HasUuids;

    protected $table = 'auth_credential_histories';

    protected $fillable = [
        'pos_user_id',
        'credential_type',
        'credential_hash',
        'credential_version',
        'retired_at',
    ];

    protected $hidden = [
        'credential_hash',
    ];

    protected $casts = [
        'credential_version' => 'integer',
        'retired_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }
}
