<?php

namespace App\Services\Zimra;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\FiscalDay;
use Modules\ZimraIntegration\Models\ZimraFiscalCounter;
use Modules\ZimraIntegration\Models\ZimraFiscalCounterEvent;
use Modules\ZimraIntegration\Models\ZimraReceipt;

class ZimraFiscalCounterService
{
    public function __construct(private ZimraSignatureService $signatureService)
    {
    }

    public function rebuildDay(FiscalDay $fiscalDay): array
    {
        return DB::transaction(function () use ($fiscalDay) {
            ZimraFiscalCounter::where('fiscal_day_id', $fiscalDay->id)->delete();
            ZimraFiscalCounterEvent::where('fiscal_day_id', $fiscalDay->id)->delete();

            $receipts = ZimraReceipt::where('fiscal_day_id', $fiscalDay->id)
                ->where('status', 'submitted')
                ->orderBy('receipt_counter')
                ->get();

            foreach ($receipts as $receipt) {
                $this->recordSubmittedReceipt($receipt);
            }

            return $this->buildCloseDayCounters($fiscalDay);
        });
    }

    public function recordSubmittedReceipt(ZimraReceipt $receipt): void
    {
        if ($receipt->status !== 'submitted') {
            return;
        }

        if (ZimraFiscalCounterEvent::where('zimra_receipt_id', $receipt->id)->exists()) {
            return;
        }

        $receiptPayload = $receipt->payload['receipt'] ?? [];
        $events = $this->buildCounterEventsFromReceipt($receipt, $receiptPayload);

        foreach ($events as $event) {
            $this->recordEvent($receipt, $event);
        }
    }

    public function buildCloseDayCounters(FiscalDay $fiscalDay): array
    {
        $raw = ZimraFiscalCounter::where('fiscal_day_id', $fiscalDay->id)
            ->whereRaw('ROUND(fiscal_counter_value, 4) != 0')
            ->get()
            ->map(function (ZimraFiscalCounter $counter) {
                return [
                    'fiscalCounterType' => $counter->fiscal_counter_type,
                    'fiscalCounterCurrency' => $counter->fiscal_counter_currency,
                    'fiscalCounterTaxID' => $counter->fiscal_counter_tax_id,
                    'fiscalCounterTaxPercent' => $counter->fiscal_counter_tax_percent,
                    'fiscalCounterMoneyType' => $counter->fiscal_counter_money_type,
                    'fiscalCounterValue' => round((float) $counter->fiscal_counter_value, 2),
                ];
            })
            ->all();

        /*
         * Use the exact same ordering helper as signature generation.
         * Payload order and signature-source order must match.
         */
        return $this->signatureService->sortFiscalCountersForPayload($raw);
    }

    private function buildCounterEventsFromReceipt(ZimraReceipt $receipt, array $r): array
    {
        $receiptType = $this->normaliseReceiptType($r['receiptType'] ?? $receipt->receipt_type);
        $currency = strtoupper($r['receiptCurrency'] ?? $receipt->receipt_currency ?? 'USD');

        $events = [];

        foreach (($r['receiptTaxes'] ?? []) as $tax) {
            $taxAmount = (float) ($tax['taxAmount'] ?? 0);
            $salesAmountWithTax = (float) ($tax['salesAmountWithTax'] ?? 0);

            $taxId = (int) ($tax['taxID'] ?? 0);
            $taxPercent = array_key_exists('taxPercent', $tax) && $tax['taxPercent'] !== null && $tax['taxPercent'] !== ''
                ? (float) $tax['taxPercent']
                : null;

            if ($receiptType === 'FiscalInvoice') {
                $events[] = $this->taxEvent('SaleByTax', $currency, $taxId, $taxPercent, $salesAmountWithTax);
                $events[] = $this->taxEvent('SaleTaxByTax', $currency, $taxId, $taxPercent, $taxAmount);
            }

            if ($receiptType === 'CreditNote') {
                $events[] = $this->taxEvent('CreditNoteByTax', $currency, $taxId, $taxPercent, $salesAmountWithTax);
                $events[] = $this->taxEvent('CreditNoteTaxByTax', $currency, $taxId, $taxPercent, $taxAmount);
            }

            if ($receiptType === 'DebitNote') {
                $events[] = $this->taxEvent('DebitNoteByTax', $currency, $taxId, $taxPercent, $salesAmountWithTax);
                $events[] = $this->taxEvent('DebitNoteTaxByTax', $currency, $taxId, $taxPercent, $taxAmount);
            }
        }

        foreach (($r['receiptPayments'] ?? []) as $payment) {
            $events[] = [
                'fiscal_counter_type' => 'BalanceByMoneyType',
                'fiscal_counter_currency' => $currency,
                'fiscal_counter_tax_id' => null,
                'fiscal_counter_tax_percent' => null,
                'fiscal_counter_money_type' => $this->normaliseMoneyType($payment['moneyTypeCode'] ?? 'Cash'),
                'fiscal_counter_value' => (float) ($payment['paymentAmount'] ?? 0),
            ];
        }

        return array_values(array_filter(
            $events,
            fn ($event) => round((float) $event['fiscal_counter_value'], 4) != 0.0
        ));
    }

    private function recordEvent(ZimraReceipt $receipt, array $event): void
    {
        ZimraFiscalCounterEvent::create([
            'company_id' => $receipt->company_id,
            'branch_id' => $receipt->branch_id,
            'fiscal_device_id' => $receipt->fiscal_device_id,
            'fiscal_day_id' => $receipt->fiscal_day_id,
            'zimra_receipt_id' => $receipt->id,
            'sales_transaction_id' => $receipt->sales_transaction_id,
            'receipt_type' => $receipt->receipt_type,
            'receipt_currency' => $receipt->receipt_currency,
            ...$event,
            'metadata' => [
                'invoice_no' => $receipt->invoice_no,
                'receipt_global_no' => $receipt->receipt_global_no,
                'receipt_counter' => $receipt->receipt_counter,
            ],
        ]);

        $counter = ZimraFiscalCounter::firstOrCreate(
            [
                'company_id' => $receipt->company_id,
                'branch_id' => $receipt->branch_id,
                'fiscal_device_id' => $receipt->fiscal_device_id,
                'fiscal_day_id' => $receipt->fiscal_day_id,
                'device_id' => $receipt->device_id,
                'fiscal_day_no' => $receipt->fiscal_day_no,
                'fiscal_counter_type' => $event['fiscal_counter_type'],
                'fiscal_counter_currency' => $event['fiscal_counter_currency'],
                'fiscal_counter_tax_id' => $event['fiscal_counter_tax_id'],
                'fiscal_counter_tax_percent' => $event['fiscal_counter_tax_percent'],
                'fiscal_counter_money_type' => $event['fiscal_counter_money_type'],
            ],
            [
                'fiscal_counter_value' => 0,
            ]
        );

        $counter->increment(
            'fiscal_counter_value',
            round((float) $event['fiscal_counter_value'], 4)
        );
    }

    private function taxEvent(
        string $type,
        string $currency,
        int $taxId,
        mixed $taxPercent,
        float $value
    ): array {
        return [
            'fiscal_counter_type' => $type,
            'fiscal_counter_currency' => strtoupper($currency),
            'fiscal_counter_tax_id' => $taxId,
            'fiscal_counter_tax_percent' => $taxPercent === null ? null : (float) $taxPercent,
            'fiscal_counter_money_type' => null,
            'fiscal_counter_value' => $value,
        ];
    }

    private function normaliseReceiptType(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'creditnote', 'credit_note', 'credit-note' => 'CreditNote',
            'debitnote', 'debit_note', 'debit-note' => 'DebitNote',
            default => 'FiscalInvoice',
        };
    }

    private function normaliseMoneyType(?string $value): string
    {
        $clean = strtolower((string) $value);

        return match (true) {
            str_contains($clean, 'card') => 'Card',
            str_contains($clean, 'mobile') => 'MobileWallet',
            str_contains($clean, 'bank') => 'BankTransfer',
            default => 'Cash',
        };
    }
}
