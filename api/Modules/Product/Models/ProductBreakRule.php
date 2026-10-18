<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ProductBreakRule extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_product_id',
        'child_product_id',
        'parent_quantity',
        'units_per_parent',
        'is_auto_break',
        'is_active',
    ];

    protected $casts = [
        'parent_quantity' => 'decimal:4',
        'units_per_parent' => 'decimal:4',
        'is_auto_break' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parentProduct()
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function childProduct()
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }
}
