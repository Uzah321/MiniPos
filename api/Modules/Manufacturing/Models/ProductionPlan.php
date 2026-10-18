<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionPlan extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'plan_number', 'name', 'planning_basis',
        'period_start', 'period_end', 'status', 'created_by', 'approved_by', 'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(ProductionPlanItem::class, 'production_plan_id');
    }

    public function materialRequirements()
    {
        return $this->hasMany(MaterialRequirement::class, 'production_plan_id');
    }

    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class, 'production_plan_id');
    }
}
