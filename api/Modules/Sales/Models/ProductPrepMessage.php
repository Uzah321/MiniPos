<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrepMessage extends Model
{
    protected $table = 'product_prep_messages';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'brand_id', 'branch_id', 'message', 'code', 'allow_free_text', 'is_active',
    ];

    protected $casts = [
        'allow_free_text' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function assignments()
    {
        return $this->hasMany(ProductPrepMessageAssignment::class, 'prep_message_id');
    }
}
