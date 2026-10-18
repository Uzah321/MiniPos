<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionRecipeItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'production_recipe_id', 'product_id', 'uom_id',
        'quantity_required', 'wastage_percent', 'is_active', 'metadata',
    ];

    protected $casts = [
        'quantity_required' => 'decimal:4',
        'wastage_percent' => 'decimal:4',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function recipe()
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }
}
