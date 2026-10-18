<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;
use Modules\Product\Models\Uom;

class ProductionOrderOverhead extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'production_order_id', 'cost_type',
        'description', 'amount', 'currency', 'expense_id', 'payroll_id',
        'metadata', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
