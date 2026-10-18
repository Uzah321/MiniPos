<?php

namespace Modules\Authentication\Services;

use DomainException;
use InvalidArgumentException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Company\Models\Brand;
use Modules\Company\Models\Company;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Country;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;

final class EnterpriseScopeResolver
{
    public function resolve(EnterpriseScope $type, ?string $targetId = null): AuthorizationScope
    {
        if ($type === EnterpriseScope::Global) {
            return AuthorizationScope::global();
        }

        $targetId = trim((string) $targetId);
        if ($targetId === '') {
            throw new InvalidArgumentException("A {$type->value} target identifier is required.");
        }

        return match ($type) {
            EnterpriseScope::Country => $this->fromCountry(Country::query()->findOrFail($targetId)),
            EnterpriseScope::Company => $this->fromCompany(Company::query()->findOrFail($targetId)),
            EnterpriseScope::Brand => $this->fromBrand(Brand::query()->findOrFail($targetId)),
            EnterpriseScope::Complex => $this->fromComplex(ComplexLocation::query()->findOrFail($targetId)),
            EnterpriseScope::Shop => $this->fromShop(Shop::query()->findOrFail($targetId)),
            EnterpriseScope::Till => $this->fromTill(PosTill::query()->findOrFail($targetId)),
            EnterpriseScope::Global => AuthorizationScope::global(),
        };
    }

    public function fromCountry(Country $country): AuthorizationScope
    {
        return new AuthorizationScope(countryId: (string) $country->getKey());
    }

    public function fromCompany(Company $company): AuthorizationScope
    {
        return new AuthorizationScope(
            countryId: $this->nullableId($company->country_id),
            companyId: (string) $company->getKey(),
        );
    }

    public function fromBrand(Brand $brand): AuthorizationScope
    {
        return new AuthorizationScope(
            countryId: $this->nullableId($brand->country_id),
            companyId: $this->nullableId($brand->company_id),
            brandId: (string) $brand->getKey(),
        );
    }

    public function fromComplex(ComplexLocation $complex): AuthorizationScope
    {
        return new AuthorizationScope(
            countryId: $this->nullableId($complex->country_id),
            companyId: $this->nullableId($complex->company_id),
            complexId: (string) $complex->getKey(),
        );
    }

    public function fromShop(Shop $shop): AuthorizationScope
    {
        return new AuthorizationScope(
            countryId: $this->nullableId($shop->country_id),
            companyId: $this->nullableId($shop->company_id),
            brandId: $this->nullableId($shop->brand_id),
            complexId: $this->nullableId($shop->complex_id),
            shopId: (string) $shop->getKey(),
            branchId: $this->nullableId($shop->branch_id),
        );
    }

    public function fromTill(PosTill $till): AuthorizationScope
    {
        $shop = $till->shop;
        if (! $shop && $till->branch_id) {
            $shop = Shop::query()->where('branch_id', $till->branch_id)->first();
        }
        if (! $shop) {
            throw new DomainException('The till is not attached to a canonical shop.');
        }

        if ((string) $shop->company_id !== (string) $till->company_id
            || (string) $shop->branch_id !== (string) $till->branch_id
            || ($till->shop_id !== null && (string) $shop->getKey() !== (string) $till->shop_id)
            || ($till->country_id !== null && (string) $shop->country_id !== (string) $till->country_id)
            || ($till->brand_id !== null && (string) $shop->brand_id !== (string) $till->brand_id)
            || ($till->complex_id !== null && (string) $shop->complex_id !== (string) $till->complex_id)) {
            throw new DomainException('The till hierarchy is inconsistent with its canonical shop.');
        }

        $shopScope = $this->fromShop($shop);

        return new AuthorizationScope(
            countryId: $shopScope->countryId,
            companyId: $shopScope->companyId,
            brandId: $shopScope->brandId,
            complexId: $shopScope->complexId,
            shopId: $shopScope->shopId,
            tillId: (string) $till->getKey(),
            branchId: $shopScope->branchId,
        );
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
