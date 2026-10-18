<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\RolesAndPermission\Models\Role;

class DiscountRolePermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'discount_id',
        'role_id',
        'max_percentage',
        'max_amount',
        'can_apply_without_manager',
    ];

    protected $casts = [
        'max_percentage' => 'decimal:4',
        'max_amount' => 'decimal:4',
        'can_apply_without_manager' => 'boolean',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }
}
