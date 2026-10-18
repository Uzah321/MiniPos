<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class CreditCustomerNumberSequence extends Model
{
    protected $table = 'credit_customer_number_sequences';

    protected $fillable = [
        'company_id', 'branch_id', 'sequence_type', 'prefix', 'sequence_date', 'last_number',
    ];

    protected $casts = [
        'sequence_date' => 'date',
        'last_number' => 'integer',
    ];
}
