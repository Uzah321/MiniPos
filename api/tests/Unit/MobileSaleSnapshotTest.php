<?php

namespace Tests\Unit;

use App\Services\Sync\MobileSaleSnapshot;
use App\Services\Sync\MobileSaleSnapshotException;
use InvalidArgumentException;
use Tests\TestCase;

class MobileSaleSnapshotTest extends TestCase
{
    private function snapshot(): MobileSaleSnapshot
    {
        return new MobileSaleSnapshot([
            'schema_version' => 3,
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'till_id' => 'till-1',
            'content_hash' => str_repeat('a', 64),
            'generated_at' => '2026-10-07T10:00:00+02:00',
            'datasets' => [
                'currencies' => [
                    ['id' => 'usd', 'branch_id' => null, 'code' => 'USD', 'is_base_currency' => true, 'is_active' => true],
                    ['id' => 'zwg', 'branch_id' => 'branch-1', 'code' => 'ZWG', 'is_base_currency' => false, 'is_active' => true],
                ],
                'currency_rates' => [
                    ['id' => 'r-usd', 'currency_id' => 'usd', 'branch_id' => null, 'rate' => '1.0000'],
                    ['id' => 'r-zwg', 'currency_id' => 'zwg', 'branch_id' => 'branch-1', 'rate' => '14.2500'],
                ],
                'payment_types' => [
                    [
                        'id' => 'cash-1', 'branch_id' => 'branch-1', 'name' => 'Cash', 'money_code' => 'CASH',
                        'supportsMultiCurrency' => true, 'requiresAmountTendered' => true, 'is_active' => true,
                    ],
                    [
                        'id' => 'credit-1', 'branch_id' => 'branch-1', 'name' => 'Customer Credit',
                        'money_code' => 'CREDIT_CUSTOMER', 'type' => 'credit', 'is_active' => true,
                    ],
                ],
                'products' => [
                    ['id' => 'product-1', 'product_type' => 'simple', 'is_active' => true],
                    ['id' => 'hidden-product', 'product_type' => 'simple', 'is_active' => false],
                    ['id' => 'raw-product', 'product_type' => 'raw_material', 'is_active' => true],
                    ['id' => 'other-branch-product', 'branch_id' => 'branch-2', 'product_type' => 'simple', 'is_active' => true],
                ],
                'price_levels' => [
                    ['id' => 'standard', 'name' => ' Standard ', 'code' => ' STANDARD ', 'is_active' => true],
                    ['id' => 'vip', 'name' => 'VIP', 'code' => 'VIP', 'is_active' => true],
                ],
                'product_prices' => [
                    [
                        'id' => 'price-1', 'product_id' => 'product-1', 'branch_id' => 'branch-1',
                        'price_level_id' => 'standard', 'price' => '2.5000', 'currency' => 'USD',
                        'is_default' => true, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'price-standard-global', 'product_id' => 'product-1', 'branch_id' => null,
                        'price_level_id' => 'standard', 'price' => '0.2500', 'currency' => 'USD',
                        'is_default' => true, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'price-standard-nondefault', 'product_id' => 'product-1', 'branch_id' => 'branch-1',
                        'price_level_id' => 'standard', 'price' => '0.5000', 'currency' => 'USD',
                        'is_default' => false, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'price-standard-high', 'product_id' => 'product-1', 'branch_id' => 'branch-1',
                        'price_level_id' => 'standard', 'price' => '3.0000', 'currency' => 'USD',
                        'is_default' => true, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'price-vip', 'product_id' => 'product-1', 'branch_id' => 'branch-1',
                        'price_level_id' => 'vip', 'price' => '1.0000', 'currency' => 'USD',
                        'is_default' => false, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'hidden-price', 'product_id' => 'hidden-product', 'branch_id' => 'branch-1',
                        'price_level_id' => 'standard', 'price' => '0.1000', 'currency' => 'USD',
                        'is_default' => true, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                    [
                        'id' => 'raw-price', 'product_id' => 'raw-product', 'branch_id' => 'branch-1',
                        'price_level_id' => 'standard', 'price' => '0.2000', 'currency' => 'USD',
                        'is_default' => true, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => null,
                    ],
                ],
                'taxes' => [
                    ['id' => 'vat', 'name' => 'VAT', 'type' => 'percentage', 'rate' => '15.0000', 'is_active' => true],
                    ['id' => 'levy', 'name' => 'Levy', 'type' => 'fixed', 'rate' => '0.5000', 'is_active' => true],
                ],
                'product_taxes' => [
                    ['id' => 'pt-1', 'product_id' => 'product-1', 'tax_id' => 'vat', 'branch_id' => null, 'is_inclusive' => true, 'is_active' => true],
                    ['id' => 'pt-2', 'product_id' => 'product-1', 'tax_id' => 'levy', 'branch_id' => 'branch-1', 'is_inclusive' => false, 'is_active' => true],
                ],
                'mobile_context' => [[
                    'id' => 'current', 'company_id' => 'company-1', 'branch_id' => 'branch-1',
                    'till_id' => 'till-1', 'business_mode' => 'retail',
                    'business_date' => '2026-10-07', 'system_timezone' => 'Africa/Harare',
                    'sales_allowed' => true,
                ]],
            ],
        ], 'branch-1', 'till-1');
    }

    public function test_it_reconstructs_price_tax_payment_and_rates_from_server_snapshot(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(str_repeat('a', 64), $snapshot->contentHash());
        $this->assertSame('USD', $snapshot->baseCurrency()->code);
        $this->assertSame(14.25, $snapshot->exchangeRate('USD', 'ZWG'));
        $this->assertSame(28.5, $snapshot->convert(2, 'USD', 'ZWG'));
        $this->assertSame('2.5000', $snapshot->productPrice('product-1', 'price-1')->price);
        $this->assertTrue((bool) $snapshot->paymentType('cash-1')->requiresAmountTendered);

        $taxes = $snapshot->productTaxes('product-1');
        $this->assertCount(2, $taxes);
        $this->assertSame('VAT', $taxes->firstWhere('tax_id', 'vat')->tax->name);
        $this->assertTrue((bool) $taxes->firstWhere('tax_id', 'vat')->is_inclusive);
        $this->assertFalse((bool) $taxes->firstWhere('tax_id', 'levy')->is_inclusive);
    }

    public function test_it_rejects_a_price_not_present_in_the_acknowledged_snapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->snapshot()->productPrice('product-1', 'price-from-newer-config');
    }

    public function test_it_rejects_a_cheaper_non_standard_price_without_mobile_authorization(): void
    {
        try {
            $this->snapshot()->productPrice('product-1', 'price-vip');
            $this->fail('A non-standard mobile price selection should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_PRICE_SELECTION_UNAUTHORIZED', $error->errorCode);
            $this->assertSame(422, $error->httpStatus);
        }
    }

    public function test_it_accepts_the_same_standard_price_selected_by_the_mobile_catalogue(): void
    {
        $price = $this->snapshot()->productPrice('product-1', 'price-1');

        $this->assertSame('price-1', $price->id);
        $this->assertSame('2.5000', $price->price);
    }

    public function test_it_binds_the_sale_business_date_to_the_acknowledged_context(): void
    {
        $snapshot = $this->snapshot();

        $snapshot->assertSaleContext('2026-10-07');
        $this->assertSame('2026-10-07', $snapshot->businessDate());
        $this->assertSame('retail', $snapshot->businessMode());
        $this->assertTrue($snapshot->salesAllowed());

        try {
            $snapshot->assertSaleContext('2026-10-08');
            $this->fail('A substituted business date should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_SNAPSHOT_BUSINESS_DATE_MISMATCH', $error->errorCode);
        }
    }

    public function test_it_fails_closed_when_acknowledged_snapshot_predates_v3(): void
    {
        try {
            new MobileSaleSnapshot([
                'schema_version' => 1,
                'company_id' => 'company-1',
                'generated_at' => '2026-10-07T10:00:00+02:00',
                'datasets' => [
                    'mobile_context' => [[
                        'id' => 'current', 'company_id' => 'company-1', 'branch_id' => 'branch-1',
                        'till_id' => 'till-1', 'business_mode' => 'retail',
                        'business_date' => '2026-10-07', 'system_timezone' => 'Africa/Harare',
                        'sales_allowed' => true,
                    ]],
                ],
            ], 'branch-1', 'till-1');
            $this->fail('A pre-v3 acknowledged snapshot should require a resync.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_CONFIGURATION_RESYNC_REQUIRED', $error->errorCode);
        }
    }

    public function test_it_rejects_a_snapshot_that_did_not_allow_sales(): void
    {
        $payload = [
            'schema_version' => 3,
            'company_id' => 'company-1',
            'generated_at' => '2026-10-07T10:00:00+02:00',
            'datasets' => [
                'mobile_context' => [[
                    'id' => 'current', 'company_id' => 'company-1', 'branch_id' => 'branch-1',
                    'till_id' => 'till-1', 'business_mode' => 'hospitality',
                    'business_date' => null, 'system_timezone' => 'Africa/Harare',
                    'sales_allowed' => false,
                    'sale_block_reason' => 'Day End is running.',
                ]],
            ],
        ];
        $snapshot = new MobileSaleSnapshot($payload, 'branch-1', 'till-1');

        try {
            $snapshot->assertSaleContext('2026-10-07');
            $this->fail('A snapshot that blocked sales should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_SNAPSHOT_SALES_BLOCKED', $error->errorCode);
        }
    }

    public function test_it_rejects_products_hidden_by_the_acknowledged_mobile_catalogue(): void
    {
        try {
            $this->snapshot()->productPrice('hidden-product', 'hidden-price');
            $this->fail('A hidden mobile product should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_PRODUCT_SELECTION_UNAUTHORIZED', $error->errorCode);
        }

        try {
            $this->snapshot()->productPrice('raw-product', 'raw-price');
            $this->fail('A raw-material mobile product should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_PRODUCT_SELECTION_UNAUTHORIZED', $error->errorCode);
        }
    }

    public function test_historical_product_fields_remain_branch_scoped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not saleable');
        $this->snapshot()->productFiscalFields('other-branch-product');
    }

    public function test_it_rejects_payment_methods_hidden_by_the_mobile_catalogue(): void
    {
        try {
            $this->snapshot()->paymentType('credit-1');
            $this->fail('A customer-credit method hidden on mobile should have been rejected.');
        } catch (MobileSaleSnapshotException $error) {
            $this->assertSame('MOBILE_PAYMENT_SELECTION_UNAUTHORIZED', $error->errorCode);
        }
    }
}
