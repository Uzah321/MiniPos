<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileFiscalFileConfirmation extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'branch_id', 'till_id', 'sync_node_id', 'device_id',
        'fiscal_day_no', 'file_sequence', 'file_sha256', 'operation_id',
        'receipt_hashes', 'get_file_status',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'fiscal_day_no' => 'integer',
        'file_sequence' => 'integer',
        'receipt_hashes' => 'array',
        'get_file_status' => 'array',
    ];
}
