<?php

namespace App\Items;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sku', 'barcode', 'name', 'price', 'tax_rate', 'active'])]
class Item extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'active' => 'boolean',
        ];
    }
}
