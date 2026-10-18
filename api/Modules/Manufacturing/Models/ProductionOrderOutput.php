<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionOrderOutput extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'production_order_id',
        'product_id',
        'uom_id',
        'quantity_produced',
        'quantity_rejected',
        'quantity_wasted',
        'unit_cost',
        'total_cost',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'quantity_produced' => 'decimal:4',
        'quantity_rejected' => 'decimal:4',
        'quantity_wasted' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }
}
