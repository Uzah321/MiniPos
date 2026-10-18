<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KitchenPrintJob extends Model
{
    use HasUuids;

    protected $table = 'kitchen_print_jobs';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'sales_transaction_id',
        'hospitality_table_order_id',
        'dispatch_revision',
        'dispatch_key',
        'command_id',
        'ticket_kind',
        'order_number',
        'target_printing',
        'idempotency_key',
        'printer_id',
        'printer_name',
        'status',
        'attempt_count',
        'last_attempt_at',
        'payload',
        'printed_at',
        'failed_at',
        'error_message',
        'acknowledged_by',
        'metadata',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'attempt_count' => 'integer',
        'dispatch_revision' => 'integer',
        'last_attempt_at' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
