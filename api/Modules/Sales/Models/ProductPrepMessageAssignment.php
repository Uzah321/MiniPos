<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Product\Models\Product;

class ProductPrepMessageAssignment extends Model
{
    protected $table = 'product_prep_message_assignments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'brand_id', 'branch_id', 'product_id', 'prep_message_id', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function prepMessage()
    {
        return $this->belongsTo(ProductPrepMessage::class, 'prep_message_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
