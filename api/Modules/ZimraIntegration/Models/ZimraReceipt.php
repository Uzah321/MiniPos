<?php

namespace Modules\ZimraIntegration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZimraReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'fiscal_device_id',
        'fiscal_day_id',
        'sales_transaction_id',
        'device_id',
        'fiscal_day_no',
        'receipt_counter',
        'receipt_global_no',
        'invoice_no',
        'receipt_type',
        'receipt_currency',
        'receipt_hash',
        'receipt_signature',
        'fdms_receipt_id',
        'operation_id',
        'qr_url',
        'qr_code_data',
        'qr_string',
        'payload',
        'response',
        'status',
        'error',
        'submitted_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'submitted_at' => 'datetime',
    ];
}
