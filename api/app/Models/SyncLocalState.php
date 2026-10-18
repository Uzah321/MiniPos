<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

class SyncLocalState extends Model
{
    use HasUuids;

    protected $fillable = [
        'node_id',
        'company_id',
        'branch_id',
        'shop_id',
        'till_id',
        'cloud_url',
        'credential',
        'local_control_token_hash',
        'push_cursor',
        'pull_cursor',
        'last_sync_at',
        'last_push_at',
        'last_pull_at',
        'last_error_at',
        'last_error',
        'status',
        'metadata',
    ];

    protected $casts = [
        'credential' => 'encrypted',
        'push_cursor' => 'integer',
        'pull_cursor' => 'integer',
        'last_sync_at' => 'datetime',
        'last_push_at' => 'datetime',
        'last_pull_at' => 'datetime',
        'last_error_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'credential',
        'local_control_token_hash',
    ];

    public function node()
    {
        return $this->belongsTo(SyncNode::class, 'node_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }
}
