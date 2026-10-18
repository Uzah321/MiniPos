<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileSaleReconciliation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'business_date' => 'date:Y-m-d',
        'command_payload' => 'array',
        'context' => 'array',
    ];
}
