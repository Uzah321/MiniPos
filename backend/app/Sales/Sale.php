<?php

namespace App\Sales;

use App\CreditNotes\CreditNote;
use App\Customers\Customer;
use App\Discounts\Coupon;
use App\Kitchen\KitchenOrder;
use App\Models\Store;
use App\Models\User;
use App\Payments\Payment;
use App\Shifts\Shift;
use App\Tables\RestaurantTable;
use App\Terminals\Terminal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'terminal_id', 'shift_id', 'customer_id', 'table_id', 'cashier_id',
    'status', 'subtotal', 'discount_total', 'tax_total', 'total', 'completed_at',
    'receipt_number', 'receipt_issued_at', 'reprint_count',
    'coupon_id', 'coupon_discount_amount', 'loyalty_discount_amount',
    'loyalty_points_earned', 'loyalty_points_redeemed',
])]
class Sale extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'completed_at' => 'datetime',
            'receipt_issued_at' => 'datetime',
            'reprint_count' => 'integer',
            'coupon_discount_amount' => 'decimal:4',
            'loyalty_discount_amount' => 'decimal:4',
            'loyalty_points_earned' => 'integer',
            'loyalty_points_redeemed' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'original_sale_id');
    }

    public function kitchenOrders(): HasMany
    {
        return $this->hasMany(KitchenOrder::class);
    }
}
