<?php
//
//namespace App\Services\Zimra;
//
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\File;
//use SimpleSoftwareIO\QrCode\Facades\QrCode;
//use Modules\FiscalDevice\Models\FiscalDevice;
//use Modules\Sales\Models\FiscalDay;
//use Modules\Sales\Models\SalesTransaction;
//use Modules\ZimraIntegration\Models\ZimraCompanySetting;
//use Modules\ZimraIntegration\Models\ZimraGlobalCounter;
//use Modules\ZimraIntegration\Models\ZimraReceipt;
//
//class ZimraReceiptService
//{
//    public function __construct(
//        private ZimraClient $client,
//        private ZimraSignatureService $signatureService,
//        private ZimraFiscalDayService $fiscalDayService,
//        private ZimraFiscalCounterService $counterService
//    ) {
//    }
//
//    public function submitSale(string $saleId): SalesTransaction
//    {
//        return $this->submitTransaction($saleId, 'sale');
//    }
//
//    public function submitCreditNote(string $cancelSaleId, string $originalSaleId, ?string $reason = null): SalesTransaction
//    {
//        return $this->submitTransaction($cancelSaleId, 'credit_note', $originalSaleId, $reason);
//    }
//
//    private function submitTransaction(
//        string $saleId,
//        string $mode,
//        ?string $originalSaleId = null,
//        ?string $reason = null
//    ): SalesTransaction {
//        return DB::transaction(function () use ($saleId, $mode, $originalSaleId, $reason) {
//            $sale = SalesTransaction::with([
//                'receiptLines.taxes.tax',
//                'cashier',
//                'user',
//            ])
//                ->where('id', $saleId)
//                ->lockForUpdate()
//                ->firstOrFail();
//
//            $setting = ZimraCompanySetting::where('company_id', $sale->company_id)->first();
//
//            if (!$setting || !$setting->enabled) {
//                $sale->update([
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'skipped',
//                    'fiscal_error' => null,
//                ]);
//
//                return $sale->fresh();
//            }
//
//            if ((bool) ($sale->is_fiscalised ?? false)) {
//                return $sale->fresh();
//            }
//
//            $device = $this->findDevice($sale);
//
//            $fiscalDay = FiscalDay::where('fiscal_device_id', $device->id)
//                ->whereIn('status', ['opened', 'close_failed'])
//                ->lockForUpdate()
//                ->first();
//
//            if (!$fiscalDay && $setting->auto_open_day) {
//                $fiscalDay = $this->fiscalDayService->open(
//                    device: $device,
//                    openedBy: $sale->cashier_id ?? $sale->user_id ?? null
//                );
//            }
//
//            if (!$fiscalDay) {
//                $sale->update([
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'failed',
//                    'fiscal_error' => 'No open fiscal day found for this fiscal device.',
//                ]);
//
//                if ($setting->block_sales_without_open_day) {
//                    throw new \Exception('No open fiscal day found for this fiscal device.');
//                }
//
//                return $sale->fresh();
//            }
//
//            $zimraReceipt = ZimraReceipt::where('sales_transaction_id', $sale->id)->first();
//
//            if (!$zimraReceipt) {
//                $zimraReceipt = $this->createLocalZimraReceipt(
//                    sale: $sale,
//                    device: $device,
//                    fiscalDay: $fiscalDay,
//                    mode: $mode,
//                    originalSaleId: $originalSaleId,
//                    reason: $reason
//                );
//            }
//
//            $response = $this->client->post($device, 'SubmitReceipt', $zimraReceipt->payload);
//
//            if (!$response->successful()) {
//                $zimraReceipt->update([
//                    'status' => 'failed',
//                    'response' => $response->json() ?: ['body' => $response->body()],
//                    'error' => $response->body(),
//                ]);
//
//                $sale->update([
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'failed',
//                    'fiscal_response' => $response->json(),
//                    'fiscal_error' => $response->body(),
//                ]);
//
//                if ($setting->block_sale_if_fiscalisation_fails) {
//                    throw new \Exception('ZIMRA SubmitReceipt failed: ' . $response->body());
//                }
//
//                return $sale->fresh();
//            }
//
//            $responseJson = $response->json() ?: [];
//
//            $zimraReceipt->update([
//                'status' => 'submitted',
//                'fdms_receipt_id' => $responseJson['receiptID'] ?? $responseJson['receiptId'] ?? null,
//                'operation_id' => $responseJson['operationID'] ?? $responseJson['operationId'] ?? null,
//                'response' => $responseJson,
//                'submitted_at' => now(),
//                'error' => null,
//            ]);
//
//            /*
//             * Counter table is updated only after FDMS accepts the receipt.
//             */
//            $this->counterService->recordSubmittedReceipt($zimraReceipt->fresh());
//
//            $sale->update([
//                'is_fiscalised' => true,
//                'zimra_submitted' => true,
//                'zimra_submitted_at' => now(),
//                'fiscal_status' => 'success',
//                'fiscalised_at' => now(),
//                'fdms_receipt_id' => $responseJson['receiptID'] ?? $responseJson['receiptId'] ?? null,
//                'fiscal_response' => $responseJson,
//                'fiscal_error' => null,
//                'qr_url' => $zimraReceipt->qr_url,
//                'qr_code_data' => $zimraReceipt->qr_string ?: $zimraReceipt->qr_code_data,
//                'receipt_global_no' => $zimraReceipt->receipt_global_no,
//                'receipt_counter' => $zimraReceipt->receipt_counter,
//                'zimra_signature_hash' => $zimraReceipt->receipt_hash,
//                'fiscal_device_id' => $device->id,
//                'fiscal_day_id' => $fiscalDay->id,
//            ]);
//
//            return $sale->fresh(['receiptLines.taxes']);
//        });
//    }
//
//    private function createLocalZimraReceipt(
//        SalesTransaction $sale,
//        FiscalDevice $device,
//        FiscalDay $fiscalDay,
//        string $mode,
//        ?string $originalSaleId,
//        ?string $reason
//    ): ZimraReceipt {
//        $receiptCounter = ((int) $fiscalDay->receipt_counter) + 1;
//
//        $counter = ZimraGlobalCounter::firstOrCreate(
//            [
//                'company_id' => $sale->company_id,
//                'fiscal_device_id' => $device->id,
//            ],
//            [
//                'device_id' => $device->device_id,
//                'next_receipt_global_no' => 1,
//            ]
//        );
//
//        $counter = ZimraGlobalCounter::where('id', $counter->id)
//            ->lockForUpdate()
//            ->first();
//
//        $latestUsedGlobalNo = ZimraReceipt::where('device_id', $device->device_id)
//            ->lockForUpdate()
//            ->max('receipt_global_no');
//
//        $receiptGlobalNo = max(
//            (int) $counter->next_receipt_global_no,
//            ((int) $latestUsedGlobalNo) + 1
//        );
//
////        $counter = ZimraGlobalCounter::firstOrCreate(
////            [
////                'company_id' => $sale->company_id,
////                'fiscal_device_id' => $device->id,
////            ],
////            [
////                'device_id' => $device->device_id,
////                'next_receipt_global_no' => 1,
////            ]
////        );
//
////        $counter = ZimraGlobalCounter::where('id', $counter->id)
////            ->lockForUpdate()
////            ->first();
//
////        $receiptGlobalNo = (int) $counter->next_receipt_global_no;
//
//        $previousHash = ZimraReceipt::where('fiscal_device_id', $device->id)
//            ->where('status', 'submitted')
//            ->orderByDesc('receipt_global_no')
//            ->value('receipt_hash') ?? '';
//
//        $originalReceipt = null;
//        $creditDebitNote = null;
//        $receiptNotes = null;
//
//        if ($mode === 'credit_note') {
//            if (!$originalSaleId) {
//                throw new \Exception('Original sale is required for CreditNote.');
//            }
//
//            $originalReceipt = ZimraReceipt::where('sales_transaction_id', $originalSaleId)
//                ->where('status', 'submitted')
//                ->first();
//
//            if (!$originalReceipt) {
//                throw new \Exception('Original FDMS receipt was not found. Cannot create CreditNote.');
//            }
//
//            $receiptNotes = $reason ?: ('Credit note for invoice ' . $originalReceipt->invoice_no);
//
//            $creditDebitNote = [
//                'receiptID' => $originalReceipt->fdms_receipt_id ? (int) $originalReceipt->fdms_receipt_id : null,
//                'deviceID' => (int) $originalReceipt->device_id,
//                'receiptGlobalNo' => (int) $originalReceipt->receipt_global_no,
//                'fiscalDayNo' => (int) $originalReceipt->fiscal_day_no,
//            ];
//
//            /*
//             * Do not send null receiptID if you are using deviceID + receiptGlobalNo + fiscalDayNo.
//             */
//            if (!$creditDebitNote['receiptID']) {
//                unset($creditDebitNote['receiptID']);
//            }
//        }
//
//        $payload = $this->buildReceiptPayload(
//            sale: $sale,
//            receiptCounter: $receiptCounter,
//            receiptGlobalNo: $receiptGlobalNo,
//            mode: $mode,
//            creditDebitNote: $creditDebitNote,
//            receiptNotes: $receiptNotes,
//            originalReceipt: $originalReceipt
//        );
//
//        $signature = $this->signatureService->receiptSignature(
//            device: $device,
//            receipt: $payload['receipt'],
//            previousReceiptHash: $previousHash
//        );
//
//        $payload['receipt']['receiptDeviceSignature'] = [
//            'hash' => $signature['hash'],
//            'signature' => $signature['signature'],
//        ];
//
//        $qr = $this->signatureService->qrData(
//            device: $device,
//            receiptDate: $payload['receipt']['receiptDate'],
//            receiptGlobalNo: $receiptGlobalNo,
//            signatureBase64: $signature['signature']
//        );
//
//        $qrImage = $this->generateReceiptQr($qr['qrString'], $sale);
//
//        $zimraReceipt = ZimraReceipt::create([
//            'company_id' => $sale->company_id,
//            'branch_id' => $sale->branch_id,
//            'fiscal_device_id' => $device->id,
//            'fiscal_day_id' => $fiscalDay->id,
//            'sales_transaction_id' => $sale->id,
//            'original_zimra_receipt_id' => $originalReceipt?->id,
//            'original_sales_transaction_id' => $originalSaleId,
//            'device_id' => $device->device_id,
//            'fiscal_day_no' => $fiscalDay->fiscal_day_no,
//            'receipt_counter' => $receiptCounter,
//            'receipt_global_no' => $receiptGlobalNo,
//            'invoice_no' => $sale->invoice_number,
//            'receipt_type' => $payload['receipt']['receiptType'],
//            'receipt_currency' => $payload['receipt']['receiptCurrency'],
//            'receipt_notes' => $payload['receipt']['receiptNotes'] ?? null,
//            'credit_debit_note' => $payload['receipt']['creditDebitNote'] ?? null,
//            'receipt_hash' => $signature['hash'],
//            'receipt_signature' => $signature['signature'],
//            'qr_url' => $qr['qrUrl'],
//            'qr_code_data' => $qr['receiptQrData'],
//            'qr_string' => $qr['qrString'],
//            'payload' => $payload,
//            'response' => [
//                'qr_code' => $qr,
//                'qr_image' => $qrImage,
//                'invoice_status' => 'local_signed_before_submit',
//            ],
//            'status' => 'pending',
//        ]);
//
//        $fiscalDay->update([
//            'receipt_counter' => $receiptCounter,
//        ]);
//
//        $counter->update([
//            'next_receipt_global_no' => $receiptGlobalNo + 1,
//        ]);
//
//        $sale->update([
//            'fiscal_device_id' => $device->id,
//            'fiscal_day_id' => $fiscalDay->id,
//            'receipt_counter' => $receiptCounter,
//            'receipt_global_no' => $receiptGlobalNo,
//            'qr_url' => $qr['qrUrl'],
//            'qr_code_data' => $qr['qrString'],
//            'zimra_signature_hash' => $signature['hash'],
//            'fiscal_status' => 'pending',
//            'fiscal_response' => [
//                'qr_code' => $qr,
//                'qr_image' => $qrImage,
//                'local_zimra_receipt_id' => $zimraReceipt->id,
//            ],
//        ]);
//
//        return $zimraReceipt;
//    }
//
//    private function buildReceiptPayload(
//        SalesTransaction $sale,
//        int $receiptCounter,
//        int $receiptGlobalNo,
//        string $mode = 'sale',
//        ?array $creditDebitNote = null,
//        ?string $receiptNotes = null,
//        ?ZimraReceipt $originalReceipt = null
//    ): array {
//        $sale->loadMissing([
//            'receiptLines.taxes.tax',
//            'cashier',
//            'user',
//        ]);
//
//        $isCreditNote = $mode === 'credit_note';
//
//        $receiptLines = [];
//        $taxSummary = [];
//        $receiptTotal = 0.0;
//        $lineNo = 1;
//
//        foreach ($sale->receiptLines as $line) {
//            $lineTaxes = collect($line->taxes ?? [])
//                ->filter(fn ($taxItem) => (int) ($taxItem->tax->obliged_to_zimra ?? $taxItem->obliged_to_zimra ?? 0) === 1)
//                ->values();
//
//            $firstTax = $lineTaxes->first();
//
//            if (!$firstTax) {
//                throw new \Exception("No obliged_to_zimra tax found for line '{$line->line_name}'.");
//            }
//
//            $taxId = $this->resolveFdmsTaxId($firstTax);
//            $taxCode = substr($firstTax->tax_code ?: 'VAT', 0, 3);
//            $taxPercent = $this->resolveTaxPercent($firstTax);
//
//            $quantity = abs((float) $line->line_quantity);
//            $unitPrice = abs((float) $line->line_price);
//            $lineGross = round($unitPrice * $quantity, 2);
//            $lineDiscount = round((float) ($line->line_discount ?? 0), 2);
//
//            if ($isCreditNote) {
//                /*
//                 * For CreditNote, send net sale lines as negative Sale lines.
//                 * Do not add separate Discount line here. The original invoice had
//                 * discount lines; the credit note reverses the final discounted value.
//                 */
//                $lineTotal = -1 * abs((float) $line->line_total);
//                $receiptLines[] = $this->receiptLinePayload(
//                    type: 'Sale',
//                    lineNo: $lineNo++,
//                    line: $line,
//                    price: -1 * abs((float) $line->line_price),
//                    quantity: $quantity,
//                    total: $lineTotal,
//                    taxCode: $taxCode,
//                    taxPercent: $taxPercent,
//                    taxId: $taxId
//                );
//
//                $receiptTotal += $lineTotal;
//            } else {
//                /*
//                 * FDMS discount implementation:
//                 * Sale line is sent gross, then a Discount line is sent negative.
//                 * This makes discount visible in FDMS receipt lines.
//                 */
//                $receiptLines[] = $this->receiptLinePayload(
//                    type: 'Sale',
//                    lineNo: $lineNo++,
//                    line: $line,
//                    price: $unitPrice,
//                    quantity: $quantity,
//                    total: $lineGross,
//                    taxCode: $taxCode,
//                    taxPercent: $taxPercent,
//                    taxId: $taxId
//                );
//
//                $receiptTotal += $lineGross;
//
//                if ($lineDiscount > 0) {
//                    $receiptLines[] = [
//                        'receiptLineType' => 'Discount',
//                        'receiptLineNo' => $lineNo++,
//                        'receiptLineHSCode' => $this->formatHSCode($line->hs_code ?? '0000', $taxPercent),
//                        'receiptLineName' => mb_substr('Discount: ' . (string) $line->line_name, 0, 200),
//                        'receiptLinePrice' => round(-1 * $lineDiscount, 6),
//                        'receiptLineQuantity' => 1,
//                        'receiptLineTotal' => round(-1 * $lineDiscount, 2),
//                        'taxCode' => $taxCode,
//                        'taxPercent' => round((float) $taxPercent, 2),
//                        'taxID' => (int) $taxId,
//                    ];
//
//                    $receiptTotal -= $lineDiscount;
//                }
//            }
//
//            foreach ($lineTaxes as $taxItem) {
//                $summaryTaxId = $this->resolveFdmsTaxId($taxItem);
//                $summaryTaxCode = substr($taxItem->tax_code ?: 'VAT', 0, 3);
//                $summaryTaxPercent = $this->resolveTaxPercent($taxItem);
//                $key = $summaryTaxCode . '_' . $summaryTaxPercent . '_' . $summaryTaxId;
//
//                if (!isset($taxSummary[$key])) {
//                    $taxSummary[$key] = [
//                        'taxCode' => $summaryTaxCode,
//                        'taxPercent' => $summaryTaxPercent === null ? null : round((float) $summaryTaxPercent, 2),
//                        'taxID' => (int) $summaryTaxId,
//                        'taxAmount' => 0,
//                        'salesAmountWithTax' => 0,
//                    ];
//                }
//
//                $taxAmount = round((float) ($taxItem->tax_amount ?? 0), 2);
//                $salesAmountWithTax = round((float) ($taxItem->sales_amount_with_tax ?? 0), 2);
//
//                if ($isCreditNote) {
//                    $taxAmount = -1 * abs($taxAmount);
//                    $salesAmountWithTax = -1 * abs($salesAmountWithTax ?: (float) $line->line_total);
//                }
//
//                $taxSummary[$key]['taxAmount'] += $taxAmount;
//                $taxSummary[$key]['salesAmountWithTax'] += $salesAmountWithTax;
//            }
//        }
//
//        $receiptTaxes = array_values(array_map(function ($tax) {
//            $row = [
//                'taxCode' => $tax['taxCode'],
//                'taxID' => (int) $tax['taxID'],
//                'taxAmount' => round($tax['taxAmount'], 2),
//                'salesAmountWithTax' => round($tax['salesAmountWithTax'], 2),
//            ];
//
//            if ($tax['taxPercent'] !== null && $tax['taxPercent'] !== '') {
//                $row['taxPercent'] = round((float) $tax['taxPercent'], 2);
//            }
//
//            return $row;
//        }, $taxSummary));
//
//        /*
//         * FDMS validates receiptTotal = sum receiptLineTotal when receiptLinesTaxInclusive = true.
//         * Use computed lines total, not sales_transactions total, so Discount lines are included.
//         */
//        $receiptTotal = round($receiptTotal, 2);
//
//        $receiptDate = $sale->transaction_date
//            ? \Carbon\Carbon::parse($sale->transaction_date)->format('Y-m-d\TH:i:s')
//            : $sale->created_at->format('Y-m-d\TH:i:s');
//
//        $receipt = [
//            'receiptType' => $isCreditNote ? 'CreditNote' : 'FiscalInvoice',
//            'receiptCurrency' => $sale->currency_code ?? $sale->currency ?? 'USD',
//            'receiptCounter' => $receiptCounter,
//            'receiptGlobalNo' => $receiptGlobalNo,
//            'invoiceNo' => $sale->invoice_number,
//            'receiptDate' => $receiptDate,
//            'receiptLinesTaxInclusive' => true,
//            'receiptLines' => $receiptLines,
//            'receiptTaxes' => $receiptTaxes,
//            'receiptPayments' => [
//                [
//                    'moneyTypeCode' => $this->moneyTypeCode($sale),
//                    'paymentAmount' => $isCreditNote ? -1 * abs($receiptTotal) : abs($receiptTotal),
//                ],
//            ],
//            'receiptTotal' => $isCreditNote ? -1 * abs($receiptTotal) : abs($receiptTotal),
//            'receiptPrintForm' => 'Receipt48',
//            'username' => optional($sale->cashier ?? $sale->user)->username,
//            'userNameSurname' => optional($sale->cashier ?? $sale->user)->name,
//        ];
//
//        if ($isCreditNote) {
//            $receipt['receiptNotes'] = $receiptNotes ?: 'Credit note';
//            $receipt['creditDebitNote'] = $creditDebitNote;
//        }
//
//        return ['receipt' => $receipt];
//    }
//
//    private function receiptLinePayload(
//        string $type,
//        int $lineNo,
//        mixed $line,
//        float $price,
//        float $quantity,
//        float $total,
//        string $taxCode,
//        mixed $taxPercent,
//        int $taxId
//    ): array {
//        $payload = [
//            'receiptLineType' => $type,
//            'receiptLineNo' => $lineNo,
//            'receiptLineHSCode' => $this->formatHSCode($line->hs_code ?? '0000', $taxPercent),
//            'receiptLineName' => mb_substr((string) $line->line_name, 0, 200),
//            'receiptLinePrice' => round($price, 6),
//            'receiptLineQuantity' => round($quantity, 6),
//            'receiptLineTotal' => round($total, 2),
//            'taxCode' => $taxCode,
//            'taxID' => $taxId,
//        ];
//
//        if ($taxPercent !== null && $taxPercent !== '') {
//            $payload['taxPercent'] = round((float) $taxPercent, 2);
//        }
//
//        return $payload;
//    }
//
//    private function findDevice(SalesTransaction $sale): FiscalDevice
//    {
//        $device = FiscalDevice::where('company_id', $sale->company_id)
//            ->where(function ($query) use ($sale) {
//                $query->whereNull('branch_id')
//                    ->orWhere('branch_id', $sale->branch_id);
//            })
//            ->where('is_active', true)
//            ->where('is_registered', true)
//            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$sale->branch_id])
//            ->first();
//
//        if (!$device) {
//            throw new \Exception('No active fiscal device found for this branch.');
//        }
//
//        return $device;
//    }
//
//    private function resolveFdmsTaxId($taxItem): int
//    {
//        $taxId = $taxItem->fdms_tax_id
//            ?? data_get($taxItem->metadata, 'fiscal_tax_id')
//            ?? data_get($taxItem->metadata, 'fdms_tax_id')
//            ?? $taxItem->tax->tax_id
//            ?? null;
//
//        if (!$taxId) {
//            throw new \Exception("Tax '{$taxItem->tax_code}' missing numeric tax_id mapping in taxes table.");
//        }
//
//        return (int) $taxId;
//    }
//
//    private function resolveTaxPercent($taxItem): mixed
//    {
//        $percent = $taxItem->tax_percent ?? $taxItem->tax->rate ?? $taxItem->tax->tax_rate ?? null;
//
//        if ($percent === null || $percent === '') {
//            return null;
//        }
//
//        return (float) $percent;
//    }
//
//    private function generateReceiptQr(string $qrString, SalesTransaction $sale): array
//    {
//        $folder = storage_path('app/public/qrcodes');
//
//        File::ensureDirectoryExists($folder, 0755, true);
//
//        $fileName = 'qr-' . ($sale->invoice_number ?: $sale->id) . '.svg';
//        $filePath = $folder . DIRECTORY_SEPARATOR . $fileName;
//
//        QrCode::format('svg')
//            ->size(250)
//            ->errorCorrection('H')
//            ->generate($qrString, $filePath);
//
//        return [
//            'file_name' => $fileName,
//            'file_path' => $filePath,
//            'public_path' => 'qrcodes/' . $fileName,
//            'url' => url('storage/qrcodes/' . $fileName),
//        ];
//    }
//
//    private function moneyTypeCode(SalesTransaction $sale): string
//    {
//        $payment = strtolower((string) ($sale->payment_method ?? ''));
//
//        return match (true) {
//            str_contains($payment, 'card') => 'Card',
//            str_contains($payment, 'mobile') => 'MobileWallet',
//            str_contains($payment, 'bank') => 'BankTransfer',
//            default => 'Cash',
//        };
//    }
//
//    private function formatHSCode($hsCode, $taxPercent): string
//    {
//        $clean = preg_replace('/[^0-9]/', '', (string) $hsCode);
//
//        if (!$clean) {
//            return ((float) $taxPercent == 0.0) ? '00000000' : '0000';
//        }
//
//        if ((float) $taxPercent == 0.0) {
//            return str_pad(substr($clean, 0, 8), 8, '0', STR_PAD_RIGHT);
//        }
//
//        if (strlen($clean) >= 8) {
//            return substr($clean, 0, 8);
//        }
//
//        if (strlen($clean) >= 4) {
//            return substr($clean, 0, 4);
//        }
//
//        return str_pad($clean, 4, '0', STR_PAD_RIGHT);
//    }
//}


namespace App\Services\Zimra;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Modules\FiscalDevice\Models\FiscalDevice;
use Modules\Sales\Models\FiscalDay;
use Modules\Sales\Models\SalesTransaction;
use Modules\ZimraIntegration\Models\ZimraCompanySetting;
use Modules\ZimraIntegration\Models\ZimraGlobalCounter;
use Modules\ZimraIntegration\Models\ZimraReceipt;

class ZimraReceiptService
{
    public function __construct(
        private ZimraClient               $client,
        private ZimraSignatureService     $signatureService,
        private ZimraFiscalDayService     $fiscalDayService,
        private ZimraFiscalCounterService $counterService
    )
    {
    }

    public function submitSale(string $saleId): SalesTransaction
    {
        return $this->submitTransaction($saleId, 'sale');
    }

    public function submitCreditNote(string $cancelSaleId, string $originalSaleId, ?string $reason = null): SalesTransaction
    {
        return $this->submitTransaction($cancelSaleId, 'credit_note', $originalSaleId, $reason);
    }

    private function submitTransaction(
        string  $saleId,
        string  $mode,
        ?string $originalSaleId = null,
        ?string $reason = null
    ): SalesTransaction
    {
        return DB::transaction(function () use ($saleId, $mode, $originalSaleId, $reason) {
            $sale = SalesTransaction::with([
                'receiptLines.taxes.tax',
                'cashier',
                'user',
            ])
                ->where('id', $saleId)
                ->lockForUpdate()
                ->firstOrFail();

            $setting = ZimraCompanySetting::where('company_id', $sale->company_id)->first();

            if (!$setting || !$setting->enabled) {
                $sale->update([
                    'is_fiscalised' => false,
                    'fiscal_status' => 'skipped',
                    'fiscal_error' => null,
                ]);

                return $sale->fresh();
            }

            if ((bool)($sale->is_fiscalised ?? false)) {
                return $sale->fresh();
            }

            $device = $this->findDevice($sale);

            $fiscalDay = FiscalDay::where('fiscal_device_id', $device->id)
                ->whereIn('status', ['opened', 'close_failed'])
                ->lockForUpdate()
                ->first();

            if (!$fiscalDay && $setting->auto_open_day) {
                $fiscalDay = $this->fiscalDayService->open(
                    device: $device,
                    openedBy: $sale->cashier_id ?? $sale->user_id ?? null
                );
            }

            if (!$fiscalDay) {
                $sale->update([
                    'is_fiscalised' => false,
                    'fiscal_status' => 'failed',
                    'fiscal_error' => 'No open fiscal day found for this fiscal device.',
                ]);

                if ($setting->block_sales_without_open_day) {
                    throw new \Exception('No open fiscal day found for this fiscal device.');
                }

                return $sale->fresh();
            }

            $zimraReceipt = ZimraReceipt::where('sales_transaction_id', $sale->id)
                ->lockForUpdate()
                ->first();

            if (!$zimraReceipt) {
                $zimraReceipt = $this->createLocalZimraReceipt(
                    sale: $sale,
                    device: $device,
                    fiscalDay: $fiscalDay,
                    mode: $mode,
                    originalSaleId: $originalSaleId,
                    reason: $reason
                );
            }

            $response = $this->client->post($device, 'SubmitReceipt', $zimraReceipt->payload);

            if (!$response->successful()) {
                $zimraReceipt->update([
                    'status' => 'failed',
                    'response' => $response->json() ?: ['body' => $response->body()],
                    'error' => $response->body(),
                ]);

                $sale->update([
                    'is_fiscalised' => false,
                    'fiscal_status' => 'failed',
                    'fiscal_response' => $response->json(),
                    'fiscal_error' => $response->body(),
                ]);

                if ($setting->block_sale_if_fiscalisation_fails) {
                    throw new \Exception('ZIMRA SubmitReceipt failed: ' . $response->body());
                }

                return $sale->fresh();
            }

            $responseJson = $response->json() ?: [];

            $zimraReceipt->update([
                'status' => 'submitted',
                'fdms_receipt_id' => $responseJson['receiptID'] ?? $responseJson['receiptId'] ?? null,
                'operation_id' => $responseJson['operationID'] ?? $responseJson['operationId'] ?? null,
                'response' => $responseJson,
                'submitted_at' => now(),
                'error' => null,
            ]);

            $this->counterService->recordSubmittedReceipt($zimraReceipt->fresh());

            $sale->update([
                'is_fiscalised' => true,
                'zimra_submitted' => true,
                'zimra_submitted_at' => now(),
                'fiscal_status' => 'success',
                'fiscalised_at' => now(),
                'fdms_receipt_id' => $responseJson['receiptID'] ?? $responseJson['receiptId'] ?? null,
                'fiscal_response' => $responseJson,
                'fiscal_error' => null,
                'qr_url' => $zimraReceipt->qr_url,
                'qr_code_data' => $zimraReceipt->qr_string ?: $zimraReceipt->qr_code_data,
                'receipt_global_no' => $zimraReceipt->receipt_global_no,
                'receipt_counter' => $zimraReceipt->receipt_counter,
                'zimra_signature_hash' => $zimraReceipt->receipt_hash,
                'fiscal_device_id' => $device->id,
                'fiscal_day_id' => $fiscalDay->id,
            ]);

            return $sale->fresh(['receiptLines.taxes']);
        });
    }

    private function createLocalZimraReceipt(
        SalesTransaction $sale,
        FiscalDevice     $device,
        FiscalDay        $fiscalDay,
        string           $mode,
        ?string          $originalSaleId,
        ?string          $reason
    ): ZimraReceipt
    {
        /*
         * Receipt counter:
         * - Starts from 1 for the first receipt in each fiscal day.
         * - Continues sequentially within the same fiscal day only.
         */
        $latestDayCounter = ZimraReceipt::where('fiscal_device_id', $device->id)
            ->where('fiscal_day_id', $fiscalDay->id)
            ->lockForUpdate()
            ->max('receipt_counter');

        $receiptCounter = max(
            ((int)$fiscalDay->receipt_counter) + 1,
            ((int)$latestDayCounter) + 1
        );

        /*
         * Global counter:
         * - Continues across fiscal days.
         * - This prevents duplicate device_id + receipt_global_no.
         */
        $counter = ZimraGlobalCounter::firstOrCreate(
            [
                'company_id' => $sale->company_id,
                'fiscal_device_id' => $device->id,
            ],
            [
                'device_id' => $device->device_id,
                'next_receipt_global_no' => 1,
            ]
        );

        $counter = ZimraGlobalCounter::where('id', $counter->id)
            ->lockForUpdate()
            ->first();

        $latestUsedGlobalNo = ZimraReceipt::where('device_id', $device->device_id)
            ->lockForUpdate()
            ->max('receipt_global_no');

        $receiptGlobalNo = max(
            (int)$counter->next_receipt_global_no,
            ((int)$latestUsedGlobalNo) + 1
        );

        /*
         * Important ZIMRA signature rule:
         * previousReceiptHash must NOT be used for the first receipt in a fiscal day.
         * It must only be included from the second receipt onwards.
         */
        $previousHash = '';

        if ($receiptCounter > 1) {
            $previousReceipt = ZimraReceipt::where('fiscal_device_id', $device->id)
                ->where('fiscal_day_id', $fiscalDay->id)
                ->where('status', 'submitted')
                ->orderByDesc('receipt_counter')
                ->lockForUpdate()
                ->first();

            if (!$previousReceipt) {
                throw new \Exception(
                    "Previous receipt hash not found for fiscal day {$fiscalDay->fiscal_day_no}, receipt counter {$receiptCounter}."
                );
            }

            $previousHash = $previousReceipt->receipt_hash;
        }

        $originalReceipt = null;
        $creditDebitNote = null;
        $receiptNotes = null;

        if ($mode === 'credit_note') {
            if (!$originalSaleId) {
                throw new \Exception('Original sale is required for CreditNote.');
            }

            $originalReceipt = ZimraReceipt::where('sales_transaction_id', $originalSaleId)
                ->where('status', 'submitted')
                ->first();

            if (!$originalReceipt) {
                throw new \Exception('Original FDMS receipt was not found. Cannot create CreditNote.');
            }

            $receiptNotes = $reason ?: ('Credit note for invoice ' . $originalReceipt->invoice_no);

            $creditDebitNote = [
                'receiptID' => $originalReceipt->fdms_receipt_id ? (int)$originalReceipt->fdms_receipt_id : null,
                'deviceID' => (int)$originalReceipt->device_id,
                'receiptGlobalNo' => (int)$originalReceipt->receipt_global_no,
                'fiscalDayNo' => (int)$originalReceipt->fiscal_day_no,
            ];

            if (!$creditDebitNote['receiptID']) {
                unset($creditDebitNote['receiptID']);
            }
        }

        $payload = $this->buildReceiptPayload(
            sale: $sale,
            receiptCounter: $receiptCounter,
            receiptGlobalNo: $receiptGlobalNo,
            mode: $mode,
            creditDebitNote: $creditDebitNote,
            receiptNotes: $receiptNotes,
            originalReceipt: $originalReceipt
        );

        $signature = $this->signatureService->receiptSignature(
            device: $device,
            receipt: $payload['receipt'],
            previousReceiptHash: $previousHash
        );

        $payload['receipt']['receiptDeviceSignature'] = [
            'hash' => $signature['hash'],
            'signature' => $signature['signature'],
        ];

        $qr = $this->signatureService->qrData(
            device: $device,
            receiptDate: $payload['receipt']['receiptDate'],
            receiptGlobalNo: $receiptGlobalNo,
            signatureBase64: $signature['signature']
        );

        $qrImage = $this->generateReceiptQr($qr['qrString'], $sale);

        $zimraReceipt = ZimraReceipt::create([
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'fiscal_device_id' => $device->id,
            'fiscal_day_id' => $fiscalDay->id,
            'sales_transaction_id' => $sale->id,
            'original_zimra_receipt_id' => $originalReceipt?->id,
            'original_sales_transaction_id' => $originalSaleId,
            'device_id' => $device->device_id,
            'fiscal_day_no' => $fiscalDay->fiscal_day_no,
            'receipt_counter' => $receiptCounter,
            'receipt_global_no' => $receiptGlobalNo,
            'invoice_no' => $sale->invoice_number,
            'receipt_type' => $payload['receipt']['receiptType'],
            'receipt_currency' => $payload['receipt']['receiptCurrency'],
            'receipt_notes' => $payload['receipt']['receiptNotes'] ?? null,
            'credit_debit_note' => $payload['receipt']['creditDebitNote'] ?? null,
            'receipt_hash' => $signature['hash'],
            'receipt_signature' => $signature['signature'],
            'qr_url' => $qr['qrUrl'],
            'qr_code_data' => $qr['receiptQrData'],
            'qr_string' => $qr['qrString'],
            'payload' => $payload,
            'response' => [
                'qr_code' => $qr,
                'qr_image' => $qrImage,
                'invoice_status' => 'local_signed_before_submit',
                'previous_receipt_hash_used' => $previousHash,
            ],
            'status' => 'pending',
        ]);

        $fiscalDay->update([
            'receipt_counter' => $receiptCounter,
        ]);

        $counter->update([
            'next_receipt_global_no' => $receiptGlobalNo + 1,
        ]);

        $sale->update([
            'fiscal_device_id' => $device->id,
            'fiscal_day_id' => $fiscalDay->id,
            'receipt_counter' => $receiptCounter,
            'receipt_global_no' => $receiptGlobalNo,
            'qr_url' => $qr['qrUrl'],
            'qr_code_data' => $qr['qrString'],
            'zimra_signature_hash' => $signature['hash'],
            'fiscal_status' => 'pending',
            'fiscal_response' => [
                'qr_code' => $qr,
                'qr_image' => $qrImage,
                'local_zimra_receipt_id' => $zimraReceipt->id,
                'previous_receipt_hash_used' => $previousHash,
            ],
        ]);

        return $zimraReceipt;
    }

    private function buildReceiptPayload(
        SalesTransaction $sale,
        int              $receiptCounter,
        int              $receiptGlobalNo,
        string           $mode = 'sale',
        ?array           $creditDebitNote = null,
        ?string          $receiptNotes = null,
        ?ZimraReceipt    $originalReceipt = null
    ): array
    {
        $sale->loadMissing([
            'receiptLines.taxes.tax',
            'cashier',
            'user',
        ]);

        $isCreditNote = $mode === 'credit_note';

        $receiptLines = [];
        $taxSummary = [];
        $receiptTotal = 0.0;
        $lineNo = 1;

        foreach ($sale->receiptLines as $line) {
            $lineTaxes = collect($line->taxes ?? [])
                ->filter(fn($taxItem) => (int)($taxItem->tax->obliged_to_zimra ?? $taxItem->obliged_to_zimra ?? 0) === 1)
                ->values();

            $firstTax = $lineTaxes->first();

            if (!$firstTax) {
                throw new \Exception("No obliged_to_zimra tax found for line '{$line->line_name}'.");
            }

            $taxId = $this->resolveFdmsTaxId($firstTax);
            $taxCode = substr($firstTax->tax_code ?: 'VAT', 0, 3);
            $taxPercent = $this->resolveTaxPercent($firstTax);

            $quantity = abs((float)$line->line_quantity);
            $unitPrice = abs((float)$line->line_price);
            $lineGross = round($unitPrice * $quantity, 2);
            $lineDiscount = round((float)($line->line_discount ?? 0), 2);

            if ($isCreditNote) {
                $lineTotal = -1 * abs((float)$line->line_total);

                $receiptLines[] = $this->receiptLinePayload(
                    type: 'Sale',
                    lineNo: $lineNo++,
                    line: $line,
                    price: -1 * abs((float)$line->line_price),
                    quantity: $quantity,
                    total: $lineTotal,
                    taxCode: $taxCode,
                    taxPercent: $taxPercent,
                    taxId: $taxId
                );

                $receiptTotal += $lineTotal;
            } else {
                $receiptLines[] = $this->receiptLinePayload(
                    type: 'Sale',
                    lineNo: $lineNo++,
                    line: $line,
                    price: $unitPrice,
                    quantity: $quantity,
                    total: $lineGross,
                    taxCode: $taxCode,
                    taxPercent: $taxPercent,
                    taxId: $taxId
                );

                $receiptTotal += $lineGross;

                if ($lineDiscount > 0) {
                    $receiptLines[] = [
                        'receiptLineType' => 'Discount',
                        'receiptLineNo' => $lineNo++,
                        'receiptLineHSCode' => $this->formatHSCode($line->hs_code ?? '0000', $taxPercent),
                        'receiptLineName' => mb_substr('Discount: ' . (string)$line->line_name, 0, 200),
                        'receiptLinePrice' => round(-1 * $lineDiscount, 6),
                        'receiptLineQuantity' => 1,
                        'receiptLineTotal' => round(-1 * $lineDiscount, 2),
                        'taxCode' => $taxCode,
                        'taxPercent' => round((float)$taxPercent, 2),
                        'taxID' => (int)$taxId,
                    ];

                    $receiptTotal -= $lineDiscount;
                }
            }

            foreach ($lineTaxes as $taxItem) {
                $summaryTaxId = $this->resolveFdmsTaxId($taxItem);
                $summaryTaxCode = substr($taxItem->tax_code ?: 'VAT', 0, 3);
                $summaryTaxPercent = $this->resolveTaxPercent($taxItem);
                $key = $summaryTaxCode . '_' . $summaryTaxPercent . '_' . $summaryTaxId;

                if (!isset($taxSummary[$key])) {
                    $taxSummary[$key] = [
                        'taxCode' => $summaryTaxCode,
                        'taxPercent' => $summaryTaxPercent === null ? null : round((float)$summaryTaxPercent, 2),
                        'taxID' => (int)$summaryTaxId,
                        'taxAmount' => 0,
                        'salesAmountWithTax' => 0,
                    ];
                }

                $taxAmount = round((float)($taxItem->tax_amount ?? 0), 2);
                $salesAmountWithTax = round((float)($taxItem->sales_amount_with_tax ?? 0), 2);

                if ($isCreditNote) {
                    $taxAmount = -1 * abs($taxAmount);
                    $salesAmountWithTax = -1 * abs($salesAmountWithTax ?: (float)$line->line_total);
                }

                $taxSummary[$key]['taxAmount'] += $taxAmount;
                $taxSummary[$key]['salesAmountWithTax'] += $salesAmountWithTax;
            }
        }

        $receiptTaxes = array_values(array_map(function ($tax) {
            $row = [
                'taxCode' => $tax['taxCode'],
                'taxID' => (int)$tax['taxID'],
                'taxAmount' => round($tax['taxAmount'], 2),
                'salesAmountWithTax' => round($tax['salesAmountWithTax'], 2),
            ];

            if ($tax['taxPercent'] !== null && $tax['taxPercent'] !== '') {
                $row['taxPercent'] = round((float)$tax['taxPercent'], 2);
            }

            return $row;
        }, $taxSummary));

        $receiptTotal = round($receiptTotal, 2);

        $receiptDate = $sale->transaction_date
            ? \Carbon\Carbon::parse($sale->transaction_date)->format('Y-m-d\TH:i:s')
            : $sale->created_at->format('Y-m-d\TH:i:s');

        $receipt = [
            'receiptType' => $isCreditNote ? 'CreditNote' : 'FiscalInvoice',
            'receiptCurrency' => strtoupper($sale->currency_code ?? $sale->currency ?? 'USD'),
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'invoiceNo' => $sale->invoice_number,
            'receiptDate' => $receiptDate,
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => $receiptLines,
            'receiptTaxes' => $receiptTaxes,
            'receiptPayments' => [
                [
                    'moneyTypeCode' => $this->moneyTypeCode($sale),
                    'paymentAmount' => $isCreditNote ? -1 * abs($receiptTotal) : abs($receiptTotal),
                ],
            ],
            'receiptTotal' => $isCreditNote ? -1 * abs($receiptTotal) : abs($receiptTotal),
            'receiptPrintForm' => 'Receipt48',
            'username' => optional($sale->cashier ?? $sale->user)->username,
            'userNameSurname' => optional($sale->cashier ?? $sale->user)->name,
        ];

        if ($isCreditNote) {
            $receipt['receiptNotes'] = $receiptNotes ?: 'Credit note';
            $receipt['creditDebitNote'] = $creditDebitNote;
        }

        return ['receipt' => $receipt];
    }

    private function receiptLinePayload(
        string $type,
        int    $lineNo,
        mixed  $line,
        float  $price,
        float  $quantity,
        float  $total,
        string $taxCode,
        mixed  $taxPercent,
        int    $taxId
    ): array
    {
        $payload = [
            'receiptLineType' => $type,
            'receiptLineNo' => $lineNo,
            'receiptLineHSCode' => $this->formatHSCode($line->hs_code ?? '0000', $taxPercent),
            'receiptLineName' => mb_substr((string)$line->line_name, 0, 200),
            'receiptLinePrice' => round($price, 6),
            'receiptLineQuantity' => round($quantity, 6),
            'receiptLineTotal' => round($total, 2),
            'taxCode' => $taxCode,
            'taxID' => $taxId,
        ];

        if ($taxPercent !== null && $taxPercent !== '') {
            $payload['taxPercent'] = round((float)$taxPercent, 2);
        }

        return $payload;
    }

    private function findDevice(SalesTransaction $sale): FiscalDevice
    {
        $device = FiscalDevice::where('company_id', $sale->company_id)
            ->where(function ($query) use ($sale) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $sale->branch_id);
            })
            ->where('is_active', true)
            ->where('is_registered', true)
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$sale->branch_id])
            ->first();

        if (!$device) {
            throw new \Exception('No active fiscal device found for this branch.');
        }

        return $device;
    }

    private function resolveFdmsTaxId($taxItem): int
    {
        $taxId = $taxItem->fdms_tax_id
            ?? data_get($taxItem->metadata, 'fiscal_tax_id')
            ?? data_get($taxItem->metadata, 'fdms_tax_id')
            ?? $taxItem->tax->tax_id
            ?? null;

        if (!$taxId) {
            throw new \Exception("Tax '{$taxItem->tax_code}' missing numeric tax_id mapping in taxes table.");
        }

        return (int)$taxId;
    }

    private function resolveTaxPercent($taxItem): mixed
    {
        $percent = $taxItem->tax_percent ?? $taxItem->tax->rate ?? $taxItem->tax->tax_rate ?? null;

        if ($percent === null || $percent === '') {
            return null;
        }

        return (float)$percent;
    }

    private function generateReceiptQr(string $qrString, SalesTransaction $sale): array
    {
        $folder = storage_path('app/public/qrcodes');

        File::ensureDirectoryExists($folder, 0755, true);

        $fileName = 'qr-' . ($sale->invoice_number ?: $sale->id) . '.svg';
        $filePath = $folder . DIRECTORY_SEPARATOR . $fileName;

        QrCode::format('svg')
            ->size(250)
            ->errorCorrection('H')
            ->generate($qrString, $filePath);

        return [
            'file_name' => $fileName,
            'file_path' => $filePath,
            'public_path' => 'qrcodes/' . $fileName,
            'url' => url('storage/qrcodes/' . $fileName),
        ];
    }

    private function moneyTypeCode(SalesTransaction $sale): string
    {
        $payment = strtolower((string)($sale->payment_method ?? ''));

        return match (true) {
            str_contains($payment, 'card') => 'Card',
            str_contains($payment, 'mobile') => 'MobileWallet',
            str_contains($payment, 'bank') => 'BankTransfer',
            default => 'Cash',
        };
    }

    private function formatHSCode($hsCode, $taxPercent): string
    {
        $clean = preg_replace('/[^0-9]/', '', (string)$hsCode);

        if (!$clean) {
            return ((float)$taxPercent == 0.0) ? '00000000' : '0000';
        }

        if ((float)$taxPercent == 0.0) {
            return str_pad(substr($clean, 0, 8), 8, '0', STR_PAD_RIGHT);
        }

        if (strlen($clean) >= 8) {
            return substr($clean, 0, 8);
        }

        if (strlen($clean) >= 4) {
            return substr($clean, 0, 4);
        }

        return str_pad($clean, 4, '0', STR_PAD_RIGHT);
    }
}
