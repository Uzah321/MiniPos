<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\FrontStockDailyClosingFactory;

class FrontStockDailyClosing extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'front_stock_daily_closings';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'closed_by',
        'closing_date',
        'stock_area',
        'status',
        'total_lines',
        'variance_lines',
        'expected_total_quantity',
        'counted_total_quantity',
        'variance_total_quantity',
        'variance_total_value',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'closing_date' => 'date',
        'total_lines' => 'integer',
        'variance_lines' => 'integer',
        'expected_total_quantity' => 'decimal:6',
        'counted_total_quantity' => 'decimal:6',
        'variance_total_quantity' => 'decimal:6',
        'variance_total_value' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(FrontStockDailyClosingLine::class, 'front_stock_daily_closing_id');
    }
}
