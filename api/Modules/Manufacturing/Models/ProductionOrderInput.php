<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionOrderInput extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'production_order_id', 'product_id', 'uom_id',
        'planned_quantity', 'reserved_quantity', 'issued_quantity', 'returned_quantity',
        'wasted_quantity', 'unit_cost', 'total_cost', 'currency', 'metadata',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'issued_quantity' => 'decimal:4',
        'returned_quantity' => 'decimal:4',
        'wasted_quantity' => 'decimal:4',
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
