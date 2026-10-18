<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;

class ProductModifierAssignment extends Model
{
    protected $table = 'product_modifier_assignments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'brand_id', 'branch_id', 'product_id', 'modifier_id', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function modifier()
    {
        return $this->belongsTo(ProductModifier::class, 'modifier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
