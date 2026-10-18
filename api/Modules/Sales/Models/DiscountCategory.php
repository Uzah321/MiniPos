<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\ProductCategory;

class DiscountCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'discount_id',
        'category_id',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'id')
            ->withTrashed();
    }
}
