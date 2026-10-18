<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\GeneralSettings\Models\PosTill;

class SalesTransaction extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sales_transactions';

    protected $fillable = [
        'id',
        'shift_session_id',

        'company_id',
        'branch_id',
        'till_id',
        'shift_id',
        'cashier_id',
        'user_id',

        'invoice_number',
        'client_sale_id',
        'transaction_date',
        'business_date',
        'trading_day_id',
        'document_type',
        'sale_type',
        'status',

        'currency_code',
        'base_currency_code',
        'exchange_rate',

        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',

        'base_subtotal_amount',
        'base_discount_amount',
        'base_tax_amount',
        'base_total_amount',

        'payment_type_id',
        'payment_method',
        'amount_tendered',
        'change_amount',
        'change',

        'customer_name',
        'customer_phone',
        'customer_tax_number',
        'notes',
        'metadata',

        'is_printed',
        'printed_at',
        'printed_by',

        // Credit customer fields. These must be fillable because
        // CreditCustomerService marks receipts as credit after the sale is saved.
        'credit_customer_id',
        'credit_account_number',
        'is_credit_sale',
        'credit_status',
        'credit_amount',
        'credit_paid_amount',
        'credit_balance_amount',
        'credit_due_date',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'business_date' => 'date:Y-m-d',

        'exchange_rate' => 'decimal:8',

        'subtotal_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',

        'base_subtotal_amount' => 'decimal:4',
        'base_discount_amount' => 'decimal:4',
        'base_tax_amount' => 'decimal:4',
        'base_total_amount' => 'decimal:4',

        'amount_tendered' => 'decimal:4',
        'change_amount' => 'decimal:4',
        'change' => 'decimal:4',

        'metadata' => 'array',
        'is_printed' => 'boolean',
        'printed_at' => 'datetime',

        'is_credit_sale' => 'boolean',
        'credit_amount' => 'decimal:4',
        'credit_paid_amount' => 'decimal:4',
        'credit_balance_amount' => 'decimal:4',
        'credit_due_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }

    public function cashier()
    {
        return $this->belongsTo(\Modules\Authentication\Models\PosUser::class, 'cashier_id');
    }

    public function user()
    {
        return $this->belongsTo(\Modules\Authentication\Models\PosUser::class, 'user_id');
    }

    public function printedBy()
    {
        return $this->belongsTo(\Modules\Authentication\Models\PosUser::class, 'printed_by');
    }

    public function paymentType()
    {
        return $this->belongsTo(\Modules\Currency\Models\PaymentType::class, 'payment_type_id');
    }

    public function receiptLines()
    {
        return $this->hasMany(ReceiptLine::class, 'sales_transaction_id');
    }

    public function lines()
    {
        return $this->hasMany(ReceiptLine::class, 'sales_transaction_id');
    }

    public function payments()
    {
        return $this->hasMany(SalesTransactionPayment::class, 'sales_transaction_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeParked($query)
    {
        return $query->where('status', 'parked');
    }

    public function scopeVoided($query)
    {
        return $query->where('status', 'voided');
    }
}
