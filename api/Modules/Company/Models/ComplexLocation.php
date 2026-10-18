<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplexLocation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'complexes';

    protected $fillable = [
        'company_id', 'country_id', 'code', 'name', 'address',
        'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
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

    public function shops()
    {
        return $this->hasMany(Shop::class, 'complex_id');
    }
}
