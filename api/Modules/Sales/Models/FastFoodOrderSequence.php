<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FastFoodOrderSequence extends Model
{
    use HasUuids;

    protected $table = 'fast_food_order_sequences';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'trading_day_id',
        'business_date',
        'prefix',
        'start_number',
        'start_minimum',
        'start_maximum',
        'number_width',
        'last_number',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'start_number' => 'integer',
        'start_minimum' => 'integer',
        'start_maximum' => 'integer',
        'number_width' => 'integer',
        'last_number' => 'integer',
    ];
}
