<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;

class DiscountProduct extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'discount_id',
        'product_id',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id')
            ->withTrashed();
    }
}
