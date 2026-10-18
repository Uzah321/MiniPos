<?php

namespace Modules\Company\Models;

use App\Models\SyncChange;
use App\Models\SyncNode;
use App\Models\SyncOutboxEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Company\Database\Factories\CompanyFactory;

class Company extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'country_id',
        'company_code',
        'company_name',
        'location_address',
        'city',
        'country',
        'domain',
        'host_ip',
        'license_support_phone',
        'logo',
        'status',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /** Countries the company is approved to trade in, including its home country. */
    public function operatingCountries()
    {
        return $this->hasMany(CompanyCountry::class);
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }

    public function complexes()
    {
        return $this->hasMany(ComplexLocation::class);
    }

    public function branches()
    {
        return $this->hasMany(CompanyBranch::class);
    }

    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    public function activeLicense()
    {
        return $this->hasOne(License::class)
            ->where('status', 'active')
            ->latestOfMany('created_at');
    }

    public function syncNodes()
    {
        return $this->hasMany(SyncNode::class);
    }

    public function syncOutboxEvents()
    {
        return $this->hasMany(SyncOutboxEvent::class);
    }

    public function syncChanges()
    {
        return $this->hasMany(SyncChange::class);
    }
}
