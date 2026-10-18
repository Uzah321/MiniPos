<?php

namespace Modules\FoodManufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;

class FoodRecipeIngredient extends Model
{
    use HasUuids;



    public $incrementing = false;

    protected $keyType = 'string';
    protected $fillable = [
        "id",
        'company_id',
        'food_recipe_id',
        'child_recipe_id',
        'ingredient_product_id',
        'uom_id',
        'line_type',
        'quantity',
        'wastage_percent',
        'notes',
        'source_line_key',
        'sequence_no',
        'unit_cost',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'wastage_percent' => 'decimal:4',
        'sequence_no' => 'integer',
        'unit_cost' => 'decimal:6',
        'line_total' => 'decimal:6',
    ];

    public function recipe()
    {
        return $this->belongsTo(FoodRecipe::class, 'food_recipe_id');
    }

    public function childRecipe()
    {
        return $this->belongsTo(FoodRecipe::class, 'child_recipe_id');
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
