<?php

namespace App\Discounts;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'discount_type', 'discount_value', 'usage_limit', 'times_used', 'active', 'starts_at', 'ends_at'])]
class Coupon extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
