<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuotationItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id', 'quotation_id', 'product_id', 'product_price_id', 'price_level_id', 'category_id',
        'product_name', 'product_code', 'sku', 'currency', 'quantity', 'base_unit_price', 'unit_price',
        'has_price_override', 'price_override_level_id', 'price_override_approved_by', 'price_override_approval_id',
        'price_override_payload', 'gross_amount', 'discount_amount', 'tax_amount', 'subtotal_excl_tax',
        'inclusive_tax_total', 'exclusive_tax_total', 'tax_total', 'tax_details', 'net_amount',
        'discount_id', 'discount_type_id', 'discount_code', 'discount_name', 'discount_basis', 'discount_value',
        'discount_approved_by', 'discount_approval_id', 'discount_payload', 'taxable', 'tax_rate', 'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'base_unit_price' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'has_price_override' => 'boolean',
        'price_override_payload' => 'array',
        'gross_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'subtotal_excl_tax' => 'decimal:4',
        'inclusive_tax_total' => 'decimal:4',
        'exclusive_tax_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'tax_details' => 'array',
        'net_amount' => 'decimal:4',
        'discount_value' => 'decimal:4',
        'discount_payload' => 'array',
        'taxable' => 'boolean',
        'tax_rate' => 'decimal:6',
        'metadata' => 'array',
    ];

    protected $appends = ['sale_product_payload'];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function getSaleProductPayloadAttribute(): array
    {
        return [
            'product_id' => $this->product_id,
            'product_price_id' => $this->product_price_id,
            'price_level_id' => $this->price_level_id,
            'category_id' => $this->category_id,
            'product_name' => $this->product_name,
            'name' => $this->product_name,
            'product_code' => $this->product_code,
            'code' => $this->product_code,
            'sku' => $this->sku,
            'quantity' => (float) $this->quantity,
            'base_unit_price' => (float) $this->base_unit_price,
            'unit_price' => (float) $this->unit_price,
            'price' => (float) $this->unit_price,
            'has_price_override' => (bool) $this->has_price_override,
            'price_override_level_id' => $this->price_override_level_id,
            'price_override_payload' => $this->price_override_payload,
            'price_level_override' => $this->price_override_payload,
            'gross_amount' => (float) $this->gross_amount,
            'discount_amount' => (float) $this->discount_amount,
            'discount_payload' => $this->discount_payload,
            'discount_id' => $this->discount_id,
            'discount_code' => $this->discount_code,
            'discount_name' => $this->discount_name,
            'discount_basis' => $this->discount_basis,
            'discount_value' => (float) $this->discount_value,
            'taxable' => (bool) $this->taxable,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'tax_total' => (float) ($this->tax_total ?: $this->tax_amount),
            'inclusive_tax_total' => (float) $this->inclusive_tax_total,
            'exclusive_tax_total' => (float) $this->exclusive_tax_total,
            'subtotal_excl_tax' => (float) $this->subtotal_excl_tax,
            'tax_details' => $this->tax_details ?: data_get($this->metadata, 'tax_details', []),
            'line_total' => (float) $this->net_amount,
            'net_amount' => (float) $this->net_amount,
            'metadata' => $this->metadata,
            'quotation_item_id' => $this->id,
            'quotation_id' => $this->quotation_id,
        ];
    }
}
