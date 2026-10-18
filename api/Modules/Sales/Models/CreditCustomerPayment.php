<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditCustomerPayment extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'credit_customer_payments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'credit_customer_id',
        'till_id',
        'shift_session_id',
        'cashier_id',
        'payment_type_id',
        'payment_number',
        'payment_name',
        'money_code',
        'amount',
        'base_amount',
        'currency_code',
        'base_currency_code',
        'exchange_rate',
        'reference',
        'approval_code',
        'card_last_four',
        'notes',
        'status',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'metadata' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(CreditCustomer::class, 'credit_customer_id');
    }
}
