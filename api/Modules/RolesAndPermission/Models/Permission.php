<?php

namespace Modules\RolesAndPermission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\RolesAndPermission\Database\Factories\PermissionFactory;

class Permission extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'module',
        'group',
        'description',
    ];

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions'
        );
    }
}
