<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscountType extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'scope',
        'calculation_type',
        'requires_manager_approval',
        'requires_reason',
        'allow_stacking',
        'is_system',
        'is_active',
        'rules',
    ];

    protected $casts = [
        'requires_manager_approval' => 'boolean',
        'requires_reason' => 'boolean',
        'allow_stacking' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'rules' => 'array',
    ];

    public function discounts()
    {
        return $this->hasMany(Discount::class);
    }
}
