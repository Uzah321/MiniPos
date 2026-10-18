<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\PurchaseReceiptLineFactory;

class PurchaseReceiptLine extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id'); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'location_id'); }


    public function orderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
