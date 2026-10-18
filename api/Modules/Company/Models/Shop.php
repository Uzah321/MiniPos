<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\GeneralSettings\Models\PosTill;

class Shop extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id',
        'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode',
        'configuration_version', 'status', 'metadata',
    ];

    protected $casts = [
        'configuration_version' => 'integer',
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

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function complex()
    {
        return $this->belongsTo(ComplexLocation::class, 'complex_id');
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function tills()
    {
        return $this->hasMany(PosTill::class);
    }

    public function menuOverrides()
    {
        return $this->hasMany(ShopMenuItemOverride::class);
    }
}
