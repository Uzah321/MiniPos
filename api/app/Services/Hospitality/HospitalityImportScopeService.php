<?php

namespace App\Services\Hospitality;

use App\Services\Auth\SuperAdminService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Company;
use Modules\Company\Models\Shop;

final class HospitalityImportScopeService
{
    private const PERMISSION = 'import_products';

    public function __construct(
        private readonly SuperAdminService $superAdmins,
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly EnterpriseScopeResolver $scopes,
    ) {}

    /**
     * @return array{company_wide: bool, shops: Collection<int, Shop>}
     */
    public function resolve(mixed $user, Company $company): array
    {
        $shops = $this->activeShops((string) $company->getKey());
        $companyWide = $this->canImportCompanyWide($user, $company);

        if ($companyWide) {
            return ['company_wide' => true, 'shops' => $shops];
        }

        if (! $user instanceof PosUser
            || ! $this->authorization->usesScopedAssignments($user)) {
            return ['company_wide' => false, 'shops' => collect()];
        }

        $candidateBranchIds = $shops
            ->pluck('branch_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $accessibleBranchIds = $this->authorization->accessibleBranchIds(
            $user,
            (string) $company->getKey(),
            $candidateBranchIds
        );

        $shops = $shops
            ->filter(function (Shop $shop) use ($user, $accessibleBranchIds): bool {
                return in_array((string) $shop->branch_id, $accessibleBranchIds, true)
                    && $this->authorization->can(
                        $user,
                        self::PERMISSION,
                        $this->scopes->fromShop($shop)
                    );
            })
            ->values();

        return ['company_wide' => false, 'shops' => $shops];
    }

    /**
     * A restricted user must provide a body branch_id, and that branch must
     * resolve to an authorised shop of the selected brand. Request headers
     * deliberately do not select the mutation target.
     *
     * @param  array{company_wide: bool, shops: Collection<int, Shop>}  $access
     */
    public function assertSelectionAllowed(
        array $access,
        string $brandId,
        ?string $branchId
    ): void {
        $brandId = trim($brandId);
        $branchId = $this->nullableId($branchId);

        if (! $access['company_wide'] && $branchId === null) {
            throw new HospitalityImportScopeException(
                'Select an authorised shop before importing this catalogue.',
                'HOSPITALITY_IMPORT_SCOPE_REQUIRED'
            );
        }

        if ($branchId === null) {
            return;
        }

        $allowed = $access['shops']->contains(
            fn (Shop $shop): bool => (string) $shop->brand_id === $brandId
                && (string) $shop->branch_id === $branchId
        );
        if (! $allowed) {
            throw new HospitalityImportScopeException(
                'The selected shop is not authorised for this brand import.',
                'HOSPITALITY_IMPORT_SCOPE_DENIED'
            );
        }
    }

    private function canImportCompanyWide(mixed $user, Company $company): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->superAdmins->isPlatformAdministrator($user)) {
            return true;
        }

        if ($user instanceof PosUser) {
            if ($this->authorization->usesScopedAssignments($user)) {
                return $this->authorization->can(
                    $user,
                    self::PERMISSION,
                    $this->scopes->fromCompany($company)
                );
            }

            $user->loadMissing('role.permissions');

            return $this->superAdmins->isSuperAdmin($user)
                || (bool) $user->role?->permissions?->contains(
                    fn ($permission): bool => (string) $permission->name === self::PERMISSION
                );
        }

        $user->loadMissing('role.permissions');

        return (bool) $user->role?->permissions?->contains(
            fn ($permission): bool => (string) $permission->name === self::PERMISSION
        );
    }

    /** @return Collection<int, Shop> */
    private function activeShops(string $companyId): Collection
    {
        if (! Schema::hasTable('shops') || ! Schema::hasTable('company_branches')) {
            return collect();
        }

        $query = Shop::query()
            ->with('branch')
            ->where('company_id', $companyId)
            ->whereNotNull('brand_id')
            ->whereNotNull('branch_id')
            ->whereHas('branch', function ($branch) use ($companyId): void {
                $branch->where('company_id', $companyId);
                if (Schema::hasColumn('company_branches', 'status')) {
                    $branch->where('status', 'active');
                }
                if (Schema::hasColumn('company_branches', 'deleted_at')) {
                    $branch->whereNull('deleted_at');
                }
            });
        if (Schema::hasColumn('shops', 'status')) {
            $query->where('status', 'active');
        }
        if (Schema::hasColumn('shops', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->orderBy('name')->get();
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
