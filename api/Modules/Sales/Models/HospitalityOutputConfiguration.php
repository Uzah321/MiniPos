<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HospitalityOutputConfiguration extends Model
{
    use HasUuids;

    protected $table = 'hospitality_output_configurations';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'mode',
        'auto_print',
        'copies',
        'dispatch_on',
        'version',
        'configured_by',
    ];

    protected $casts = [
        'auto_print' => 'boolean',
        'copies' => 'integer',
        'version' => 'integer',
    ];
}
