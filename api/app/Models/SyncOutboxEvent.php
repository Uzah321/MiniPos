<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

class SyncOutboxEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'source_node_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'deduplication_key',
        'schema_version',
        'payload',
        'payload_hash',
        'occurred_at',
        'available_at',
        'attempt_count',
        'last_attempt_at',
        'last_error',
        'delivered_at',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'available_at' => 'datetime',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function sourceNode()
    {
        return $this->belongsTo(SyncNode::class, 'source_node_id');
    }
}
