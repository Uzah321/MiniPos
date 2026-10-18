<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\ProductNumberSequenceFactory;

class ProductNumberSequence extends Model
{
    use HasUuids;

    protected $table = 'product_number_sequences';

    protected $fillable = [
        'company_id',
        'branch_id',
        'type',
        'prefix',
        'current_number',
        'padding',
        'is_active',
    ];

    protected $casts = [
        'current_number' => 'integer',
        'padding' => 'integer',
        'is_active' => 'boolean',
    ];
}
