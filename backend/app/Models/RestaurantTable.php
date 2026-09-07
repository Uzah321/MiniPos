<?php

namespace App\Models;

use App\Enums\TableStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
