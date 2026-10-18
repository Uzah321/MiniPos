<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class TradingDay extends Model
{
    protected $table = 'trading_days';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','company_id','branch_id','trading_date','status','opened_at','day_end_started_at',
        'closed_at','day_end_started_by','closed_by','lock_token','notes','metadata'
    ];

    protected $casts = [
        'trading_date' => 'date',
        'opened_at' => 'datetime',
        'day_end_started_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
