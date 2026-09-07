<?php

namespace App\Terminals;

use App\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'store_id', 'registration_code', 'name', 'status', 'app_version', 'last_seen_at',
    'peripheral_status', 'peripheral_checked_at',
])]
class Terminal extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TerminalStatus::class,
            'last_seen_at' => 'datetime',
            'peripheral_status' => 'array',
            'peripheral_checked_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
