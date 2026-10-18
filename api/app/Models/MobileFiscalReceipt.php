<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileFiscalReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'branch_id', 'till_id', 'sync_node_id',
        'mobile_fiscal_device_id', 'certificate_sha256', 'certificate_pem',
        'sales_transaction_id', 'client_sale_id',
        'device_id', 'fiscal_day_no', 'receipt_counter', 'receipt_global_no',
        'invoice_no', 'receipt_currency', 'receipt_total', 'previous_receipt_hash',
        'receipt_hash', 'receipt_signature', 'qr_string', 'payload', 'status',
        'fdms_receipt_id', 'operation_id', 'file_sequence', 'fdms_response',
        'device_reported_submitted_at',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'fiscal_day_no' => 'integer',
        'receipt_counter' => 'integer',
        'receipt_global_no' => 'integer',
        'file_sequence' => 'integer',
        'receipt_total' => 'decimal:2',
        'payload' => 'array',
        'fdms_response' => 'array',
        'device_reported_submitted_at' => 'datetime',
    ];

    protected $hidden = ['certificate_pem'];

    public function device()
    {
        return $this->belongsTo(MobileFiscalDevice::class, 'mobile_fiscal_device_id');
    }
}
