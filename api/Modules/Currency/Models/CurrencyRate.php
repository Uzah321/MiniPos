<?php

namespace Modules\Currency\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// use Modules\Currency\Database\Factories\CurrencyRateFactory;

class CurrencyRate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'currency_id',
        'rate',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }
}
