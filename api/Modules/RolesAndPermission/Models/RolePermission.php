<?php

namespace Modules\RolesAndPermission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\RolesAndPermission\Database\Factories\RolePermissionFactory;

class RolePermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'role_id',
        'permission_id',
        'company_id',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
