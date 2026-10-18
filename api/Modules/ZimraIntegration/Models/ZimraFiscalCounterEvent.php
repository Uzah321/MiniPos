<?php

namespace Modules\ZimraIntegration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZimraFiscalCounterEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'fiscal_device_id',
        'fiscal_day_id',
        'zimra_receipt_id',
        'sales_transaction_id',
        'receipt_type',
        'receipt_currency',
        'fiscal_counter_type',
        'fiscal_counter_currency',
        'fiscal_counter_tax_id',
        'fiscal_counter_tax_percent',
        'fiscal_counter_money_type',
        'fiscal_counter_value',
        'metadata',
    ];

    protected $casts = [
        'fiscal_counter_tax_id' => 'integer',
        'fiscal_counter_tax_percent' => 'decimal:4',
        'fiscal_counter_value' => 'decimal:4',
        'metadata' => 'array',
    ];
}
