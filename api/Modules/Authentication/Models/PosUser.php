<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\RolesAndPermission\Models\Role;

// use Modules\Authentication\Database\Factories\PosUserFactory;

class PosUser extends Authenticatable
{
    use HasApiTokens, HasUuids, SoftDeletes;

    protected $table = 'pos_users';

    protected $fillable = [
        'company_id',
        'branch_id',
        'role_id',
        'name',
        'username',
        'phone',
        'password',
        'employee_code',
        'user_type',
        'can_login_pos',
        'can_access_front_office',
        'can_access_back_office',
        'is_active',
        'last_login_at',
        'login_code',
    ];

    protected $hidden = [
        'password',
        'login_code',
        'login_code_lookup',
        'login_code_lookup_key_id',
        'remember_token',
    ];

    protected $casts = [
        'can_login_pos' => 'boolean',
        'can_access_front_office' => 'boolean',
        'can_access_back_office' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'credential_version' => 'integer',
        'credentials_changed_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branches()
    {
        return $this->belongsToMany(
            CompanyBranch::class,
            'user_branches',
            'pos_user_id',
            'branch_id'
        )->withPivot([
            'id',
            'company_id',
            'is_default',
        ])->withTimestamps();
    }

    public function shiftSessions()
    {
        return $this->hasMany(PosUserShiftSession::class, 'pos_user_id');
    }

    public function activeShiftSession()
    {
        return $this->hasOne(PosUserShiftSession::class, 'pos_user_id')
            ->where('status', 'open')
            ->latestOfMany();
    }

    public function shiftAssignments()
    {
        return $this->hasMany(PosUserShiftAssignment::class, 'pos_user_id');
    }

    public function accessAssignments()
    {
        return $this->hasMany(UserAccessAssignment::class, 'pos_user_id');
    }

    public function authSessions()
    {
        return $this->hasMany(AuthSession::class, 'pos_user_id');
    }

    public function credentialHistories()
    {
        return $this->hasMany(CredentialHistory::class, 'pos_user_id');
    }

    public function authenticationEvents()
    {
        return $this->hasMany(AuthenticationEvent::class, 'pos_user_id');
    }
}
