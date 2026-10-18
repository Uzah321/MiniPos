<?php

namespace Modules\Authentication\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Authentication\Models\Concerns\HasEnterpriseScope;
use Modules\RolesAndPermission\Models\Role;

class UserAccessAssignment extends Model
{
    use HasEnterpriseScope, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'pos_user_id',
        'role_id',
        'scope_type',
        'country_id',
        'company_id',
        'brand_id',
        'complex_id',
        'shop_id',
        'till_id',
        'status',
        'valid_from',
        'valid_until',
        'granted_by',
        'grant_reason',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(PosUser::class, 'pos_user_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(PosUser::class, 'granted_by');
    }

    public function revokedBy()
    {
        return $this->belongsTo(PosUser::class, 'revoked_by');
    }

    public function scopeEffectiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', $at);
            });
    }
}
