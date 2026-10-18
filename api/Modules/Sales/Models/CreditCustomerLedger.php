<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CreditCustomerLedger extends Model
{
    use HasUuids;

    protected $table = 'credit_customer_ledger';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id', 'branch_id', 'credit_customer_id', 'sales_transaction_id', 'receipt_line_id',
        'payment_id', 'shift_session_id', 'till_id', 'cashier_id', 'entry_type', 'document_number',
        'document_date', 'due_date', 'debit_amount', 'credit_amount', 'balance_after', 'currency_code',
        'base_currency_code', 'exchange_rate', 'reference', 'notes', 'metadata', 'created_by',
    ];

    protected $casts = [
        'document_date' => 'date',
        'due_date' => 'date',
        'debit_amount' => 'decimal:4',
        'credit_amount' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'metadata' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(CreditCustomer::class, 'credit_customer_id');
    }
}
