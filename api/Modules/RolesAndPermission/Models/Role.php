<?php

namespace Modules\RolesAndPermission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Company\Models\Company;
use Modules\Product\Models\PriceLevel;

// use Modules\RolesAndPermission\Database\Factories\RoleFactory;

class Role extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'group'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions'
        );
    }

    public function users()
    {
        return $this->hasMany(\App\Models\User::class);
    }

    public function priceLevels()
    {
        return $this->belongsToMany(
            PriceLevel::class,
            'role_price_levels',
            'role_id',
            'price_level_id'
        )
            ->withPivot('id', 'company_id')
            ->withTimestamps();
    }
}
