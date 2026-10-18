<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

class SyncChange extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'event_id',
        'company_id',
        'branch_id',
        'source_node_id',
        'resource_type',
        'resource_id',
        'operation',
        'schema_version',
        'payload',
        'payload_hash',
        'occurred_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'schema_version' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
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
