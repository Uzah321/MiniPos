<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Taxe;

class ReceiptLineTaxe extends Model
{
    use HasUuids;

    protected $table = 'receipt_line_taxes';

    protected $fillable = [
        'id',

        'company_id',
        'branch_id',

        'sales_transaction_id',
        'receipt_line_id',

        'tax_id',
        'tax_code',
        'tax_percent',

        'is_inclusive',
        'obliged_to_zimra',

        'tax_amount',
        'sales_amount_with_tax',

        'currency_code',
        'metadata',
    ];

    protected $casts = [
        'tax_percent' => 'decimal:4',
        'is_inclusive' => 'boolean',
        'obliged_to_zimra' => 'boolean',

        'tax_amount' => 'decimal:4',
        'sales_amount_with_tax' => 'decimal:4',

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

    public function receiptLine()
    {
        return $this->belongsTo(ReceiptLine::class, 'receipt_line_id');
    }

    public function line()
    {
        return $this->belongsTo(ReceiptLine::class, 'receipt_line_id');
    }

    public function tax()
    {
        return $this->belongsTo(Taxe::class, 'tax_id');
    }

    public function scopeForSale($query, string $saleId)
    {
        return $query->where('sales_transaction_id', $saleId);
    }

    public function scopeForLine($query, string $lineId)
    {
        return $query->where('receipt_line_id', $lineId);
    }

    public function scopeInclusive($query)
    {
        return $query->where('is_inclusive', true);
    }

    public function scopeExclusive($query)
    {
        return $query->where('is_inclusive', false);
    }
}
