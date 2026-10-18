<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SyncReplayNonce extends Model
{
    use HasUuids;

    protected $fillable = [
        'sync_node_id',
        'nonce',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'nonce',
    ];

    public function node()
    {
        return $this->belongsTo(SyncNode::class, 'sync_node_id');
    }
}
