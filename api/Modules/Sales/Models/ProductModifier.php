<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;

class ProductModifier extends Model
{
    protected $table = 'product_modifiers';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'brand_id', 'branch_id', 'name', 'code', 'modifier_product_id', 'recipe_id',
        'price', 'currency_code', 'deduct_recipe_stock', 'print_on_kitchen',
        'print_on_customer_receipt', 'is_active',
    ];

    protected $casts = [
        'price' => 'float',
        'deduct_recipe_stock' => 'boolean',
        'print_on_kitchen' => 'boolean',
        'print_on_customer_receipt' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function assignments()
    {
        return $this->hasMany(ProductModifierAssignment::class, 'modifier_id');
    }

    public function modifierProduct()
    {
        return $this->belongsTo(Product::class, 'modifier_product_id');
    }
}
