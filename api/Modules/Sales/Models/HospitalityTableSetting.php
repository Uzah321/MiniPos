<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HospitalityTableSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'shop_id',
        'assignment_mode',
        'require_table_for_dine_in',
        'allow_cross_till_recall',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'require_table_for_dine_in' => 'boolean',
        'allow_cross_till_recall' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
