<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'production_plan_id', 'production_plan_item_id',
        'production_recipe_id', 'finished_product_id', 'production_number', 'status',
        'batch_number', 'batch_size', 'planned_output_quantity', 'actual_good_quantity',
        'actual_waste_quantity', 'actual_reject_quantity', 'planned_raw_material_cost',
        'planned_labour_cost', 'planned_overhead_cost', 'planned_total_cost',
        'planned_unit_cost', 'actual_raw_material_cost', 'actual_labour_cost',
        'actual_overhead_cost', 'actual_total_cost', 'actual_unit_cost',
        'yield_variance_quantity', 'cost_variance_amount', 'currency',
        'planned_start_date', 'planned_end_date', 'started_at', 'completed_at',
        'approved_by', 'created_by', 'metadata',
    ];

    protected $casts = [
        'batch_size' => 'decimal:4',
        'planned_output_quantity' => 'decimal:4',
        'actual_good_quantity' => 'decimal:4',
        'actual_waste_quantity' => 'decimal:4',
        'actual_reject_quantity' => 'decimal:4',
        'planned_raw_material_cost' => 'decimal:4',
        'planned_labour_cost' => 'decimal:4',
        'planned_overhead_cost' => 'decimal:4',
        'planned_total_cost' => 'decimal:4',
        'planned_unit_cost' => 'decimal:6',
        'actual_raw_material_cost' => 'decimal:4',
        'actual_labour_cost' => 'decimal:4',
        'actual_overhead_cost' => 'decimal:4',
        'actual_total_cost' => 'decimal:4',
        'actual_unit_cost' => 'decimal:6',
        'yield_variance_quantity' => 'decimal:4',
        'cost_variance_amount' => 'decimal:4',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function planItem()
    {
        return $this->belongsTo(ProductionPlanItem::class, 'production_plan_item_id');
    }

    public function recipe()
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function inputs()
    {
        return $this->hasMany(ProductionOrderInput::class, 'production_order_id');
    }

    public function outputs()
    {
        return $this->hasMany(ProductionOrderOutput::class, 'production_order_id');
    }

    public function overheads()
    {
        return $this->hasMany(ProductionOrderOverhead::class, 'production_order_id');
    }
}
