<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\FiscalDevice\Models\FiscalDevice;

class FiscalDay extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'fiscal_device_id',
        'device_id',
        'fiscal_day_no',
        'status',
        'opened_at',
        'closed_at',
        'receipt_counter',
        'open_operation_id',
        'close_operation_id',
        'open_response',
        'close_response',
        'last_status_response',
        'server_signature',
        'closing_error_code',
        'opened_by',
        'closed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'open_response' => 'array',
        'close_response' => 'array',
        'last_status_response' => 'array',
        'server_signature' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(FiscalDevice::class, 'fiscal_device_id');
    }
}
