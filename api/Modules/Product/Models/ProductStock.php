<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Company\Models\CompanyBranch;

// use Modules\Product\Database\Factories\ProductStockFactory;

class ProductStock extends Model
{
    use HasUuids;

    protected $table = 'product_stocks';

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'quantity_on_hand',
        'reserved_quantity',
        'available_quantity',
        'stock_area'
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'available_quantity' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }
}
