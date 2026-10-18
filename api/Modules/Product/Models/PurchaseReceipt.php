<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\PurchaseReceiptFactory;

class PurchaseReceipt extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['received_date' => 'date'];

    public function lines() { return $this->hasMany(PurchaseReceiptLine::class); }
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class, 'branch_id'); }
}
