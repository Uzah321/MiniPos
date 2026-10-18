<?php

namespace Modules\Product\Models;

use App\Services\Enterprise\BranchMenuCatalogueService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\ProductPriceFactory;

class ProductPrice extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'product_prices';

    protected static function booted(): void
    {
        // A Back Office menu item's till price follows its selling price.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(function (ProductPrice $price): void {
                app(BranchMenuCatalogueService::class)->syncProduct((string) $price->product_id);
            });
        }
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'branch_id',
        'price_level_id',
        'price',
        'currency',
        'is_default',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function priceLevel()
    {
        return $this->belongsTo(PriceLevel::class);
    }





    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class, 'branch_id');
    }
}
