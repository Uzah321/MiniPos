<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\TaxeFactory;

class Taxe extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'taxes';

    protected $fillable = [
        'company_id',
        'name',
        'tax_code',
        'tax_id',
        'type',
        'rate',
        'obliged_to_zimra',
        'is_active',
        'valid_from',
        'valid_till',
        'description',
    ];

    protected $casts = [
        'tax_id' => 'integer',
        'rate' => 'decimal:4',
        'obliged_to_zimra' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_till' => 'date',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'tax_id');
    }


    protected $appends = [
        'fdms_tax_id',
        'display_name',
    ];

    public function getFdmsTaxIdAttribute()
    {
        return $this->tax_id;
    }

    public function getDisplayNameAttribute(): string
    {
        $code = $this->tax_code ? " ({$this->tax_code})" : '';
        $fdms = $this->tax_id !== null ? " FDMS:{$this->tax_id}" : '';

        return "{$this->name}{$code}{$fdms}";
    }

    public function productTaxes()
    {
        return $this->hasMany(ProductTaxe::class, 'tax_id');
    }


}
