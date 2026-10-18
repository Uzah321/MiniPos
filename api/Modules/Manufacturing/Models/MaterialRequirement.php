<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class MaterialRequirement extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'production_plan_id', 'production_order_id',
        'product_id', 'required_quantity', 'available_quantity', 'reserved_quantity',
        'shortage_quantity', 'status', 'metadata',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:4',
        'available_quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'shortage_quantity' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
