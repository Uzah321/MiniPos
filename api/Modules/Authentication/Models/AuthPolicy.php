<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Authentication\Models\Concerns\HasEnterpriseScope;

class AuthPolicy extends Model
{
    use HasEnterpriseScope, HasUuids;

    protected $table = 'auth_policies';

    protected $fillable = [
        'policy_key',
        'scope_type',
        'country_id',
        'company_id',
        'brand_id',
        'complex_id',
        'shop_id',
        'till_id',
        'priority',
        'version',
        'settings',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'version' => 'integer',
        'settings' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(PosUser::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(PosUser::class, 'updated_by');
    }
}
