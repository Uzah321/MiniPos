<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\FrontStockDailyClosingLineFactory;

class FrontStockDailyClosingLine extends Model
{
    use HasUuids;

    protected $table = 'front_stock_daily_closing_lines';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'front_stock_daily_closing_id',
        'company_id',
        'branch_id',
        'product_id',
        'product_code',
        'product_sku',
        'product_name',
        'product_type',
        'uom_code',
        'stock_area',
        'expected_quantity',
        'counted_quantity',
        'variance_quantity',
        'unit_cost',
        'variance_value',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:6',
        'counted_quantity' => 'decimal:6',
        'variance_quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'variance_value' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function closing()
    {
        return $this->belongsTo(FrontStockDailyClosing::class, 'front_stock_daily_closing_id');
    }
}
