<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;

class PurchaseOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['order_date' => 'date', 'expected_date' => 'date'];

    public function lines() { return $this->hasMany(PurchaseOrderLine::class); }
    public function receipts() { return $this->hasMany(PurchaseReceipt::class); }
    public function returns() { return $this->hasMany(PurchaseReturn::class); }
    public function invoices() { return $this->hasMany(SupplierInvoice::class); }
}













