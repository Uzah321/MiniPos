<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Country extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code',
        'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    public function complexes()
    {
        return $this->hasMany(ComplexLocation::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }
}
