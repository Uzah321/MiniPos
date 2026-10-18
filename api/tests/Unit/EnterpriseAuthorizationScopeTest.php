<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\ComplexLocation;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use PHPUnit\Framework\TestCase;

class EnterpriseAuthorizationScopeTest extends TestCase
{
    public function test_it_builds_the_complete_enterprise_lineage_in_order(): void
    {
        $scope = new AuthorizationScope(
            countryId: 'country-1',
            companyId: 'company-1',
            brandId: 'brand-1',
            complexId: 'complex-1',
            shopId: 'shop-1',
            tillId: 'till-1',
            branchId: 'branch-1',
        );

        $this->assertSame(EnterpriseScope::Till, $scope->deepestType());
        $this->assertSame([
            'global',
            'country:country-1',
            'company:company-1',
            'brand:brand-1',
            'complex:complex-1',
            'shop:shop-1',
            'till:till-1',
        ], $scope->lineageKeys());
    }

    public function test_legacy_branch_is_a_shop_compatibility_fallback(): void
    {
        $scope = AuthorizationScope::fromArray([
            'company_id' => 'company-1',
            'branch_id' => 'branch-shop-1',
        ]);

        $this->assertSame('branch-shop-1', $scope->shopId);
        $this->assertSame('branch-shop-1', $scope->branchId);
        $this->assertContains('shop:branch-shop-1', $scope->lineageKeys());
    }

    public function test_database_columns_discard_descendant_ids_below_selected_scope(): void
    {
        $scope = new AuthorizationScope(
            countryId: 'country-1',
            companyId: 'company-1',
            brandId: 'brand-1',
            shopId: 'shop-1',
        );

        $this->assertSame([
            'scope_type' => 'company',
            'scope_key' => 'company:company-1',
            'country_id' => 'country-1',
            'company_id' => 'company-1',
            'brand_id' => null,
            'complex_id' => null,
            'shop_id' => null,
            'till_id' => null,
        ], $scope->databaseColumns(EnterpriseScope::Company));
    }

    public function test_non_global_scope_requires_its_target_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationScope::global()->scopeKey(EnterpriseScope::Shop);
    }

    public function test_till_scope_is_derived_from_its_server_side_shop_hierarchy(): void
    {
        $shop = new Shop;
        $shop->setRawAttributes([
            'id' => 'shop-1',
            'country_id' => 'country-1',
            'company_id' => 'company-1',
            'brand_id' => 'brand-1',
            'complex_id' => 'complex-1',
            'branch_id' => 'branch-1',
        ]);

        $till = new PosTill;
        $till->setRawAttributes([
            'id' => 'till-1',
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'shop_id' => 'shop-1',
        ]);
        $till->setRelation('shop', $shop);

        $scope = (new EnterpriseScopeResolver)->fromTill($till);

        $this->assertSame('till:till-1', $scope->scopeKey(EnterpriseScope::Till));
        $this->assertSame('shop-1', $scope->shopId);
        $this->assertSame('brand-1', $scope->brandId);
        $this->assertSame('country-1', $scope->countryId);
    }

    public function test_complex_scope_is_company_owned_even_if_a_legacy_brand_value_exists(): void
    {
        $complex = new ComplexLocation;
        $complex->setRawAttributes([
            'id' => 'complex-1',
            'country_id' => 'country-1',
            'company_id' => 'company-1',
            'brand_id' => 'legacy-brand-that-must-not-scope-the-complex',
        ]);

        $scope = (new EnterpriseScopeResolver)->fromComplex($complex);

        $this->assertSame('complex:complex-1', $scope->scopeKey(EnterpriseScope::Complex));
        $this->assertNull($scope->brandId);
    }
}
