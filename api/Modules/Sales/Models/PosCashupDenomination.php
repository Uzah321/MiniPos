<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashupDenomination extends Model
{
    protected $table = 'pos_cashup_denominations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'cashup_id',
        'currency_code',
        'denomination_value',
        'quantity',
        'line_total',
        'denomination_type',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'denomination_value' => 'decimal:4',
        'line_total' => 'decimal:4',
        'quantity' => 'integer',
        'metadata' => 'array',
    ];

    public function cashup()
    {
        return $this->belongsTo(PosCashup::class, 'cashup_id');
    }
}
