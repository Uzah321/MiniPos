<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

class SyncNode extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'shop_id',
        'till_id',
        'node_code',
        'node_name',
        'deployment_mode',
        'activation_code_hash',
        'activation_expires_at',
        'activated_at',
        'credential_hash',
        'credential_secret',
        'credential_version',
        'status',
        'last_seen_at',
        'last_push_at',
        'last_pull_at',
        'app_version',
        'metadata',
        'created_by',
        'revoked_at',
    ];

    protected $casts = [
        'activation_expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'credential_secret' => 'encrypted',
        'credential_version' => 'integer',
        'last_seen_at' => 'datetime',
        'last_push_at' => 'datetime',
        'last_pull_at' => 'datetime',
        'metadata' => 'array',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'activation_code_hash',
        'credential_hash',
        'credential_secret',
    ];

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

    public function localState()
    {
        return $this->hasOne(SyncLocalState::class, 'node_id');
    }

    public function outboxEvents()
    {
        return $this->hasMany(SyncOutboxEvent::class, 'source_node_id');
    }

    public function inboxReceipts()
    {
        return $this->hasMany(SyncInboxReceipt::class, 'sync_node_id');
    }

    public function changes()
    {
        return $this->hasMany(SyncChange::class, 'source_node_id');
    }

    public function cursors()
    {
        return $this->hasMany(SyncNodeCursor::class, 'sync_node_id');
    }

    public function replayNonces()
    {
        return $this->hasMany(SyncReplayNonce::class, 'sync_node_id');
    }

    public function terminalActivations()
    {
        return $this->hasMany(SyncTerminalActivation::class, 'sync_node_id');
    }
}
