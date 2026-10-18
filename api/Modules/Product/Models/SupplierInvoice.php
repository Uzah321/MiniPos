<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\SupplierInvoiceFactory;

class SupplierInvoice extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date'];

    public function lines() { return $this->hasMany(SupplierInvoiceLine::class); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
}

