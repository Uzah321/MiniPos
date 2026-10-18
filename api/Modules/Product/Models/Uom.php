<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\UomFactory;

class Uom extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'uoms';

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'quantity_type',
        'decimal_places',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeAvailableTo(Builder $query, string $companyId): Builder
    {
        return $query->where(function (Builder $scope) use ($companyId): void {
            $scope->whereNull('company_id')
                ->orWhere('company_id', $companyId);
        });
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'base_uom_id');
    }
}
