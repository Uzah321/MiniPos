<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\ProductTaxeFactory;

class ProductTaxe extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'tax_id',
        'is_inclusive',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_inclusive' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected $appends = [
        'fdms_tax_id',
        'tax_name',
        'tax_code',
        'tax_rate',
        'obliged_to_zimra',
    ];

    public function tax()
    {
        return $this->belongsTo(Taxe::class, 'tax_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id')->withTrashed();
    }

    public function getFdmsTaxIdAttribute()
    {
        return $this->tax?->tax_id;
    }

    public function getTaxNameAttribute()
    {
        return $this->tax?->name;
    }

    public function getTaxCodeAttribute()
    {
        return $this->tax?->tax_code;
    }

    public function getTaxRateAttribute()
    {
        return $this->tax?->rate;
    }

    public function getObligedToZimraAttribute()
    {
        return (bool) ($this->tax?->obliged_to_zimra ?? false);
    }
}

