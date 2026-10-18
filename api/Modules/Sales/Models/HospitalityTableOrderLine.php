<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HospitalityTableOrderLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'table_order_id',
        'product_id',
        'menu_item_channel_id',
        'line_no',
        'line_name',
        'quantity',
        'unit_price',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',
        'metadata',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_subtotal' => 'decimal:4',
        'line_discount' => 'decimal:4',
        'line_tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'metadata' => 'array',
    ];

    protected $appends = [
        // POS clients use the historical request key even though Hospitality
        // prices are sourced from menu_item_channels, not product_prices.
        'product_price_id',
    ];

    public function getProductPriceIdAttribute(): ?string
    {
        return $this->menu_item_channel_id;
    }

    public function order()
    {
        return $this->belongsTo(HospitalityTableOrder::class, 'table_order_id');
    }
}
