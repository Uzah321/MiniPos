<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One country a company is approved to trade in (its operating footprint). */
class CompanyCountry extends Model
{
    use HasUuids;

    protected $table = 'company_countries';

    protected $fillable = [
        'company_id',
        'country_id',
        'is_home',
        'status',
        'metadata',
    ];

    protected $casts = [
        'is_home' => 'boolean',
        'metadata' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
