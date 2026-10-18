<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\ProductDiscountFactory;

class ProductDiscount extends Model
{
    use HasUuids;

    protected $table = 'product_discounts';

    protected $fillable = [
        'company_id',
        'product_id',
        'discount_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }
}
