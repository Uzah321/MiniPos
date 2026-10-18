<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalityTable extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'shop_id',
        'code',
        'name',
        'section',
        'table_type',
        'capacity',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function activeOrder()
    {
        return $this->hasOne(HospitalityTableOrder::class, 'active_table_id');
    }
}
