<?php

namespace Modules\ZimraIntegration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZimraGlobalCounter extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'fiscal_device_id',
        'device_id',
        'next_receipt_global_no',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'next_receipt_global_no' => 'integer',
    ];
}
