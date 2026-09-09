<?php

namespace App\Kitchen;

use App\Sales\Sale;
use App\Tables\RestaurantTable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sale_id', 'table_id', 'status'])]
class KitchenOrder extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => KitchenOrderStatus::class,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitchenOrderLine::class);
    }
}
