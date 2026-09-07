<?php

namespace App\Kitchen;

use App\Items\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kitchen_order_id', 'item_id', 'station', 'status', 'quantity', 'notes', 'is_duplicate'])]
class KitchenOrderLine extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => KitchenOrderStatus::class,
            'quantity' => 'decimal:4',
            'is_duplicate' => 'boolean',
        ];
    }

    public function kitchenOrder(): BelongsTo
    {
        return $this->belongsTo(KitchenOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
