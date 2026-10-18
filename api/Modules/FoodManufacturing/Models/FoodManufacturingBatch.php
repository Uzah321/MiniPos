<?php

namespace Modules\FoodManufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Company\Models\CompanyBranch;
use Modules\Product\Models\Product;

class FoodManufacturingBatch extends Model

{
    use HasUuids;



    public $incrementing = false;

    protected $keyType = 'string';
    protected $fillable = [
        "id",
        'company_id',
        'branch_id',
        'food_recipe_id',
        'recipe_version_no',
        'produced_product_id',
        'source_stock_area',
        'destination_stock_area',
        'planned_yield_quantity',
        'actual_yield_quantity',
        'ingredient_cost',
        'wastage_cost',
        'packaging_cost',
        'labour_cost',
        'overhead_cost',
        'total_cost',
        'unit_cost',
        'currency',
        'notes',
        'is_reversed',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'planned_yield_quantity' => 'decimal:6',
        'actual_yield_quantity' => 'decimal:6',
        'ingredient_cost' => 'decimal:6',
        'wastage_cost' => 'decimal:6',
        'packaging_cost' => 'decimal:6',
        'labour_cost' => 'decimal:6',
        'overhead_cost' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'recipe_version_no' => 'integer',
    ];

    public function recipe()
    {
        return $this->belongsTo(FoodRecipe::class, 'food_recipe_id');
    }

    public function producedProduct()
    {
        return $this->belongsTo(Product::class, 'produced_product_id');
    }

    public function lines()
    {
        return $this->hasMany(FoodManufacturingBatchLine::class, 'food_manufacturing_batch_id');
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }
}
