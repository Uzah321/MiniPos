<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\PurchaseReturnLineFactory;

class PurchaseReturnLine extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    public function purchaseReturn() { return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
