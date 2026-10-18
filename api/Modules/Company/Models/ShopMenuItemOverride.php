<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;

class ShopMenuItemOverride extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'brand_id', 'shop_id', 'product_id', 'is_available',
        'channel_code', 'channel_key', 'starts_at', 'ends_at', 'reason', 'configuration_version',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'configuration_version' => 'integer',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
