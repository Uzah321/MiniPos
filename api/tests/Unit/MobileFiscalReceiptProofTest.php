<?php

namespace Tests\Unit;

use App\Models\MobileFiscalDevice;
use App\Services\Zimra\MobileFiscalReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Product\Models\Taxe;
use Modules\Sales\Models\ReceiptLine;
use Modules\Sales\Models\ReceiptLineTaxe;
use Modules\Sales\Models\SalesTransaction;
use Tests\TestCase;

class MobileFiscalReceiptProofTest extends TestCase
{
    public function test_registered_public_key_verifies_locally_signed_invoice_and_qr(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();

        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);

        $this->assertTrue(true);
    }

    public function test_optional_fdms_buyer_information_can_accompany_a_matching_sale(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $sale->forceFill(['customer_name' => 'Example Buyer', 'customer_tax_number' => '1234567890']);
        $receipt['buyerData'] = [
            'buyerRegisterName' => 'Example Buyer',
            'buyerTIN' => '1234567890',
            'buyerTradeName' => 'Example Shop',
            'VATNumber' => '123456789',
            'buyerContacts' => ['phoneNo' => '0771234567', 'email' => 'buyer@example.com'],
            'buyerAddress' => [
                'houseNo' => '14', 'street' => 'Kaguvi Street', 'city' => 'Harare', 'province' => 'Harare',
            ],
        ];

        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);

        $this->assertTrue(true);
    }

    public function test_fdms_buyer_requires_name_and_tin_when_supplied(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['buyerData'] = ['buyerRegisterName' => 'Example Buyer'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('buyerTIN');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_fdms_buyer_name_must_match_the_sale_customer(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $sale->customer_name = 'Another Buyer';
        $receipt['buyerData'] = ['buyerRegisterName' => 'Example Buyer', 'buyerTIN' => '1234567890'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('buyer name differs');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_fdms_buyer_tin_must_match_the_sale_customer_tin(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $sale->customer_tax_number = '0000000000';
        $receipt['buyerData'] = ['buyerRegisterName' => 'Example Buyer', 'buyerTIN' => '1234567890'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('buyer TIN differs');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_fdms_buyer_contacts_need_a_value_when_supplied(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['buyerData'] = [
            'buyerRegisterName' => 'Example Buyer',
            'buyerTIN' => '1234567890',
            'buyerContacts' => ['phoneNo' => '', 'email' => ''],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('need a phone number or email');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_fdms_buyer_vat_number_must_fit_the_gateway_limit(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['buyerData'] = [
            'buyerRegisterName' => 'Example Buyer',
            'buyerTIN' => '1234567890',
            'VATNumber' => '1234567890',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VATNumber exceeds 9 characters');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_fdms_buyer_address_must_be_complete_when_supplied(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['buyerData'] = [
            'buyerRegisterName' => 'Example Buyer',
            'buyerTIN' => '1234567890',
            'buyerAddress' => ['houseNo' => '14', 'street' => 'Kaguvi Street', 'city' => 'Harare'],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('province');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_cloud_receipt_renders_only_the_buyer_details_that_were_supplied(): void
    {
        [, $sale] = $this->fixture();
        $sale->forceFill([
            'invoice_number' => 'AND-TEST',
            'customer_name' => 'Example Buyer',
            'customer_tax_number' => '1234567890',
            'currency_code' => 'USD',
            'subtotal_amount' => 2.00,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 2.00,
            'amount_tendered' => 2.00,
            'payment_method' => 'Cash',
            'metadata' => [],
        ]);
        $sale->setRelation('company', (object) ['company_name' => 'Example Company']);
        $sale->setRelation('branch', (object) ['branch_name' => 'Main Branch']);
        $sale->setRelation('till', (object) ['till_code' => 'TILL-1', 'till_name' => 'Front']);
        $sale->setRelation('fiscalDay', null);

        $mobileFiscalReceipt = (new \App\Models\MobileFiscalReceipt())->forceFill([
            'payload' => ['receipt' => ['buyerData' => [
                'buyerRegisterName' => 'Example Buyer',
                'buyerTIN' => '1234567890',
                'VATNumber' => '123456789',
                'buyerContacts' => ['email' => 'buyer@example.com'],
                'buyerAddress' => [
                    'houseNo' => '14', 'street' => 'Kaguvi Street', 'city' => 'Harare', 'province' => 'Harare',
                ],
            ]]],
        ]);
        $mobileFiscalReceipt->setRelation('device', null);

        $html = view('sales::enterprise-receipt', [
            'sale' => $sale,
            'settings' => ['receipt_barcode_enabled' => false],
            'mobileFiscalReceipt' => $mobileFiscalReceipt,
        ])->render();

        $this->assertStringContainsString('Buyer VAT', $html);
        $this->assertStringContainsString('123456789', $html);
        $this->assertStringContainsString('14, Kaguvi Street, Harare, Harare', $html);
        $this->assertStringContainsString('buyer@example.com', $html);
        $this->assertStringNotContainsString('Buyer Phone', $html);
        $this->assertStringNotContainsString('Trade Name', $html);
    }

    public function test_tampered_qr_and_sale_lines_are_rejected(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $service = app(MobileFiscalReceiptService::class);

        try {
            $service->verifyReceipt($device, $sale, $receipt, '', $qr . 'changed');
            $this->fail('A modified QR must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('QR code differs', $exception->getMessage());
        }

        $receipt['receiptLines'][0]['receiptLineTotal'] = 3.00;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('differs from the posted sale');
        $service->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['receiptDeviceSignature']['signature'] = base64_encode(random_bytes(64));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('registered device-certificate signature is invalid');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_signed_receipt_cannot_change_product_hs_code_or_tax_mapping(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $receipt['receiptLines'][0]['receiptLineHSCode'] = '9999';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('differs from the posted sale');
        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
    }

    public function test_two_line_inclusive_fifteen_percent_receipt_matches_sale_tax(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture(2, 15.0);

        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);

        $this->assertEquals(0.30, $sale->tax_amount);
    }

    public function test_current_tax_catalogue_change_does_not_rewrite_signed_line_tax_id(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture();
        $sale->receiptLines->first()->taxes->first()->tax->tax_id = 99;

        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
        $this->assertTrue(true);
    }

    public function test_nineteen_one_cent_lines_round_grouped_tax_to_two_cents(): void
    {
        [$device, $sale, $receipt, $qr] = $this->fixture(19, 15.5, 0.01);

        app(MobileFiscalReceiptService::class)->verifyReceipt($device, $sale, $receipt, '', $qr);
        $this->assertEquals(0.02, $sale->tax_amount);
        $this->assertEquals(0.02, $receipt['receiptTaxes'][0]['taxAmount']);
    }

    private function fixture(int $lineCount = 1, float $rate = 0.0, ?float $perLineTotal = null): array
    {
        $privateKey = openssl_pkey_new($this->openSslOptions(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
        $csr = openssl_csr_new(['commonName' => 'ZIMRA-TEST-0000000123'], $privateKey, $this->openSslOptions());
        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $this->openSslOptions());
        openssl_x509_export($certificate, $certificatePem);

        $device = (new MobileFiscalDevice())->forceFill([
            'device_id' => 123,
            'certificate_pem' => $certificatePem,
            'qr_url' => 'https://fdmstest.zimra.co.zw/',
        ]);
        $now = CarbonImmutable::now('Africa/Harare')->startOfSecond();
        $clientId = '123e4567-e89b-42d3-a456-426614174000';
        $lineTotal = $perLineTotal ?? ($rate > 0 ? 1.15 : 2.00);
        $saleTotal = $lineCount * $lineTotal;
        $taxAmount = $rate > 0
            ? round($lineCount * round($lineTotal * $rate / (100 + $rate), 4), 2)
            : 0.00;
        $sale = (new SalesTransaction())->forceFill([
            'client_sale_id' => $clientId,
            'currency_code' => 'USD',
            'total_amount' => $saleTotal,
            'tax_amount' => $taxAmount,
            'transaction_date' => $now,
        ]);
        $tax = (new Taxe())->forceFill(['tax_id' => 42, 'tax_code' => 'Z', 'rate' => $rate, 'obliged_to_zimra' => true]);
        $saleLines = [];
        $receiptLines = [];
        for ($index = 1; $index <= $lineCount; $index++) {
            $lineTax = (new ReceiptLineTaxe())->forceFill([
                'obliged_to_zimra' => true, 'tax_code' => 'Z', 'tax_percent' => $rate,
                'metadata' => ['fiscal_tax_id' => 42],
            ]);
            $lineTax->setRelation('tax', $tax);
            $line = (new ReceiptLine())->forceFill([
                'line_no' => $index,
                'line_name' => 'Test product',
                'line_quantity' => 1,
                'line_total' => $lineTotal,
                'hs_code' => '0000',
            ]);
            $line->setRelation('taxes', new Collection([$lineTax]));
            $saleLines[] = $line;
            $receiptLines[] = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => $index,
                'receiptLineName' => 'Test product',
                'receiptLineQuantity' => 1,
                'receiptLineTotal' => $lineTotal,
                'taxID' => 42,
                'taxCode' => 'Z',
                'taxPercent' => $rate,
                'receiptLineHSCode' => '0000',
            ];
        }
        $sale->setRelation('receiptLines', new Collection($saleLines));

        $receipt = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => 1,
            'receiptGlobalNo' => 1,
            'invoiceNo' => 'AND-' . strtoupper(str_replace('-', '', $clientId)),
            'receiptDate' => $now->format('Y-m-d\TH:i:s'),
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => $receiptLines,
            'receiptTaxes' => [[
                'taxID' => 42,
                'taxCode' => 'Z',
                'taxPercent' => $rate,
                'taxAmount' => $taxAmount,
                'salesAmountWithTax' => $saleTotal,
            ]],
            'receiptPayments' => [['moneyTypeCode' => 'Cash', 'paymentAmount' => $saleTotal]],
            'receiptTotal' => $saleTotal,
        ];
        $source = app(MobileFiscalReceiptService::class)->signatureSource(123, $receipt, '');
        openssl_sign($source, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256);
        $receipt['receiptDeviceSignature'] = [
            'hash' => base64_encode(hash('sha256', $source, true)),
            'signature' => base64_encode($rawSignature),
        ];
        $qr = 'https://fdmstest.zimra.co.zw/'
            . '0000000123' . $now->format('dmY') . '0000000001'
            . substr(bin2hex(md5($rawSignature, true)), 0, 16);

        return [$device, $sale, $receipt, $qr];
    }
}
