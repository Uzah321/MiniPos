<?php

namespace Modules\FoodManufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\CompanyBranch;
use Modules\Product\Models\Product;

class FoodManufacturingProductionPlan extends Model
{
    use HasUuids;

    protected $table = 'food_manufacturing_production_plans';

    protected $fillable = [
        'company_id',
        'branch_id',
        'plan_number',
        'food_recipe_id',
        'recipe_version_no',
        'produced_product_id',
        'source_stock_area',
        'destination_stock_area',
        'planned_quantity',
        'actual_quantity',
        'status',
        'currency',
        'required_value',
        'shortage_value',
        'shortage_line_count',
        'can_produce',
        'planned_for',
        'notes',
        'summary',
        'lines',
        'exploded_lines',
        'subrecipe_requirements',
        'created_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:6',
        'actual_quantity' => 'decimal:6',
        'required_value' => 'decimal:6',
        'shortage_value' => 'decimal:6',
        'shortage_line_count' => 'integer',
        'can_produce' => 'boolean',
        'planned_for' => 'date',
        'summary' => 'array',
        'lines' => 'array',
        'exploded_lines' => 'array',
        'subrecipe_requirements' => 'array',
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

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }
}
