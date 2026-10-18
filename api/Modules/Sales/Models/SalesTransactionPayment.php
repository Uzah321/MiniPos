<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\GeneralSettings\Models\PosTill;

class SalesTransactionPayment extends Model
{
    use HasUuids;

    protected $table = 'sales_transaction_payments';

    protected $fillable = [
        'id',

        'company_id',
        'branch_id',
        'till_id',
        'shift_id',

        'sales_transaction_id',
        'payment_type_id',

        'payment_name',
        'money_code',

        'currency_code',
        'base_currency_code',
        'exchange_rate',

        'amount',
        'base_amount',
        'amount_tendered',
        'change_amount',

        'reference',
        'approval_code',
        'card_last_four',

        'metadata',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:8',

        'amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'amount_tendered' => 'decimal:4',
        'change_amount' => 'decimal:4',

        'metadata' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }

    public function salesTransaction()
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }

    public function paymentType()
    {
        return $this->belongsTo(\Modules\Currency\Models\PaymentType::class, 'payment_type_id');
    }

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class, 'company_id');
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class, 'branch_id');
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }

    public function scopeForSale($query, string $saleId)
    {
        return $query->where('sales_transaction_id', $saleId);
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }
}
