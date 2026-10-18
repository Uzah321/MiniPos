<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\GeneralSettings\Models\PosTill;

class SyncTerminalActivation extends Model
{
    use HasUuids;

    protected $fillable = [
        'sync_node_id',
        'company_id',
        'branch_id',
        'till_id',
        'code_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'code_hash',
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

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }
}
