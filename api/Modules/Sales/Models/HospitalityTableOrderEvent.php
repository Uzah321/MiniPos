<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HospitalityTableOrderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'table_order_id',
        'till_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'manager_approval_id',
        'command_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
