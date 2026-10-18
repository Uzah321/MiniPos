<?php

namespace Modules\Authentication\Services;

use App\Services\CompanySettingService;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Company\Models\Company;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

final class PosAuthenticationContextService
{
    public function __construct(
        private readonly AuthenticationPolicyService $authenticationPolicies,
        private readonly CredentialPolicyService $credentialPolicies,
        private readonly EnterpriseScopeResolver $scopes,
        private readonly CompanySettingService $settings,
    ) {}

    /**
     * @return array{scope: AuthorizationScope, methods: list<string>, mode: string, fingerprint: string, login_code: array<string, mixed>}
     */
    public function resolve(Company $company, PosTill $till, bool $forUpdate = false): array
    {
        if ((string) $till->company_id !== (string) $company->id || ! $till->branch_id) {
            throw new DomainException('The till does not belong to the resolved company and branch.');
        }

        $hasCanonicalHierarchy = Schema::hasTable('shops')
            && Schema::hasTable('pos_tills')
            && Schema::hasColumn('pos_tills', 'shop_id');

        if ($hasCanonicalHierarchy) {
            $shopQuery = Shop::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $till->branch_id);
            if ($till->shop_id) {
                $shopQuery->whereKey($till->shop_id);
            }
            if ($forUpdate) {
                $shopQuery->lockForUpdate();
            }
            $shop = $shopQuery->first();
            if (! $shop) {
                throw new DomainException('The till is not attached to a canonical shop.');
            }
            $till->setRelation('shop', $shop);
            $scope = $this->scopes->fromTill($till);
            $fallbackMode = (string) $shop->pos_login_mode;
        } else {
            // Rolling-deploy compatibility for a pre-hierarchy edge. Branches
            // are backfilled one-to-one as Shops by the hierarchy migration.
            $scope = new AuthorizationScope(
                companyId: (string) $company->id,
                shopId: (string) $till->branch_id,
                tillId: (string) $till->getKey(),
                branchId: (string) $till->branch_id,
            );
            $legacyMode = strtolower(trim((string) $this->settings->get(
                (string) $company->id,
                'pos_login_mode',
                'both',
                (string) $till->branch_id,
            )));
            // `both` preserves the pre-policy behaviour only while the new
            // hierarchy schema is absent; canonical installs never use it.
            $fallbackMode = in_array($legacyMode, ['password', 'username_password', 'code', 'both'], true)
                ? $legacyMode
                : 'both';
        }

        $effective = $this->authenticationPolicies->effectivePolicy($scope, $fallbackMode, $forUpdate);
        $methods = $this->authenticationPolicies->allowedMethods('pos', $scope, $fallbackMode, $forUpdate);
        $mode = match ($methods) {
            [AuthenticationPolicyService::METHOD_LOGIN_CODE] => 'code',
            [AuthenticationPolicyService::METHOD_USERNAME_PASSWORD] => 'username_password',
            default => 'both',
        };

        return [
            'scope' => $scope,
            'methods' => $methods,
            'mode' => $mode,
            'fingerprint' => $effective->fingerprint,
            'login_code' => $this->credentialPolicies->loginCodeRequirements($scope),
        ];
    }
}
