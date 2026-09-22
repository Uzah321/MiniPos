<?php

namespace Modules\Authentication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMfaMethod extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['user_id', 'type', 'secret', 'recovery_code_hashes', 'confirmed_at', 'last_used_at'];
    protected $hidden = ['secret', 'recovery_code_hashes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_code_hashes' => 'array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
