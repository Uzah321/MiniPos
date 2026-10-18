<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\SupplierInvoiceLineFactory;

class SupplierInvoiceLine extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function invoice() { return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
