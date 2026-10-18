<?php

namespace Modules\Product\Models;

use App\Services\Enterprise\BranchMenuCatalogueService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sales\Models\Discount;
use Modules\Company\Models\CompanyBranch;


class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        // A deleted product releases its barcodes so they can be reused.
        static::deleted(function (Product $product): void {
            if (! $product->isForceDeleting()) {
                $product->barcodes()->get()->each->delete();
            }
        });

        // Keep a Back Office menu item on its shop's till menu.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(function (Product $product): void {
                if (filled($product->branch_id) && ! $product->isForceDeleting()) {
                    app(BranchMenuCatalogueService::class)->syncProduct((string) $product->getKey());
                }
            });
        }
    }

    protected $fillable = [
        'company_id',
        'branch_id',
        'is_global',
        'category_id',
        'supplier_id',
        'parent_product_id',
        'base_uom_id',
        'name',
        'code',
        'sku',
        'barcode',
        'description',
        'hs_code',
        'product_type',
        'stock_tracking_enabled',
        'batch_tracking_enabled',
        'expiry_tracking_enabled',
        'default_shelf_life_days',
        'allow_negative_stock',
        'allow_discount',
        'taxable',
        'tax_id',
        'reorder_level',
        'minimum_stock',
        'maximum_stock',
        'is_active',
        'sub_category_id',
        'stock_usage_type',
        'default_issue_destination',
        'expense_account_code',
        'expense_category',
        'image_path',
        'image_url',
    ];

    protected $casts = [
        'stock_tracking_enabled' => 'boolean',
        'is_global' => 'boolean',
        'batch_tracking_enabled' => 'boolean',
        'expiry_tracking_enabled' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'allow_discount' => 'boolean',
        'taxable' => 'boolean',
        'is_active' => 'boolean',
        'reorder_level' => 'decimal:4',
        'minimum_stock' => 'decimal:4',
        'maximum_stock' => 'decimal:4',

    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(ProductSubCategory::class, 'sub_category_id');
    }
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function parent()
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function children()
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    /**
     * Legacy single tax relationship.
     * Keep this only if products.tax_id is still used anywhere old.
     */
    public function tax()
    {
        return $this->belongsTo(Taxe::class, 'tax_id', 'id');
    }

    /**
     * Real new relationship.
     * One product can have many tax assignments.
     */
    public function productTaxes()
    {
        return $this->hasMany(ProductTaxe::class, 'product_id', 'id');
    }

    /**
     * Alias used when loading product taxes with tax details.
     * Example: Product::with('taxes.tax')
     */
    public function taxes()
    {
        return $this->hasMany(ProductTaxe::class, 'product_id', 'id');
    }

    /**
     * Only active product taxes.
     */
    public function activeTaxes()
    {
        return $this->hasMany(ProductTaxe::class, 'product_id', 'id')
            ->where('is_active', true)
            ->with('tax');
    }

    /**
     * Only taxes that must go to ZIMRA later.
     */
    public function zimraTaxes()
    {
        return $this->hasMany(ProductTaxe::class, 'product_id', 'id')
            ->where('is_active', true)
            ->whereHas('tax', function ($query) {
                $query->where('obliged_to_zimra', true)
                    ->where('is_active', true);
            })
            ->with('tax');
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function costs()
    {
        return $this->hasMany(ProductCost::class);
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function suppliers()
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function discounts()
    {
        return $this->belongsToMany(Discount::class, 'product_discounts');
    }

    public function parentBreakRules()
    {
        return $this->hasMany(ProductBreakRule::class, 'parent_product_id');
    }

    public function childBreakRule()
    {
        return $this->hasOne(ProductBreakRule::class, 'child_product_id');
    }
}
