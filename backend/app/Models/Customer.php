<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'phone', 'email', 'loyalty_id', 'account_status', 'account_credit_limit',
    'store_credit_balance', 'loyalty_points_balance',
])]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'account_credit_limit' => 'decimal:4',
            'store_credit_balance' => 'decimal:4',
            'loyalty_points_balance' => 'integer',
        ];
    }
}
