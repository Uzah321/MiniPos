<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PasswordPolicy extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'password_require_uppercase' => 'boolean',
            'password_require_lowercase' => 'boolean',
            'password_require_number' => 'boolean',
            'password_require_symbol' => 'boolean',
            'pin_disallow_sequential' => 'boolean',
            'pin_disallow_repeated' => 'boolean',
            'require_mfa_for_back_office' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
