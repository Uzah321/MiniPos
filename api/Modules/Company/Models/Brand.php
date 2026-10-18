<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'country_id', 'code', 'name', 'description',
        'logo_path', 'logo_url', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }
}
