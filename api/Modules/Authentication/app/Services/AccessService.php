<?php

namespace Modules\Authentication\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Terminals\Terminal;

class AccessService
{
    public function isSuperAdmin(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $this->assignments($user)
            ->where('scope_type', 'global')
            ->whereHas('role', fn (Builder $query) => $query
                ->where('is_super_admin', true)
                ->where('status', 'active'))
            ->exists();
    }

    public function hasPermission(User $user, string $permission, ?AccessContext $context = null): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $query = $this->assignments($user)
            ->whereHas('role', fn (Builder $role) => $role
                ->where('status', 'active')
                ->whereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery
                    ->where('name', $permission)
                    ->where('status', 'active')));

        if ($context !== null) {
            $this->applyContext($query, $context);
        }

        return $query->exists();
    }

    public function canAccessStore(User $user, Store $store): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $query = $this->assignments($user);
        $this->applyContext($query, AccessContext::forStore($store));

        return $query->exists();
    }

    public function canAccessTerminal(User $user, Terminal $terminal): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $query = $this->assignments($user);
        $this->applyContext($query, AccessContext::forTerminal($terminal));

        return $query->exists();
    }

    public function scopeStores(Builder $query, User $user, ?string $permission = null): Builder
    {
        if ($this->isSuperAdmin($user)) {
            return $query;
        }

        $assignments = $this->assignments($user, $permission)->get([
            'scope_type', 'country_id', 'brand_id', 'complex_id', 'store_id',
        ]);

        if ($assignments->contains('scope_type', 'global')) {
            return $query;
        }

        $storeIds = $assignments->pluck('store_id')->filter()->unique();
        $complexIds = $assignments->pluck('complex_id')->filter()->unique();
        $brandIds = $assignments->pluck('brand_id')->filter()->unique();
        $countryIds = $assignments->pluck('country_id')->filter()->unique();

        return $query->where(function (Builder $scope) use ($storeIds, $complexIds, $brandIds, $countryIds): void {
            $scope->whereIn('id', $storeIds)
                ->orWhereIn('complex_id', $complexIds)
                ->orWhereHas('complex', fn (Builder $complex) => $complex
                    ->whereIn('brand_id', $brandIds)
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->whereIn('country_id', $countryIds)));
        });
    }

    public function assignments(User $user, ?string $permission = null): Builder
    {
        return UserAccessAssignment::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->when($permission, fn (Builder $query) => $query->whereHas('role', fn (Builder $role) => $role
                ->where('status', 'active')
                ->whereHas('permissions', fn (Builder $permissions) => $permissions
                    ->where('name', $permission)
                    ->where('status', 'active'))));
    }

    private function applyContext(Builder $query, AccessContext $context): void
    {
        $query->where(function (Builder $scope) use ($context): void {
            $scope->where('scope_type', 'global');

            if ($context->countryId !== null) {
                $scope->orWhere(fn (Builder $country) => $country
                    ->where('scope_type', 'country')
                    ->where('country_id', $context->countryId));
            }
            if ($context->brandId !== null) {
                $scope->orWhere(fn (Builder $brand) => $brand
                    ->where('scope_type', 'brand')
                    ->where('brand_id', $context->brandId));
            }
            if ($context->complexId !== null) {
                $scope->orWhere(fn (Builder $complex) => $complex
                    ->where('scope_type', 'complex')
                    ->where('complex_id', $context->complexId));
            }
            if ($context->storeId !== null) {
                $scope->orWhere(fn (Builder $store) => $store
                    ->where('scope_type', 'store')
                    ->where('store_id', $context->storeId));
            }
            if ($context->terminalId !== null) {
                $scope->orWhere(fn (Builder $terminal) => $terminal
                    ->where('scope_type', 'terminal')
                    ->where('terminal_id', $context->terminalId));
            }
        });
    }
}
