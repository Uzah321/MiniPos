<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'iso_code', 'iso3_code', 'name', 'currency_code', 'timezone', 'status', 'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }
}
