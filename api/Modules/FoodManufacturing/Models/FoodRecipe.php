<?php

namespace Modules\FoodManufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;

class FoodRecipe extends Model
{
    use SoftDeletes,HasUuids;

    protected $table = 'food_recipes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'brand_id',
        'menu_product_id',
        'produced_product_id',
        'name',
        'external_code',
        'source_key',
        'recipe_type',
        'production_output_type',
        'version_no',
        'yield_quantity',
        'yield_uom_id',
        'currency',
        'source_stock_area',
        'destination_stock_area',
        'ingredient_cost',
        'wastage_cost',
        'packaging_cost',
        'labour_cost',
        'overhead_cost',
        'notes',
        'total_cost',
        'cost_per_unit',
        'last_costed_at',
        'is_active',
        'created_by',
        'updated_by',
        'effective_from',
        'effective_to',
        'is_current',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:6',
        'ingredient_cost' => 'decimal:6',
        'wastage_cost' => 'decimal:6',
        'packaging_cost' => 'decimal:6',
        'labour_cost' => 'decimal:6',
        'overhead_cost' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'cost_per_unit' => 'decimal:6',
        'last_costed_at' => 'datetime',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_current' => 'boolean',
    ];

    public function menuProduct()
    {
        return $this->belongsTo(Product::class, 'menu_product_id');
    }

    public function producedProduct()
    {
        return $this->belongsTo(Product::class, 'produced_product_id');
    }

    public function ingredients()
    {
        return $this->hasMany(FoodRecipeIngredient::class, 'food_recipe_id');
    }

    public function yieldUom()
    {
        return $this->belongsTo(\Modules\Product\Models\Uom::class, 'yield_uom_id');
    }

    public function batches()
    {
        return $this->hasMany(FoodManufacturingBatch::class, 'food_recipe_id');
    }
}

//
//namespace Modules\FoodManufacturing\Models;
//
//use Illuminate\Database\Eloquent\Model;
//use Illuminate\Database\Eloquent\SoftDeletes;
//use Modules\Product\Models\Product;
//
//class FoodRecipe extends Model
//{
//    use SoftDeletes;
//
//    protected $fillable = [
//        'company_id',
//        'menu_product_id',
//        'produced_product_id',
//        'name',
//        'recipe_type',
//        'production_output_type',
//        'version_no',
//        'yield_quantity',
//        'currency',
//        'source_stock_area',
//        'destination_stock_area',
//        'ingredient_cost',
//        'wastage_cost',
//        'packaging_cost',
//        'labour_cost',
//        'overhead_cost',
//        'total_cost',
//        'cost_per_unit',
//        'last_costed_at',
//        'is_active',
//        'created_by',
//        'updated_by',
//    ];
//
//    protected $casts = [
//        'yield_quantity' => 'decimal:6',
//        'ingredient_cost' => 'decimal:6',
//        'wastage_cost' => 'decimal:6',
//        'packaging_cost' => 'decimal:6',
//        'labour_cost' => 'decimal:6',
//        'overhead_cost' => 'decimal:6',
//        'total_cost' => 'decimal:6',
//        'cost_per_unit' => 'decimal:6',
//        'last_costed_at' => 'datetime',
//        'is_active' => 'boolean',
//    ];
//
//    public function menuProduct()
//    {
//        return $this->belongsTo(Product::class, 'menu_product_id');
//    }
//
//    public function producedProduct()
//    {
//        return $this->belongsTo(Product::class, 'produced_product_id');
//    }
//
//    public function ingredients()
//    {
//        return $this->hasMany(FoodRecipeIngredient::class, 'food_recipe_id');
//    }
//
//    public function batches()
//    {
//        return $this->hasMany(FoodManufacturingBatch::class, 'food_recipe_id');
//    }
//}
//
