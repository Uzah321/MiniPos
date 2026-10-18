<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HospitalityTableOrder extends Model
{
    use HasUuids;

    public const ACTIVE_STATUSES = ['open', 'hanging', 'processing'];

    public const TERMINAL_STATUSES = ['completed', 'cleared'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'shop_id',
        'till_id',
        'last_till_id',
        'hospitality_table_id',
        'active_table_id',
        'client_request_id',
        'order_number',
        'order_sequence',
        'order_business_date',
        'trading_day_id',
        'service_type',
        'status',
        'revision',
        'content_revision',
        'kitchen_status',
        'last_kitchen_content_revision',
        'guest_count',
        'display_label',
        'notes',
        'cart_hash',
        'currency_code',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'opened_by',
        'last_updated_by',
        'sales_transaction_id',
        'cleared_by',
        'manager_approval_id',
        'clear_reason',
        'opened_at',
        'last_activity_at',
        'completed_at',
        'cleared_at',
        'kitchen_sent_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'order_sequence' => 'integer',
        'order_business_date' => 'date:Y-m-d',
        'content_revision' => 'integer',
        'last_kitchen_content_revision' => 'integer',
        'guest_count' => 'integer',
        'subtotal_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'opened_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'completed_at' => 'datetime',
        'cleared_at' => 'datetime',
        'kitchen_sent_at' => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(HospitalityTable::class, 'hospitality_table_id');
    }

    public function lines()
    {
        return $this->hasMany(HospitalityTableOrderLine::class, 'table_order_id')->orderBy('line_no');
    }

    public function events()
    {
        return $this->hasMany(HospitalityTableOrderEvent::class, 'table_order_id');
    }

    public function sale()
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }
}
