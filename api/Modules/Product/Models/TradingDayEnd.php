<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradingDayEnd extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'trading_date', 'next_trading_date', 'status',
        'started_by', 'completed_by', 'started_at', 'completed_at', 'failed_at',
        'failure_reason', 'lock_token', 'lock_started_at', 'lock_released_at',
        'summary', 'metadata',
    ];

    protected $casts = [
        'trading_date' => 'date',
        'next_trading_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'lock_started_at' => 'datetime',
        'lock_released_at' => 'datetime',
        'summary' => 'array',
        'metadata' => 'array',
    ];

    public function reconciliationReport(): HasOne
    {
        return $this->hasOne(DailyStockReconciliationReport::class, 'trading_day_end_id');
    }
}
