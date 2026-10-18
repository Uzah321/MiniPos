<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Authentication\Models\PosUser;

// use Modules\Product\Database\Factories\ProductPriceAdjustmentFactory;

class ProductPriceAdjustment extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'product_price_id',
        'price_level_id',
        'old_price',
        'new_price',
        'currency',
        'adjustment_type',
        'reason',
        'adjusted_by',
        'adjusted_at',
        'metadata',
    ];

    protected $casts = [
        'old_price' => 'decimal:4',
        'new_price' => 'decimal:4',
        'adjusted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productPrice()
    {
        return $this->belongsTo(ProductPrice::class);
    }

    public function priceLevel()
    {
        return $this->belongsTo(PriceLevel::class);
    }

    public function adjudtedby(){
        return $this->belongsTo(PosUser::class , 'adjusted_by');
    }
}
