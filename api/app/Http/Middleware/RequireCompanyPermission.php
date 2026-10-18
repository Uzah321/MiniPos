<?php

namespace App\Http\Middleware;

use App\Services\Auth\SuperAdminService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Shop;
use Symfony\Component\HttpFoundation\Response;

class RequireCompanyPermission
{
    public function __construct(
        private readonly SuperAdminService $superAdmins,
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly EnterpriseScopeResolver $scopes,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $company = app('company');
        $user = $request->user()?->loadMissing('role.permissions');
        $platformSession = $user
            && $this->superAdmins->hasPlatformSessionAccess($user);

        if (! $user || (! $platformSession && (string) $user->company_id !== (string) $company->id)) {
            return response()->json([
                'message' => 'This user does not belong to the resolved company.',
                'code' => 'COMPANY_ACCESS_DENIED',
            ], 403);
        }

        $role = $user->role;
        $normalise = static fn ($value) => strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
        $superAdminNames = ['super_admin', 'super_administrator'];
        $usesScopedAssignments = $user instanceof PosUser
            && $this->authorization->usesScopedAssignments($user);
        $isLegacyCompanySuperAdmin = ! $usesScopedAssignments && (
            $platformSession
            || in_array($normalise($user->user_type ?? ''), $superAdminNames, true)
            || ($role && in_array($normalise($role->name), $superAdminNames, true))
        );
        $granted = $role?->permissions?->pluck('name')->all() ?? [];
        $isGranted = $platformSession || $isLegacyCompanySuperAdmin;

        if (! $isGranted && $usesScopedAssignments) {
            try {
                $scope = $this->scopeForRequest($request, $company);
            } catch (\DomainException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'AUTHORIZATION_SCOPE_INVALID',
                ], 403);
            }

            $isGranted = $this->authorization->canAny($user, $permissions, $scope);
        } elseif (! $isGranted) {
            // Rolling-deployment compatibility: a user who has never received
            // a canonical assignment still uses the legacy role. Once any
            // assignment exists, legacy grants can no longer bypass a revoke.
            $isGranted = collect($permissions)
                ->contains(fn ($permission) => in_array($permission, $granted, true));
        }

        if (! $isGranted) {
            return response()->json([
                'message' => 'You do not have permission to access this company management resource.',
                'code' => 'PERMISSION_DENIED',
                'required_any' => $permissions,
            ], 403);
        }

        return $next($request);
    }

    private function scopeForRequest(Request $request, mixed $company): AuthorizationScope
    {
        if (app()->bound('till') && app('till')) {
            return $this->scopes->fromTill(app('till'));
        }

        $branchId = collect([
            $request->attributes->get('company_branch_id'),
            $request->attributes->get('business_mode_branch_id'),
            $request->header('X-Branch-ID'),
            $request->input('branch_id'),
            $request->user()?->branch_id,
        ])->first(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');

        $shopId = collect([
            $request->attributes->get('shop_id'),
            $request->header('X-Shop-ID'),
            $request->input('shop_id'),
        ])->first(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');

        if ($shopId !== null) {
            $shop = Shop::query()
                ->where('company_id', $company->id)
                ->when(
                    $branchId !== null,
                    fn ($query) => $query->where('branch_id', trim((string) $branchId))
                )
                ->whereKey(trim((string) $shopId))
                ->first();
            if (! $shop) {
                throw new \DomainException(
                    'The authorization shop does not belong to the selected company and branch.'
                );
            }

            return $this->scopes->fromShop($shop);
        }

        if ($branchId === null) {
            return $this->scopes->fromCompany($company);
        }
        $branchId = trim((string) $branchId);
        if (Schema::hasTable('company_branches') && ! DB::table('company_branches')
            ->where('company_id', $company->id)
            ->where('id', $branchId)
            ->exists()) {
            throw new \DomainException('The authorization branch does not belong to the resolved company.');
        }

        if (Schema::hasTable('shops')) {
            $shop = Shop::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->first();
            if ($shop) {
                return $this->scopes->fromShop($shop);
            }
        }

        return new AuthorizationScope(
            companyId: (string) $company->id,
            shopId: $branchId,
            branchId: $branchId,
        );
    }
}
