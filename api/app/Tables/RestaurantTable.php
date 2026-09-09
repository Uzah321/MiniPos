<?php

namespace App\Tables;

use App\Kitchen\KitchenOrder;
use App\Models\Store;
use App\Models\User;
use App\Sales\Sale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['store_id', 'label', 'status', 'server_id', 'guest_count'])]
class RestaurantTable extends Model
{
    use HasFactory;

    protected $table = 'tables';

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(User::class, 'server_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'table_id');
    }

    public function kitchenOrders(): HasMany
    {
        return $this->hasMany(KitchenOrder::class, 'table_id');
    }
}
