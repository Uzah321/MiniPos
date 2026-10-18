<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use HasUuids, SoftDeletes;

    protected $appends = ['discount_type_name'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'discount_type_id',
        'code',
        'name',
        'description',
        'discount_basis',
        'value',
        'max_discount_amount',
        'min_sale_amount',
        'min_quantity',
        'applies_to_all_products',
        'applies_to_all_categories',
        'requires_customer',
        'requires_coupon_code',
        'coupon_code',
        'usage_limit',
        'usage_count',
        'per_customer_limit',
        'starts_at',
        'ends_at',
        'requires_manager_approval',
        'requires_reason',
        'allow_stacking',
        'priority',
        'rules',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'min_sale_amount' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'applies_to_all_products' => 'boolean',
        'applies_to_all_categories' => 'boolean',
        'requires_customer' => 'boolean',
        'requires_coupon_code' => 'boolean',
        'requires_manager_approval' => 'boolean',
        'requires_reason' => 'boolean',
        'allow_stacking' => 'boolean',
        'is_active' => 'boolean',
        'rules' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // The shared discounts table also has Product's legacy `type` column.
    // A relationship called `type` would resolve to that string instead.
    public function discountType()
    {
        return $this->belongsTo(DiscountType::class, 'discount_type_id');
    }

    public function getDiscountTypeNameAttribute(): ?string
    {
        return $this->relationLoaded('discountType')
            ? $this->getRelation('discountType')?->name
            : null;
    }

    public function productTargets()
    {
        return $this->hasMany(DiscountProduct::class, 'discount_id');
    }

    public function categoryTargets()
    {
        return $this->hasMany(DiscountCategory::class, 'discount_id');
    }

    public function rolePermissions()
    {
        return $this->hasMany(DiscountRolePermission::class, 'discount_id');
    }
}
