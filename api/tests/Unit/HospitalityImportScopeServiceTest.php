<?php

namespace Tests\Unit;

use App\Services\Hospitality\HospitalityImportScopeException;
use App\Services\Hospitality\HospitalityImportScopeService;
use Modules\Company\Models\Shop;
use Tests\TestCase;

final class HospitalityImportScopeServiceTest extends TestCase
{
    public function test_restricted_import_requires_an_explicit_body_branch(): void
    {
        try {
            $this->service()->assertSelectionAllowed(
                ['company_wide' => false, 'shops' => collect([$this->shop()])],
                'brand-pizza',
                null
            );
            $this->fail('A restricted import without a branch should be rejected.');
        } catch (HospitalityImportScopeException $exception) {
            $this->assertSame('HOSPITALITY_IMPORT_SCOPE_REQUIRED', $exception->errorCode);
        }
    }

    public function test_restricted_import_rejects_another_brand_or_branch(): void
    {
        foreach ([
            ['brand-chicken', 'branch-pizza'],
            ['brand-pizza', 'branch-other'],
        ] as [$brandId, $branchId]) {
            try {
                $this->service()->assertSelectionAllowed(
                    ['company_wide' => false, 'shops' => collect([$this->shop()])],
                    $brandId,
                    $branchId
                );
                $this->fail('An out-of-scope shop should be rejected.');
            } catch (HospitalityImportScopeException $exception) {
                $this->assertSame('HOSPITALITY_IMPORT_SCOPE_DENIED', $exception->errorCode);
            }
        }
    }

    public function test_restricted_import_accepts_its_authorised_brand_shop_pair(): void
    {
        $this->service()->assertSelectionAllowed(
            ['company_wide' => false, 'shops' => collect([$this->shop()])],
            'brand-pizza',
            'branch-pizza'
        );

        $this->addToAssertionCount(1);
    }

    public function test_company_wide_import_may_omit_branch_but_cannot_mismatch_one(): void
    {
        $access = ['company_wide' => true, 'shops' => collect([$this->shop()])];
        $this->service()->assertSelectionAllowed($access, 'brand-pizza', null);

        $this->expectException(HospitalityImportScopeException::class);
        $this->service()->assertSelectionAllowed($access, 'brand-chicken', 'branch-pizza');
    }

    private function service(): HospitalityImportScopeService
    {
        return app(HospitalityImportScopeService::class);
    }

    private function shop(): Shop
    {
        $shop = new Shop;
        $shop->forceFill([
            'id' => 'shop-pizza',
            'company_id' => 'company-1',
            'brand_id' => 'brand-pizza',
            'branch_id' => 'branch-pizza',
        ]);

        return $shop;
    }
}
