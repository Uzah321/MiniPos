<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

// use Modules\Product\Database\Factories\ProductCategoryFactory;

class ProductCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'brand_id',
        'parent_id',
        'name',
        'code',
        'description',
        'is_active',
        'image_path',
        'image_url',
        'banner_image_path',
        'banner_image_url',
        'display_area',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }

    public function subCategories()
    {
        return $this->hasMany(ProductSubCategory::class, 'category_id');
    }
}
