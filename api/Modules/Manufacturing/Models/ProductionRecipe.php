<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionRecipe extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'finished_product_id', 'name', 'code',
        'costing_basis', 'batch_output_quantity', 'standard_wastage_percent',
        'is_active', 'metadata', 'created_by',
    ];

    protected $casts = [
        'batch_output_quantity' => 'decimal:4',
        'standard_wastage_percent' => 'decimal:4',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function items()
    {
        return $this->hasMany(ProductionRecipeItem::class, 'production_recipe_id');
    }
}
