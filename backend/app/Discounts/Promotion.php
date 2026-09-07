<?php

namespace App\Discounts;

use App\Items\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'min_quantity', 'discount_type', 'discount_value', 'active', 'starts_at', 'ends_at'])]
class Promotion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
