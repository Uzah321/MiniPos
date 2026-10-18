<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PosCommandReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_action_id',
        'company_id',
        'branch_id',
        'till_id',
        'user_id',
        'command_type',
        'request_hash',
        'status',
        'response_status',
        'response_content_type',
        'response_body',
        'completed_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'completed_at' => 'datetime',
    ];
}
