<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionPlanItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'production_plan_id', 'finished_product_id',
        'production_recipe_id', 'forecast_quantity', 'current_stock_quantity',
        'safety_stock_quantity', 'planned_quantity', 'batch_size', 'planned_batches',
        'planned_unit_cost', 'planned_total_cost', 'required_by', 'metadata',
    ];

    protected $casts = [
        'forecast_quantity' => 'decimal:4',
        'current_stock_quantity' => 'decimal:4',
        'safety_stock_quantity' => 'decimal:4',
        'planned_quantity' => 'decimal:4',
        'batch_size' => 'decimal:4',
        'planned_unit_cost' => 'decimal:6',
        'planned_total_cost' => 'decimal:4',
        'required_by' => 'date',
        'metadata' => 'array',
    ];

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function recipe()
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }
}
