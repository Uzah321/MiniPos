<?php

namespace Modules\Currency\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

// use Modules\Currency\Database\Factories\PaymentTypeFactory;

class PaymentType extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'payment_types';

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'money_code',
        'type',
        'supportsMultiCurrency',
        'requiresAmountTendered',
        'default_currency_code',
        'integration_status',
        'integration_number',
        'is_active',
    ];

    protected $casts = [
        'supportsMultiCurrency' => 'boolean',
        'requiresAmountTendered' => 'boolean',
        'integration_status' => 'boolean',
        'is_active' => 'boolean',
        'integration_number' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }
}
