<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Models\UserMfaMethod;
use Modules\Authentication\Services\AccessService;

#[Fillable([
    'name', 'email', 'password', 'employee_code', 'pin_hash', 'store_id',
    'authentication_mode', 'status', 'active', 'failed_login_attempts',
    'locked_until', 'password_changed_at', 'pin_changed_at',
    'must_change_password', 'must_change_pin', 'last_login_at', 'meta',
])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'pin_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'must_change_pin' => 'boolean',
            'last_login_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function accessAssignments(): HasMany
    {
        return $this->hasMany(UserAccessAssignment::class);
    }

    public function activeAccessAssignments(): HasMany
    {
        return $this->accessAssignments()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(UserMfaMethod::class);
    }

    public function isActive(): bool
    {
        return $this->active
            && $this->status === 'active'
            && ($this->locked_until === null || $this->locked_until->isPast());
    }

    public function supportsBackOfficeLogin(): bool
    {
        return in_array($this->authentication_mode, ['back_office', 'both'], true);
    }

    public function supportsPosLogin(): bool
    {
        return in_array($this->authentication_mode, ['pos', 'both'], true);
    }

    public function isSuperAdmin(): bool
    {
        return app(AccessService::class)->isSuperAdmin($this);
    }

    public function hasPermission(string $permission, ?AccessContext $context = null): bool
    {
        return app(AccessService::class)->hasPermission($this, $permission, $context);
    }
}
