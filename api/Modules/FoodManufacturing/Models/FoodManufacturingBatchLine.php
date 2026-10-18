<?php

namespace Modules\FoodManufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;

class FoodManufacturingBatchLine extends Model
{
    use HasUuids;



    public $incrementing = false;

    protected $keyType = 'string';
    protected $fillable = [
        "id",
        'company_id',
        'food_manufacturing_batch_id',
        'ingredient_product_id',
        'uom_id',
        'line_type',
        'stock_area',
        'net_quantity',
        'wastage_percent',
        'wastage_quantity',
        'gross_quantity',
        'unit_cost',
        'line_cost',
        'currency',
    ];

    protected $casts = [
        'net_quantity' => 'decimal:6',
        'wastage_percent' => 'decimal:4',
        'wastage_quantity' => 'decimal:6',
        'gross_quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'line_cost' => 'decimal:6',
    ];

    public function batch()
    {
        return $this->belongsTo(FoodManufacturingBatch::class, 'food_manufacturing_batch_id');
    }

    public function ingredientProduct()
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }

    public function uom()
    {
        return $this->belongsTo(\Modules\Product\Models\Uom::class, 'uom_id');
    }
}
