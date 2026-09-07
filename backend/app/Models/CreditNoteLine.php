<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['credit_note_id', 'sale_line_id', 'item_id', 'quantity', 'condition', 'amount', 'tax_amount', 'disposition'])]
class CreditNoteLine extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
