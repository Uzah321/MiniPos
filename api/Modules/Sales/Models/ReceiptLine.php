<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReceiptLine extends Model
{
    use HasUuids;

    protected $table = 'receipt_lines';

    protected $fillable = [
        'id',

        'company_id',
        'branch_id',
        'sales_transaction_id',

        'product_id',
        'product_price_id',
        'price_level_id',

        'line_no',
        'line_name',
        'product_code',
        'sku',
        'barcode',
        'hs_code',

        'line_quantity',
        'line_price',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',

        'base_line_price',
        'base_line_subtotal',
        'base_line_discount',
        'base_line_tax',
        'base_line_total',

        'currency_code',
        'base_currency_code',

        'metadata',
    ];

    protected $casts = [
        'line_no' => 'integer',

        'line_quantity' => 'decimal:4',
        'line_price' => 'decimal:4',
        'line_subtotal' => 'decimal:4',
        'line_discount' => 'decimal:4',
        'line_tax' => 'decimal:4',
        'line_total' => 'decimal:4',

        'base_line_price' => 'decimal:4',
        'base_line_subtotal' => 'decimal:4',
        'base_line_discount' => 'decimal:4',
        'base_line_tax' => 'decimal:4',
        'base_line_total' => 'decimal:4',

        'metadata' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }

    public function salesTransaction()
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }

    public function product()
    {
        return $this->belongsTo(\Modules\Product\Models\Product::class, 'product_id');
    }

    public function productPrice()
    {
        return $this->belongsTo(\Modules\Product\Models\ProductPrice::class, 'product_price_id');
    }

    public function priceLevel()
    {
        return $this->belongsTo(\Modules\Product\Models\PriceLevel::class, 'price_level_id');
    }

    public function taxes()
    {
        return $this->hasMany(ReceiptLineTaxe::class, 'receipt_line_id');
    }

    public function scopeForSale($query, string $saleId)
    {
        return $query->where('sales_transaction_id', $saleId);
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function getDisplayNameAttribute()
    {
        return $this->line_name ?: $this->product?->name;
    }
}
