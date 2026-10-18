<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Product\Models\Product;


// use Modules\Product\Database\Factories\PurchaseOrderLineFactory;

class PurchaseOrderLine extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['tax_details' => 'array'];

    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
