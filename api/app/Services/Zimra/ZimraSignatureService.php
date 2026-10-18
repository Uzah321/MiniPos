<?php
//
//namespace App\Services\Zimra;
//
//use Carbon\Carbon;
//use Modules\FiscalDevice\Models\FiscalDevice;
//
//class ZimraSignatureService
//{
//    public function __construct(private ZimraPathService $pathService)
//    {
//    }
//
//    public function receiptSignature(
//        FiscalDevice $device,
//        array $receipt,
//        string $previousReceiptHash = ''
//    ): array {
//        $receiptTaxes = $receipt['receiptTaxes'] ?? [];
//
//        $source = ''
//            . (string) $device->device_id
//            . strtoupper((string) $receipt['receiptType'])
//            . strtoupper((string) $receipt['receiptCurrency'])
//            . (string) $receipt['receiptGlobalNo']
//            . Carbon::parse($receipt['receiptDate'])->format('Y-m-d\TH:i:s')
//            . $this->amountToCents($receipt['receiptTotal'])
//            . $this->concatenateTaxesForReceiptSignature($receiptTaxes);
//
//        if ($previousReceiptHash !== '') {
//            $source .= trim($previousReceiptHash);
//        }
//
//        return $this->hashAndSign($device, $source);
//    }
//
//    public function fiscalDaySignature(
//        FiscalDevice $device,
//        int $fiscalDayNo,
//        string $fiscalDayDate,
//        array $counters
//    ): array {
//        /*
//         * FDMS v7.2 section 13.3.1:
//         * deviceID || fiscalDayNo || fiscalDayDate || fiscalDayCounters
//         */
//        $source = ''
//            . (string) $device->device_id
//            . (string) $fiscalDayNo
//            . Carbon::parse($fiscalDayDate)->format('Y-m-d')
//            . $this->concatenateCountersForFiscalDaySignature($counters);
//
//        return $this->hashAndSign($device, $source);
//    }
//
//    public function qrData(
//        FiscalDevice $device,
//        string $receiptDate,
//        int $receiptGlobalNo,
//        string $signatureBase64
//    ): array {
//        $qrUrl = $this->normaliseQrUrl($device);
//
//        $deviceId = str_pad((string) $device->device_id, 10, '0', STR_PAD_LEFT);
//        $receiptDateFormatted = Carbon::parse($receiptDate)->format('dmY');
//        $receiptGlobalNoFormatted = str_pad((string) $receiptGlobalNo, 10, '0', STR_PAD_LEFT);
//
//        $signatureBinary = base64_decode($signatureBase64, true);
//
//        if ($signatureBinary === false) {
//            throw new \Exception('Invalid base64 receipt signature. QR code cannot be generated.');
//        }
//
//        $receiptQrData = substr(bin2hex(md5($signatureBinary, true)), 0, 16);
//
//        return [
//            'qrUrl' => $qrUrl,
//            'deviceID' => $deviceId,
//            'receiptDate' => $receiptDateFormatted,
//            'receiptGlobalNo' => $receiptGlobalNoFormatted,
//            'receiptQrData' => $receiptQrData,
//            'qrString' => $qrUrl . $deviceId . $receiptDateFormatted . $receiptGlobalNoFormatted . $receiptQrData,
//        ];
//    }
//
//    public function concatenateCountersForFiscalDaySignature(array $counters): string
//    {
//        $normalised = collect($counters)
//            ->filter(fn ($counter) => round((float) ($counter['fiscalCounterValue'] ?? 0), 4) != 0.0)
//            ->map(function ($counter) {
//                return [
//                    'fiscalCounterType' => (string) ($counter['fiscalCounterType'] ?? ''),
//                    'fiscalCounterCurrency' => strtoupper((string) ($counter['fiscalCounterCurrency'] ?? '')),
//                    'fiscalCounterTaxID' => array_key_exists('fiscalCounterTaxID', $counter)
//                        ? (int) $counter['fiscalCounterTaxID']
//                        : null,
//                    'fiscalCounterTaxPercent' => array_key_exists('fiscalCounterTaxPercent', $counter)
//                        ? $counter['fiscalCounterTaxPercent']
//                        : null,
//                    'fiscalCounterMoneyType' => $counter['fiscalCounterMoneyType'] ?? null,
//                    'fiscalCounterValue' => (float) ($counter['fiscalCounterValue'] ?? 0),
//                ];
//            })
//            ->sort(function ($a, $b) {
//                /*
//                 * FDMS v7.2 section 13.3.1:
//                 * sort by counter type, currency, then taxID for byTax or moneyType for balance.
//                 */
//                $type = strcmp(strtoupper($a['fiscalCounterType']), strtoupper($b['fiscalCounterType']));
//                if ($type !== 0) {
//                    return $type;
//                }
//
//                $currency = strcmp($a['fiscalCounterCurrency'], $b['fiscalCounterCurrency']);
//                if ($currency !== 0) {
//                    return $currency;
//                }
//
//                $aThird = $a['fiscalCounterTaxID'] !== null
//                    ? str_pad((string) $a['fiscalCounterTaxID'], 12, '0', STR_PAD_LEFT)
//                    : strtoupper((string) $a['fiscalCounterMoneyType']);
//
//                $bThird = $b['fiscalCounterTaxID'] !== null
//                    ? str_pad((string) $b['fiscalCounterTaxID'], 12, '0', STR_PAD_LEFT)
//                    : strtoupper((string) $b['fiscalCounterMoneyType']);
//
//                return strcmp($aThird, $bThird);
//            })
//            ->values();
//
//        $value = '';
//
//        foreach ($normalised as $counter) {
//            $type = strtoupper($counter['fiscalCounterType']);
//            $currency = strtoupper($counter['fiscalCounterCurrency']);
//
//            $value .= $type;
//            $value .= $currency;
//
//            if ($this->isByTaxCounter($type)) {
//                /*
//                 * FDMS signature concatenation uses taxPercent, not taxID.
//                 * taxID is for payload + sorting.
//                 * Exempt tax percent is omitted.
//                 */
//                if ($counter['fiscalCounterTaxPercent'] !== null && $counter['fiscalCounterTaxPercent'] !== '') {
//                    $value .= number_format((float) $counter['fiscalCounterTaxPercent'], 2, '.', '');
//                }
//            } else {
//                $value .= strtoupper((string) $counter['fiscalCounterMoneyType']);
//            }
//
//            $value .= $this->amountToCents($counter['fiscalCounterValue']);
//        }
//
//        return $value;
//    }
//
//    private function concatenateTaxesForReceiptSignature(array $receiptTaxes): string
//    {
//        usort($receiptTaxes, function ($a, $b) {
//            $aTaxId = $a['taxID'] ?? 0;
//            $bTaxId = $b['taxID'] ?? 0;
//
//            return $aTaxId === $bTaxId
//                ? strcmp($a['taxCode'] ?? '', $b['taxCode'] ?? '')
//                : $aTaxId <=> $bTaxId;
//        });
//
//        $result = '';
//
//        foreach ($receiptTaxes as $tax) {
//            $taxCode = $tax['taxCode'] ?? '';
//
//            $taxPercent = array_key_exists('taxPercent', $tax) && $tax['taxPercent'] !== null && $tax['taxPercent'] !== ''
//                ? number_format((float) $tax['taxPercent'], 2, '.', '')
//                : '';
//
//            $result .= $taxCode;
//            $result .= $taxPercent;
//            $result .= $this->amountToCents($tax['taxAmount'] ?? 0);
//            $result .= $this->amountToCents($tax['salesAmountWithTax'] ?? 0);
//        }
//
//        return $result;
//    }
//
//    private function hashAndSign(FiscalDevice $device, string $source): array
//    {
//        $hash = base64_encode(hash('sha256', $source, true));
//
//        $privateKeyPath = $this->pathService->absolute(
//            $device->private_key_path ?: ($device->key_path ?? '')
//        );
//
//        if (!is_file($privateKeyPath)) {
//            throw new \Exception("Fiscal device private key file not found at base path: {$device->private_key_path}");
//        }
//
//        $privateKeyPem = file_get_contents($privateKeyPath);
//
//        $privateKey = openssl_pkey_get_private(
//            $privateKeyPem,
//            $device->certificate_password ?: null
//        );
//
//        if (!$privateKey) {
//            throw new \Exception('Invalid fiscal device RSA private key or password.');
//        }
//
//        if (!openssl_sign($source, $signatureBinary, $privateKey, OPENSSL_ALGO_SHA256)) {
//            throw new \Exception('Could not sign ZIMRA payload.');
//        }
//
//        return [
//            'success' => true,
//            'hash' => $hash,
//            'signature' => base64_encode($signatureBinary),
//            'string_used_for_hash' => $source,
//            'source' => $source,
//        ];
//    }
//
//    private function isByTaxCounter(string $counterType): bool
//    {
//        return str_contains(strtoupper($counterType), 'BYTAX');
//    }
//
//    private function normaliseQrUrl(FiscalDevice $device): string
//    {
//        $qrUrl = $device->qr_url;
//
//        if (!$qrUrl) {
//            $environment = $device->environment ?: config('zimra.default_environment', 'testing');
//
//            $qrUrl = $environment === 'production'
//                ? (string) config('zimra.production_qr_url', 'https://fdms.zimra.co.zw/')
//                : (string) config('zimra.testing_qr_url', 'https://fdmstest.zimra.co.zw/');
//        }
//
//        return rtrim($qrUrl, '/') . '/';
//    }
//
//    private function amountToCents($amount): string
//    {
//        if (function_exists('bcmul')) {
//            return bcmul((string) $amount, '100', 0);
//        }
//
//        return (string) (int) round(((float) $amount) * 100);
//    }
//}


namespace App\Services\Zimra;

use Carbon\Carbon;
use Modules\FiscalDevice\Models\FiscalDevice;

class ZimraSignatureService
{
    public function __construct(private ZimraPathService $pathService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIPT SIGNATURE
    |--------------------------------------------------------------------------
    | Keep this exactly as the working invoice signature flow.
    | Do not change receipt signature to openssl_private_encrypt().
    */
    public function receiptSignature(
        FiscalDevice $device,
        array        $receipt,
        string       $previousReceiptHash = ''
    ): array
    {
        $source = ''
            . (string)$device->device_id
            . strtoupper((string)$receipt['receiptType'])
            . strtoupper((string)$receipt['receiptCurrency'])
            . (string)$receipt['receiptGlobalNo']
            . Carbon::parse($receipt['receiptDate'])->format('Y-m-d\TH:i:s')
            . $this->amountToCents($receipt['receiptTotal'])
            . $this->concatenateTaxesForReceiptSignature($receipt['receiptTaxes'] ?? []);

        if ($previousReceiptHash !== '') {
            $source .= trim($previousReceiptHash);
        }

        return $this->hashAndSign($device, $source);
    }

    /*
    |--------------------------------------------------------------------------
    | FISCAL DAY DEVICE SIGNATURE
    |--------------------------------------------------------------------------
    | This is separate from receipt signature.
    |
    | FDMS closeDay source string:
    | deviceID || fiscalDayNo || fiscalDayDate || fiscalDayCounters
    |
    | fiscalDayDate must be YYYY-MM-DD, taken from the date the fiscal day opened.
    */
    public function fiscalDaySignature(
        FiscalDevice $device,
        int          $fiscalDayNo,
        string       $fiscalDayOpenedDate,
        array        $counters
    ): array
    {
        $counterString = $this->concatenateCountersForFiscalDaySignature($counters);

        $source = ''
            . (string)$device->device_id
            . (string)$fiscalDayNo
            . Carbon::parse($fiscalDayOpenedDate)->format('Y-m-d')
            . $counterString;

        $signature = $this->hashAndSign($device, $source);

        return [
            ...$signature,
            'counter_string' => $counterString,
            'fiscal_day_date' => Carbon::parse($fiscalDayOpenedDate)->format('Y-m-d'),
            'signature_type' => 'fiscal_day_device_signature',
        ];
    }

    public function qrData(
        FiscalDevice $device,
        string       $receiptDate,
        int          $receiptGlobalNo,
        string       $signatureBase64
    ): array
    {
        $qrUrl = $this->normaliseQrUrl($device);

        $deviceId = str_pad((string)$device->device_id, 10, '0', STR_PAD_LEFT);
        $receiptDateFormatted = Carbon::parse($receiptDate)->format('dmY');
        $receiptGlobalNoFormatted = str_pad((string)$receiptGlobalNo, 10, '0', STR_PAD_LEFT);

        $signatureBinary = base64_decode($signatureBase64, true);

        if ($signatureBinary === false) {
            throw new \Exception('Invalid base64 receipt signature. QR code cannot be generated.');
        }

        $receiptQrData = substr(bin2hex(md5($signatureBinary, true)), 0, 16);

        return [
            'qrUrl' => $qrUrl,
            'deviceID' => $deviceId,
            'receiptDate' => $receiptDateFormatted,
            'receiptGlobalNo' => $receiptGlobalNoFormatted,
            'receiptQrData' => $receiptQrData,
            'qrString' => $qrUrl . $deviceId . $receiptDateFormatted . $receiptGlobalNoFormatted . $receiptQrData,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Counter concatenation for closeDay signature
    |--------------------------------------------------------------------------
    | Important:
    | - Only non-zero counters.
    | - Text values upper case.
    | - Amounts represented in cents.
    | - For byTax counters: concatenate taxPercent, not taxID.
    | - For exempt: taxPercent is empty.
    | - Sorting uses FDMS counter order first, then currency, then taxID/moneyType.
    |
    | The previous implementation used pure alphabetic type sorting. In practice
    | this can create a signature source that does not match FDMS examples.
    */
    public function concatenateCountersForFiscalDaySignature(array $counters): string
    {
        $normalised = $this->normaliseAndSortFiscalCounters($counters);

        $value = '';

        foreach ($normalised as $counter) {
            $type = strtoupper($counter['fiscalCounterType']);
            $currency = strtoupper($counter['fiscalCounterCurrency']);

            $value .= $type;
            $value .= $currency;

            if ($this->isByTaxCounter($type)) {
                if ($counter['fiscalCounterTaxPercent'] !== null && $counter['fiscalCounterTaxPercent'] !== '') {
                    $value .= number_format((float)$counter['fiscalCounterTaxPercent'], 2, '.', '');
                }
            } else {
                $value .= strtoupper((string)$counter['fiscalCounterMoneyType']);
            }

            $value .= $this->amountToCents($counter['fiscalCounterValue']);
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Use this same sorted payload order when sending closeDay.
    |--------------------------------------------------------------------------
    */
    public function sortFiscalCountersForPayload(array $counters): array
    {
        return array_map(function ($counter) {
            $payload = [
                'fiscalCounterType' => $counter['fiscalCounterType'],
                'fiscalCounterCurrency' => strtoupper($counter['fiscalCounterCurrency']),
                'fiscalCounterValue' => round((float)$counter['fiscalCounterValue'], 2),
            ];

            if ($this->isByTaxCounter($counter['fiscalCounterType'])) {
                $payload['fiscalCounterTaxID'] = (int)$counter['fiscalCounterTaxID'];

                if ($counter['fiscalCounterTaxPercent'] !== null && $counter['fiscalCounterTaxPercent'] !== '') {
                    $payload['fiscalCounterTaxPercent'] = round((float)$counter['fiscalCounterTaxPercent'], 2);
                }
            } else {
                $payload['fiscalCounterMoneyType'] = strtoupper((string)$counter['fiscalCounterMoneyType']);
            }

            return $payload;
        }, $this->normaliseAndSortFiscalCounters($counters));
    }

    private function normaliseAndSortFiscalCounters(array $counters): array
    {
        return collect($counters)
            ->filter(fn($counter) => round((float)($counter['fiscalCounterValue'] ?? 0), 4) != 0.0)
            ->map(function ($counter) {
                $type = $counter['fiscalCounterType'] ?? $counter['fiscal_counter_type'] ?? '';
                $currency = $counter['fiscalCounterCurrency'] ?? $counter['fiscal_counter_currency'] ?? '';
                $taxId = $counter['fiscalCounterTaxID'] ?? $counter['fiscal_counter_tax_id'] ?? null;
                $taxPercent = $counter['fiscalCounterTaxPercent'] ?? $counter['fiscal_counter_tax_percent'] ?? null;
                $moneyType = $counter['fiscalCounterMoneyType'] ?? $counter['fiscal_counter_money_type'] ?? null;
                $counterValue = $counter['fiscalCounterValue'] ?? $counter['fiscal_counter_value'] ?? 0;

                return [
                    'fiscalCounterType' => $this->normaliseCounterType($type),
                    'fiscalCounterCurrency' => strtoupper((string)$currency),
                    'fiscalCounterTaxID' => $taxId === null || $taxId === '' ? null : (int)$taxId,
                    'fiscalCounterTaxPercent' => $taxPercent === null || $taxPercent === '' ? null : (float)$taxPercent,
                    'fiscalCounterMoneyType' => $moneyType === null ? null : strtoupper((string)$moneyType),
                    'fiscalCounterValue' => (float)$counterValue,
                ];
            })
            ->sort(function ($a, $b) {
                $aOrder = $this->counterTypeOrder($a['fiscalCounterType']);
                $bOrder = $this->counterTypeOrder($b['fiscalCounterType']);

                if ($aOrder !== $bOrder) {
                    return $aOrder <=> $bOrder;
                }

                $currency = strcmp($a['fiscalCounterCurrency'], $b['fiscalCounterCurrency']);
                if ($currency !== 0) {
                    return $currency;
                }

                if ($this->isByTaxCounter($a['fiscalCounterType'])) {
                    return ((int)$a['fiscalCounterTaxID']) <=> ((int)$b['fiscalCounterTaxID']);
                }

                return strcmp((string)$a['fiscalCounterMoneyType'], (string)$b['fiscalCounterMoneyType']);
            })
            ->values()
            ->all();
    }

    private function normaliseCounterType(string $type): string
    {
        return match (strtoupper(str_replace(['_', '-', ' '], '', $type))) {
            'SALEBYTAX' => 'SaleByTax',
            'SALETAXBYTAX' => 'SaleTaxByTax',
            'CREDITNOTEBYTAX' => 'CreditNoteByTax',
            'CREDITNOTETAXBYTAX' => 'CreditNoteTaxByTax',
            'DEBITNOTEBYTAX' => 'DebitNoteByTax',
            'DEBITNOTETAXBYTAX' => 'DebitNoteTaxByTax',
            'BALANCEBYMONEYTYPE' => 'BalanceByMoneyType',
            default => $type,
        };
    }

    private function counterTypeOrder(string $type): int
    {
        /*
         * This follows the FDMS examples where SaleByTax/SaleTaxByTax appear
         * before BalanceByMoneyType. It avoids PHP alphabetical sorting where
         * BalanceByMoneyType would come first.
         */
        return match ($this->normaliseCounterType($type)) {
            'SaleByTax' => 10,
            'SaleTaxByTax' => 20,
            'CreditNoteByTax' => 30,
            'CreditNoteTaxByTax' => 40,
            'DebitNoteByTax' => 50,
            'DebitNoteTaxByTax' => 60,
            'BalanceByMoneyType' => 70,
            default => 999,
        };
    }

    private function concatenateTaxesForReceiptSignature(array $receiptTaxes): string
    {
        usort($receiptTaxes, function ($a, $b) {
            $aTaxId = $a['taxID'] ?? 0;
            $bTaxId = $b['taxID'] ?? 0;

            return $aTaxId === $bTaxId
                ? strcmp($a['taxCode'] ?? '', $b['taxCode'] ?? '')
                : $aTaxId <=> $bTaxId;
        });

        $result = '';

        foreach ($receiptTaxes as $tax) {
            $taxPercent = array_key_exists('taxPercent', $tax) && $tax['taxPercent'] !== null && $tax['taxPercent'] !== ''
                ? number_format((float)$tax['taxPercent'], 2, '.', '')
                : '';

            $result .= $tax['taxCode'] ?? '';
            $result .= $taxPercent;
            $result .= $this->amountToCents($tax['taxAmount'] ?? 0);
            $result .= $this->amountToCents($tax['salesAmountWithTax'] ?? 0);
        }

        return $result;
    }

    private function hashAndSign(FiscalDevice $device, string $source): array
    {
        /*
         * Keep this same as your working receipt signature.
         */
        $hash = base64_encode(hash('sha256', $source, true));

        $privateKeyPath = $this->pathService->absolute(
            $device->private_key_path ?: ($device->key_path ?? '')
        );

        if (!is_file($privateKeyPath)) {
            throw new \Exception("Fiscal device private key file not found at base path: {$device->private_key_path}");
        }

        $privateKeyPem = file_get_contents($privateKeyPath);

        $privateKey = openssl_pkey_get_private(
            $privateKeyPem,
            $device->certificate_password ?: null
        );

        if (!$privateKey) {
            throw new \Exception('Invalid fiscal device RSA private key or password.');
        }

        if (!openssl_sign($source, $signatureBinary, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \Exception('Could not sign ZIMRA payload.');
        }

        return [
            'success' => true,
            'hash' => $hash,
            'signature' => base64_encode($signatureBinary),
            'string_used_for_hash' => $source,
            'source' => $source,
            'signature_method' => 'openssl_sign_source_sha256',
        ];
    }

    private function isByTaxCounter(string $counterType): bool
    {
        return str_contains(strtoupper($counterType), 'BYTAX');
    }

    private function normaliseQrUrl(FiscalDevice $device): string
    {
        $qrUrl = $device->qr_url;

        if (!$qrUrl) {
            $environment = $device->environment ?: config('zimra.default_environment', 'testing');

            $qrUrl = $environment === 'production'
                ? (string)config('zimra.production_qr_url', 'https://fdms.zimra.co.zw/')
                : (string)config('zimra.testing_qr_url', 'https://fdmstest.zimra.co.zw/');
        }

        return rtrim($qrUrl, '/') . '/';
    }

    private function amountToCents($amount): string
    {
        $number = round((float)$amount, 2);

        if (function_exists('bcmul')) {
            return bcmul(number_format($number, 2, '.', ''), '100', 0);
        }

        return (string)(int)round($number * 100);
    }
}
