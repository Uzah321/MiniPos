<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'guard_name', 'description', 'is_super_admin', 'status'];

    protected function casts(): array
    {
        return ['is_super_admin' => 'boolean'];
    }
}
