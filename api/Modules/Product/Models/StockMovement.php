<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\CompanyBranch;

// use Modules\Product\Database\Factories\StockMovementFactory;

class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'warehouse_id',
        'location_id',
        'batch_id',
        'product_id',
        'movement_type',
        'quantity_in',
        'quantity_out',
        'unit_cost',
        'total_cost',
        'currency',
        'reference_type',
        'reference_id',
        'notes',
        'metadata',
        'created_by',
        'stock_area',
        'from_stock_area',
        'to_stock_area',
        'balance_after',
        'currency_code',
        'issue_destination',
        'stock_usage_type',
        'expense_account_code',

    ];

    protected $casts = [
        'quantity_in' => 'decimal:4',
        'quantity_out' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(PosUser::class, 'created_by');
    }
}
