<?php

namespace App\Models;

use App\Enums\CreditNoteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'original_sale_id', 'customer_id', 'status', 'credit_note_number', 'reason',
    'refund_method', 'refund_reference', 'total', 'issued_at', 'reprint_count', 'approved_by',
])]
class CreditNote extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatus::class,
            'total' => 'decimal:4',
            'issued_at' => 'datetime',
            'reprint_count' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
