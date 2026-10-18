<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

class SyncInboxReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'sync_node_id',
        'company_id',
        'branch_id',
        'payload_hash',
        'status',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
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
