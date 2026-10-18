<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompanyClockGuard extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'last_seen_at',
        'last_seen_timezone',
        'last_seen_source',
        'rollback_tolerance_minutes',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'rollback_tolerance_minutes' => 'integer',
    ];
}
