<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'pos_user_id',
        'created_by',
        'approved_by',
        'quote_code',
        'customer_name',
        'customer_phone',
        'customer_email',
        'currency',
        'sub_total',
        'discount_total',
        'tax_total',
        'grand_total',
        'valid_until',
        'status',
        'notes',
        'metadata',
        'converted_at',
        'converted_sale_id',
    ];

    protected $casts = [
        'sub_total' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'valid_until' => 'datetime',
        'converted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }
}
