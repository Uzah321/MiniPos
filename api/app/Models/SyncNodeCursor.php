<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

class SyncNodeCursor extends Model
{
    use HasUuids;

    protected $fillable = [
        'sync_node_id',
        'company_id',
        'branch_id',
        'stream',
        'cursor',
        'last_event_id',
        'synced_at',
        'metadata',
    ];

    protected $casts = [
        'cursor' => 'integer',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function node()
    {
        return $this->belongsTo(SyncNode::class, 'sync_node_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }
}
