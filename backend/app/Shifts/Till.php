<?php

namespace App\Shifts;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['shift_id', 'opening_float', 'closing_count', 'variance', 'status', 'manager_verified_by'])]
class Till extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TillStatus::class,
            'opening_float' => 'decimal:4',
            'closing_count' => 'decimal:4',
            'variance' => 'decimal:4',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function managerVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_verified_by');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
