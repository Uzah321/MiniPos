<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Modules\Sales\Models\CreditCustomerLedger;
use Modules\Sales\Models\CreditCustomerPayment;

class CreditCustomer extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'credit_customers';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id', 'branch_id', 'account_number', 'customer_code', 'name', 'trading_name',
        'tax_number', 'phone', 'email', 'billing_address', 'delivery_address', 'credit_limit',
        'opening_balance', 'current_balance', 'available_credit', 'currency_code',
        'payment_terms_days', 'allow_credit_sales', 'requires_po_number', 'is_active', 'status',
        'approved_by', 'approved_at', 'blocked_by', 'blocked_at', 'block_reason', 'created_by',
        'updated_by', 'metadata',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:4',
        'opening_balance' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'available_credit' => 'decimal:4',
        'allow_credit_sales' => 'boolean',
        'requires_po_number' => 'boolean',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
        'blocked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function ledger()
    {
        return $this->hasMany(CreditCustomerLedger::class, 'credit_customer_id');
    }

    public function payments()
    {
        return $this->hasMany(CreditCustomerPayment::class, 'credit_customer_id');
    }
}
