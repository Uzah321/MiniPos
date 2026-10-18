<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\ProductSupplierFactory;

class ProductSupplier extends Model
{
    use HasUuids;

    protected $table = 'product_suppliers';

    protected $fillable = [
        'company_id',
        'product_id',
        'supplier_id',
        'supplier_code',
        'last_purchase_price',
        'is_default',
    ];

    protected $casts = [
        'last_purchase_price' => 'decimal:4',
        'is_default' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
