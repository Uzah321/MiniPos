<?php

namespace App\Services\Zimra;

use App\Models\MobileFiscalDevice;
use App\Models\MobileFiscalFileConfirmation;
use App\Models\MobileFiscalReceipt;
use App\Models\SyncNode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesTransaction;

/**
 * Verifies and stores an Android-signed receipt without possessing its private
 * key. The paired node signs the HTTP body; this class independently verifies
 * the FDMS signature against the administrator-registered X.509 public key.
 */
class MobileFiscalReceiptService
{
    public function registrationFor(SyncNode $node): ?MobileFiscalDevice
    {
        return MobileFiscalDevice::query()
            ->where('sync_node_id', $node->id)
            ->where('company_id', $node->company_id)
            ->where('branch_id', $node->branch_id)
            ->where('till_id', $node->till_id)
            ->where('is_active', true)
            ->first();
    }

    /** Must be called inside the sale transaction, after server totals/lines exist. */
    public function attach(SyncNode $node, SalesTransaction $sale, array $proof): MobileFiscalReceipt
    {
        $device = $this->registrationFor($node);
        if (! $device) {
            throw new \InvalidArgumentException('This Android terminal has no active ZIMRA public certificate registered in Back Office.');
        }
        if ($node->deployment_mode !== 'android' || $node->status !== 'active'
            || $node->revoked_at || (string) $sale->company_id !== (string) $node->company_id
            || (string) $sale->branch_id !== (string) $node->branch_id
            || (string) $sale->till_id !== (string) $node->till_id) {
            throw new \InvalidArgumentException('The fiscal device is not assigned to this active Android terminal and till.');
        }

        $receipt = data_get($proof, 'payload.receipt');
        if (! is_array($receipt) || count($receipt) < 10) {
            throw new \InvalidArgumentException('The immutable, signed FDMS receipt payload is required.');
        }
        $dayNo = filter_var($proof['fiscal_day_no'] ?? null, FILTER_VALIDATE_INT);
        $counter = filter_var($receipt['receiptCounter'] ?? null, FILTER_VALIDATE_INT);
        $globalNo = filter_var($receipt['receiptGlobalNo'] ?? null, FILTER_VALIDATE_INT);
        if (! $dayNo || ! $counter || ! $globalNo || $dayNo < 1 || $counter < 1 || $globalNo < 1) {
            throw new \InvalidArgumentException('Fiscal day and receipt counters must be positive integers.');
        }
        $previousHash = trim((string) ($proof['previous_receipt_hash'] ?? ''));
        $this->verifySequence($device, $dayNo, $counter, $globalNo, $previousHash);
        $this->verifyReceipt($device, $sale, $receipt, $previousHash, (string) ($proof['qr_string'] ?? ''));

        $signature = $receipt['receiptDeviceSignature'];
        $buyer = $receipt['buyerData'] ?? null;
        $record = MobileFiscalReceipt::query()->create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'till_id' => $sale->till_id,
            'sync_node_id' => $node->id,
            'mobile_fiscal_device_id' => $device->id,
            'certificate_sha256' => $device->certificate_sha256,
            'certificate_pem' => $device->certificate_pem,
            'sales_transaction_id' => $sale->id,
            'client_sale_id' => (string) $sale->client_sale_id,
            'device_id' => $device->device_id,
            'fiscal_day_no' => $dayNo,
            'receipt_counter' => $counter,
            'receipt_global_no' => $globalNo,
            'invoice_no' => (string) $receipt['invoiceNo'],
            'receipt_currency' => (string) $receipt['receiptCurrency'],
            'receipt_total' => round((float) $receipt['receiptTotal'], 2),
            'previous_receipt_hash' => $previousHash ?: null,
            'receipt_hash' => (string) $signature['hash'],
            'receipt_signature' => (string) $signature['signature'],
            'qr_string' => (string) $proof['qr_string'],
            'payload' => $proof['payload'],
            'status' => 'device_pending',
        ]);

        $buyerSaleFields = [];
        if (is_array($buyer)) {
            if (in_array(trim((string) $sale->customer_name), ['', 'Walk-in Customer'], true)) {
                $buyerSaleFields['customer_name'] = trim($buyer['buyerRegisterName']);
            }
            if (trim((string) $sale->customer_tax_number) === '') {
                $buyerSaleFields['customer_tax_number'] = trim($buyer['buyerTIN']);
            }
            if (trim((string) $sale->customer_phone) === '') {
                $phone = trim((string) data_get($buyer, 'buyerContacts.phoneNo', ''));
                if ($phone !== '') {
                    $buyerSaleFields['customer_phone'] = $phone;
                }
            }
        }

        $sale->forceFill(array_merge([
            'invoice_number' => $record->invoice_no,
            'is_fiscalised' => false,
            'fiscal_status' => 'device_pending',
            'fiscal_error' => null,
            'receipt_counter' => $counter,
            'receipt_global_no' => $globalNo,
            'qr_url' => $device->qr_url,
            'qr_code_data' => $record->qr_string,
            'zimra_signature_hash' => $record->receipt_hash,
            'fiscal_response' => json_encode([
                'source' => 'android_local_signed',
                'device_id' => $device->device_id,
                'fiscal_day_no' => $dayNo,
                'certificate_sha256' => $record->certificate_sha256,
            ], JSON_THROW_ON_ERROR),
        ], $buyerSaleFields))->save();

        return $record;
    }

    public function acknowledge(SyncNode $node, string $clientSaleId, string $receiptHash, array $response): MobileFiscalReceipt
    {
        return DB::transaction(function () use ($node, $clientSaleId, $receiptHash, $response) {
            $record = MobileFiscalReceipt::query()
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('till_id', $node->till_id)
                ->where('sync_node_id', $node->id)
                ->where('client_sale_id', $clientSaleId)
                ->lockForUpdate()->first();
            if (! $record) {
                throw new \InvalidArgumentException('The signed fiscal receipt must be uploaded with its sale before acknowledging FDMS.');
            }
            if (! hash_equals((string) $record->receipt_hash, $receiptHash)) {
                throw new \InvalidArgumentException('The FDMS acknowledgement does not match the immutable signed receipt hash.');
            }
            $device = $this->registrationFor($node);
            if (! $device || $device->device_operating_mode !== 'Online'
                || (string) $device->id !== (string) $record->mobile_fiscal_device_id) {
                throw new \InvalidArgumentException('Only an Online-mode device may acknowledge individual SubmitReceipt responses.');
            }
            $fdmsId = trim((string) ($response['receiptID'] ?? ''));
            $operationId = trim((string) ($response['operationID'] ?? ''));
            if ($fdmsId === '' || $operationId === '') {
                throw new \InvalidArgumentException('The FDMS receipt and operation IDs are required.');
            }
            if ($record->status === 'device_reported_submitted') {
                if ((string) $record->fdms_receipt_id !== $fdmsId || (string) $record->operation_id !== $operationId) {
                    throw new \InvalidArgumentException('The immutable FDMS acknowledgement conflicts with an earlier acknowledgement.');
                }
                return $record;
            }
            if ($record->status !== 'device_pending') {
                throw new \InvalidArgumentException('This fiscal receipt is not awaiting FDMS acknowledgement.');
            }
            $record->forceFill([
                'status' => 'device_reported_submitted',
                'fdms_receipt_id' => $fdmsId,
                'operation_id' => $operationId,
                'fdms_response' => $response,
                'device_reported_submitted_at' => now(),
            ])->save();
            SalesTransaction::query()->whereKey($record->sales_transaction_id)
                ->where('company_id', $node->company_id)
                ->update([
                    'fiscal_status' => 'device_reported_submitted',
                    'fdms_receipt_id' => $fdmsId,
                    'fiscal_response' => json_encode([
                        'source' => 'android_device_reported_fdms',
                        'device_id' => $record->device_id,
                        'fiscal_day_no' => $record->fiscal_day_no,
                        'receipt_id' => $fdmsId,
                        'operation_id' => $operationId,
                        'centrally_verified' => false,
                    ], JSON_THROW_ON_ERROR),
                ]);
            // A signed node's report is not independent Central-to-FDMS
            // verification. Leave is_fiscalised=false until such verification.
            return $record->fresh();
        });
    }

    /**
     * A formal FDMS Offline-mode file has a file-level operation/status, not a
     * per-receipt receiptID. Keep that distinction in the cloud ledger.
     */
    public function confirmFile(
        SyncNode $node,
        int $fiscalDayNo,
        int $fileSequence,
        string $fileHash,
        string $operationId,
        array $statusResponse,
        array $receiptHashes
    ): MobileFiscalFileConfirmation {
        return DB::transaction(function () use ($node, $fiscalDayNo, $fileSequence, $fileHash, $operationId, $statusResponse, $receiptHashes) {
            $device = $this->registrationFor($node);
            if (! $device || $device->device_operating_mode !== 'Offline') {
                // Confirmation of an earlier, signed fiscal file may arrive
                // after the certificate expires. Expiry blocks fresh device
                // ownership/sales, not upload of already issued evidence.
                throw new \InvalidArgumentException('A registered Offline-mode ZIMRA device is required.');
            }
            if ($fiscalDayNo < 1 || $fileSequence < 1 || ! preg_match('/^[a-f0-9]{64}$/', $fileHash)
                || count($receiptHashes) > 1000
                || count(array_unique($receiptHashes)) !== count($receiptHashes)) {
                throw new \InvalidArgumentException('The FDMS file sequence, hash or ordered receipt hashes are invalid.');
            }
            $matched = collect($statusResponse['fileStatus'] ?? [])
                ->first(fn ($status) => is_array($status)
                    && (string) ($status['operationID'] ?? '') === $operationId
                    && (int) ($status['fiscalDayNo'] ?? 0) === $fiscalDayNo
                    && (int) ($status['fileSequence'] ?? 0) === $fileSequence);
            if (! $matched || ($matched['fileProcessingStatus'] ?? null) !== 'FileProcessingIsSuccessful'
                || ! empty($matched['fileProcessingErrorCode'])) {
                throw new \InvalidArgumentException('FDMS GetFileStatus has not reported successful processing of this exact file.');
            }

            $existing = MobileFiscalFileConfirmation::query()
                ->where('device_id', $device->device_id)
                ->where('fiscal_day_no', $fiscalDayNo)
                ->where('file_sequence', $fileSequence)
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->file_sha256, $fileHash)
                    || (string) $existing->operation_id !== $operationId
                    || $existing->receipt_hashes !== array_values($receiptHashes)) {
                    throw new \InvalidArgumentException('This FDMS file sequence conflicts with an existing confirmation.');
                }
                return $existing;
            }
            $previousFile = MobileFiscalFileConfirmation::query()
                ->where('device_id', $device->device_id)
                ->where('fiscal_day_no', $fiscalDayNo)
                ->orderByDesc('file_sequence')->lockForUpdate()->first();
            if ($fileSequence !== ($previousFile ? (int) $previousFile->file_sequence + 1 : 1)) {
                throw new \InvalidArgumentException('FDMS files must be confirmed in sequence.');
            }
            $records = MobileFiscalReceipt::query()
                ->where('sync_node_id', $node->id)
                ->where('company_id', $node->company_id)
                ->where('branch_id', $node->branch_id)
                ->where('till_id', $node->till_id)
                ->where('device_id', $device->device_id)
                ->where('fiscal_day_no', $fiscalDayNo)
                ->whereIn('receipt_hash', $receiptHashes)
                ->orderBy('receipt_global_no')
                ->lockForUpdate()->get();
            if ($records->count() !== count($receiptHashes)
                || $records->pluck('receipt_hash')->all() !== array_values($receiptHashes)
                || $records->contains(fn ($record) => $record->status !== 'device_pending')) {
                throw new \InvalidArgumentException('The file receipts must match this terminal’s pending, immutable fiscal chain in order.');
            }
            if ($records->isEmpty()) {
                if (MobileFiscalReceipt::query()->where('device_id', $device->device_id)
                    ->where('fiscal_day_no', $fiscalDayNo)
                    ->where('status', 'device_pending')->exists()) {
                    throw new \InvalidArgumentException('Confirm all sale-containing files before the footer-only FDMS file.');
                }
            } elseif (MobileFiscalReceipt::query()->where('device_id', $device->device_id)
                ->where('fiscal_day_no', $fiscalDayNo)
                ->where('status', 'device_pending')
                ->where('receipt_global_no', '<', $records->first()->receipt_global_no)
                ->exists()) {
                throw new \InvalidArgumentException('An earlier receipt on this fiscal day must be included in a previous FDMS file first.');
            }
            for ($i = 1; $i < $records->count(); $i++) {
                if ((int) $records[$i]->receipt_global_no !== (int) $records[$i - 1]->receipt_global_no + 1) {
                    throw new \InvalidArgumentException('The file cannot skip a receipt in this device’s fiscal chain.');
                }
            }

            $confirmation = MobileFiscalFileConfirmation::query()->create([
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'till_id' => $node->till_id,
                'sync_node_id' => $node->id,
                'device_id' => $device->device_id,
                'fiscal_day_no' => $fiscalDayNo,
                'file_sequence' => $fileSequence,
                'file_sha256' => $fileHash,
                'operation_id' => $operationId,
                'receipt_hashes' => array_values($receiptHashes),
                'get_file_status' => $statusResponse,
            ]);
            foreach ($records as $record) {
                $record->forceFill([
                    'status' => 'device_reported_file_processed',
                    'operation_id' => $operationId,
                    'file_sequence' => $fileSequence,
                    'fdms_response' => $statusResponse,
                    'device_reported_submitted_at' => now(),
                ])->save();
                SalesTransaction::query()->whereKey($record->sales_transaction_id)
                    ->where('company_id', $node->company_id)
                    ->update([
                        'fiscal_status' => 'device_reported_file_processed',
                        'fiscal_response' => json_encode([
                            'source' => 'android_device_reported_fdms_file',
                            'device_id' => $device->device_id,
                            'fiscal_day_no' => $fiscalDayNo,
                            'file_sequence' => $fileSequence,
                            'file_sha256' => $fileHash,
                            'operation_id' => $operationId,
                            'centrally_verified' => false,
                        ], JSON_THROW_ON_ERROR),
                    ]);
            }
            return $confirmation;
        });
    }

    private function verifySequence(MobileFiscalDevice $device, int $dayNo, int $counter, int $globalNo, string $previousHash): void
    {
        $latest = MobileFiscalReceipt::query()->where('device_id', $device->device_id)
            ->orderByDesc('receipt_global_no')->lockForUpdate()->first();
        if ($latest && ($globalNo !== (int) $latest->receipt_global_no + 1
            || $dayNo < (int) $latest->fiscal_day_no)) {
            throw new \InvalidArgumentException('The fiscal receipt sequence is out of order for this device.');
        }
        if ($counter === 1) {
            if ($previousHash !== '' || ($latest && $dayNo <= (int) $latest->fiscal_day_no)) {
                throw new \InvalidArgumentException('A new fiscal day must begin with a fresh counter and empty receipt hash chain.');
            }
            return;
        }
        if (! $latest || (int) $latest->fiscal_day_no !== $dayNo
            || (int) $latest->receipt_counter !== $counter - 1
            || ! hash_equals((string) $latest->receipt_hash, $previousHash)) {
            throw new \InvalidArgumentException('The previous receipt hash or fiscal-day counter does not match the stored device chain.');
        }
    }

    public function verifyReceipt(MobileFiscalDevice $device, SalesTransaction $sale, array $receipt, string $previousHash, string $qrString): void
    {
        if (! is_array($receipt['receiptLines'] ?? null)
            || ! is_array($receipt['receiptTaxes'] ?? null)
            || ! is_array($receipt['receiptPayments'] ?? null)
            || ! is_array($receipt['receiptDeviceSignature'] ?? null)
            || ! is_numeric($receipt['receiptGlobalNo'] ?? null)) {
            throw new \InvalidArgumentException('The signed FDMS receipt structure is invalid.');
        }
        if (($receipt['receiptType'] ?? null) !== 'FiscalInvoice'
            || ! empty($receipt['creditDebitNote'])
            || ! (bool) ($receipt['receiptLinesTaxInclusive'] ?? false)) {
            throw new \InvalidArgumentException('A sale must carry a tax-inclusive FiscalInvoice, not a credit or debit note.');
        }
        $this->verifyBuyerData($sale, $receipt);
        $clientId = str_replace('-', '', (string) $sale->client_sale_id);
        $expectedInvoice = 'AND-' . strtoupper($clientId);
        if (! preg_match('/^[0-9a-fA-F]{32}$/', $clientId)
            || ! hash_equals($expectedInvoice, (string) ($receipt['invoiceNo'] ?? ''))
            || strtoupper((string) ($receipt['receiptCurrency'] ?? '')) !== strtoupper((string) $sale->currency_code)
            || self::cents($receipt['receiptTotal'] ?? null) !== self::cents($sale->total_amount)) {
            throw new \InvalidArgumentException('The fiscal invoice number, currency or total does not match this sale.');
        }
        try {
            $receiptDate = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', (string) ($receipt['receiptDate'] ?? ''), 'Africa/Harare');
        } catch (\Throwable) {
            $receiptDate = false;
        }
        if (! $receiptDate || abs($receiptDate->timestamp - $sale->transaction_date->timestamp) > 120) {
            throw new \InvalidArgumentException('The fiscal receipt date does not match the original sale time.');
        }
        $registeredCertificate = openssl_x509_parse((string) $device->certificate_pem);
        if (! is_array($registeredCertificate)
            || $receiptDate->timestamp < (int) ($registeredCertificate['validFrom_time_t'] ?? PHP_INT_MAX)
            || $receiptDate->timestamp >= (int) ($registeredCertificate['validTo_time_t'] ?? 0)) {
            throw new \InvalidArgumentException('The registered device certificate was not valid when this fiscal receipt was signed.');
        }

        $sale->loadMissing('receiptLines.taxes.tax');
        $saleLines = $sale->receiptLines->sortBy('line_no')->values();
        $fiscalLines = array_values($receipt['receiptLines'] ?? []);
        if ($saleLines->count() !== count($fiscalLines) || $fiscalLines === []) {
            throw new \InvalidArgumentException('The fiscal line count differs from the posted sale.');
        }
        foreach ($saleLines as $index => $line) {
            $fiscalLine = $fiscalLines[$index];
            if (! is_array($fiscalLine)) {
                throw new \InvalidArgumentException('A fiscal product line is invalid.');
            }
            $mappedTaxes = $line->taxes->filter(fn ($tax) => (bool) $tax->obliged_to_zimra);
            $tax = $mappedTaxes->first();
            $fiscalTaxId = data_get($tax?->metadata, 'fiscal_tax_id');
            if (! $tax || $mappedTaxes->count() !== 1 || ! is_numeric($fiscalTaxId)
                || (int) $fiscalTaxId !== (int) ($fiscalLine['taxID'] ?? 0)
                || (string) ($fiscalLine['taxCode'] ?? '') !== (string) $tax->tax_code
                || self::percent($fiscalLine['taxPercent'] ?? null) !== self::percent($tax->tax_percent)
                || (string) ($fiscalLine['receiptLineHSCode'] ?? '') !== (string) ($line->hs_code ?? '')
                || (int) ($fiscalLine['receiptLineNo'] ?? 0) !== (int) $line->line_no
                || (string) ($fiscalLine['receiptLineName'] ?? '') !== mb_substr((string) $line->line_name, 0, 200)
                || self::quantity($fiscalLine['receiptLineQuantity'] ?? null) !== self::quantity($line->line_quantity)
                || self::cents($fiscalLine['receiptLineTotal'] ?? null) !== self::cents($line->line_total)
                || ($fiscalLine['receiptLineType'] ?? null) !== 'Sale') {
                throw new \InvalidArgumentException('A fiscal product, quantity, total or ZIMRA tax ID differs from the posted sale.');
            }
        }
        $taxes = $receipt['receiptTaxes'] ?? [];
        $payments = $receipt['receiptPayments'] ?? [];
        if (collect($taxes)->contains(fn ($tax) => ! is_array($tax))
            || collect($payments)->contains(fn ($payment) => ! is_array($payment))) {
            throw new \InvalidArgumentException('A fiscal tax or payment entry is invalid.');
        }
        if (! is_array($taxes) || $taxes === [] || ! is_array($payments) || $payments === []
            || array_sum(array_map(fn ($line) => self::cents($line['receiptLineTotal'] ?? null), $fiscalLines)) !== self::cents($sale->total_amount)
            || array_sum(array_map(fn ($tax) => self::cents($tax['salesAmountWithTax'] ?? null), $taxes)) !== self::cents($sale->total_amount)
            || array_sum(array_map(fn ($tax) => self::cents($tax['taxAmount'] ?? null), $taxes)) !== self::cents($sale->tax_amount)
            || array_sum(array_map(fn ($payment) => self::cents($payment['paymentAmount'] ?? null), $payments)) !== self::cents($sale->total_amount)) {
            throw new \InvalidArgumentException('Fiscal line, tax or payment sums do not match this sale.');
        }

        $signature = $receipt['receiptDeviceSignature'] ?? [];
        $sigBytes = base64_decode((string) ($signature['signature'] ?? ''), true);
        if (! is_array($signature) || ! $sigBytes) {
            throw new \InvalidArgumentException('The receipt has no valid device signature.');
        }
        $source = $this->signatureSource((int) $device->device_id, $receipt, $previousHash);
        $hash = base64_encode(hash('sha256', $source, true));
        $publicKey = openssl_pkey_get_public((string) $device->certificate_pem);
        if (! hash_equals($hash, (string) ($signature['hash'] ?? ''))
            || ! $publicKey || openssl_verify($source, $sigBytes, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \InvalidArgumentException('The fiscal payload hash or registered device-certificate signature is invalid.');
        }
        $expectedQr = rtrim((string) $device->qr_url, '/') . '/'
            . str_pad((string) $device->device_id, 10, '0', STR_PAD_LEFT)
            . $receiptDate->format('dmY')
            . str_pad((string) $receipt['receiptGlobalNo'], 10, '0', STR_PAD_LEFT)
            . substr(bin2hex(md5($sigBytes, true)), 0, 16);
        if (! hash_equals($expectedQr, $qrString)) {
            throw new \InvalidArgumentException('The fiscal QR code differs from this device signature and authoritative URL.');
        }
    }

    public function signatureSource(int $deviceId, array $receipt, string $previousHash): string
    {
        $taxes = $receipt['receiptTaxes'] ?? [];
        usort($taxes, static fn (array $a, array $b): int =>
            ((int) ($a['taxID'] ?? 0) <=> (int) ($b['taxID'] ?? 0))
            ?: strcmp((string) ($a['taxCode'] ?? ''), (string) ($b['taxCode'] ?? '')));
        $taxPart = '';
        foreach ($taxes as $tax) {
            $percent = $tax['taxPercent'] ?? null;
            $taxPart .= (string) ($tax['taxCode'] ?? '')
                . ($percent === null || $percent === '' ? '' : number_format((float) $percent, 2, '.', ''))
                . self::cents($tax['taxAmount'] ?? null)
                . self::cents($tax['salesAmountWithTax'] ?? null);
        }
        return $deviceId
            . strtoupper((string) $receipt['receiptType'])
            . strtoupper((string) $receipt['receiptCurrency'])
            . (string) $receipt['receiptGlobalNo']
            . (string) $receipt['receiptDate']
            . self::cents($receipt['receiptTotal'] ?? null)
            . $taxPart . $previousHash;
    }

    /** FDMS v7.2 RCPT043: the buyer block is optional, but name and TIN are required together. */
    private function verifyBuyerData(SalesTransaction $sale, array $receipt): void
    {
        if (! array_key_exists('buyerData', $receipt) || $receipt['buyerData'] === null) {
            return;
        }

        $buyer = $receipt['buyerData'];
        if (! is_array($buyer)) {
            throw new \InvalidArgumentException('FDMS buyer information must be an object when supplied.');
        }

        $name = $this->buyerText($buyer, 'buyerRegisterName', 250, true);
        $tin = $this->buyerText($buyer, 'buyerTIN', 10, true);
        $this->buyerText($buyer, 'buyerTradeName', 250);
        $this->buyerText($buyer, 'VATNumber', 9);

        $saleName = trim((string) $sale->customer_name);
        if ($saleName !== '' && $saleName !== 'Walk-in Customer' && ! hash_equals($name, $saleName)) {
            throw new \InvalidArgumentException('The FDMS buyer name differs from the posted sale customer.');
        }
        $saleTin = trim((string) $sale->customer_tax_number);
        if ($saleTin !== '' && ! hash_equals($tin, $saleTin)) {
            throw new \InvalidArgumentException('The FDMS buyer TIN differs from the posted sale customer TIN.');
        }

        if (array_key_exists('buyerContacts', $buyer) && $buyer['buyerContacts'] !== null) {
            if (! is_array($buyer['buyerContacts'])) {
                throw new \InvalidArgumentException('FDMS buyer contacts must be an object.');
            }
            $phone = $this->buyerText($buyer['buyerContacts'], 'phoneNo', 20);
            $email = $this->buyerText($buyer['buyerContacts'], 'email', 100);
            if ($phone === '' && $email === '') {
                throw new \InvalidArgumentException('FDMS buyer contacts need a phone number or email address.');
            }
        }

        if (array_key_exists('buyerAddress', $buyer) && $buyer['buyerAddress'] !== null) {
            if (! is_array($buyer['buyerAddress'])) {
                throw new \InvalidArgumentException('FDMS buyer address must be an object.');
            }
            foreach (['province', 'city', 'street', 'houseNo'] as $field) {
                $this->buyerText($buyer['buyerAddress'], $field, 100, true);
            }
        }
    }

    private function buyerText(array $object, string $field, int $maxLength, bool $required = false): string
    {
        $value = $object[$field] ?? null;
        if ($value === null && ! $required) {
            return '';
        }
        if (! is_string($value)) {
            throw new \InvalidArgumentException("FDMS buyer {$field} must be text.");
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new \InvalidArgumentException("FDMS buyer {$field} is required when buyer information is supplied.");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("FDMS buyer {$field} exceeds {$maxLength} characters.");
        }
        return $value;
    }

    private static function cents(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Fiscal money fields must be numeric.');
        }
        $amount = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException('Fiscal amounts must be valid four-decimal accounting values.');
        }
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $negative = str_starts_with($units, '-');
        $fraction = str_pad($fraction, 3, '0');
        $minor = (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
        return ((int) $units * 100) + ($negative ? -1 : 1) * $minor;
    }

    private static function quantity(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Fiscal quantities must be numeric.');
        }
        return number_format((float) $value, 4, '.', '');
    }

    private static function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('The fiscal tax rate is invalid.');
        }
        return number_format((float) $value, 2, '.', '');
    }
}
