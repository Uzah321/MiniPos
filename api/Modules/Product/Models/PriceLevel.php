<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\RolesAndPermission\Models\Role;

// use Modules\Product\Database\Factories\PriceLevelFactory;

class PriceLevel extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'price_levels';

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'role_price_level',
            'price_level_id',
            'role_id'
        )
            ->withPivot('id', 'company_id')
            ->withTimestamps();
    }
}


