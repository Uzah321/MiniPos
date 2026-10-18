<?php
//
//namespace App\Services\Zimra;
//
//use Illuminate\Support\Facades\DB;
//use Modules\FiscalDevice\Models\FiscalDevice;
//use Modules\Sales\Models\FiscalDay;
//use Modules\ZimraIntegration\Models\ZimraReceipt;
//
//class ZimraFiscalDayService
//{
//    public function __construct(private ZimraClient $client, private ZimraSignatureService $signatureService)
//    {
//    }
//
//    public function open(FiscalDevice $device, ?string $openedBy = null): FiscalDay
//    {
//        return DB::transaction(function () use ($device, $openedBy) {
//            $device = FiscalDevice::where('id', $device->id)->lockForUpdate()->firstOrFail();
//
//            $existing = FiscalDay::where('fiscal_device_id', $device->id)
//                ->whereIn('status', ['opened', 'close_failed', 'close_initiated'])
//                ->first();
//
//            if ($existing) {
//                return $existing;
//            }
//
//            $statusResponse = $this->client->get($device, 'GetStatus');
//
//            if (!$statusResponse->successful()) {
//                throw new \Exception('ZIMRA getStatus failed: ' . $statusResponse->body());
//            }
//
//            $status = $statusResponse->json() ?: [];
//            $fdmsStatus = $status['fiscalDayStatus'] ?? null;
//
//            if ($fdmsStatus === 'FiscalDayOpened') {
//                return FiscalDay::create([
//                    'company_id' => $device->company_id,
//                    'branch_id' => $device->branch_id,
//                    'fiscal_device_id' => $device->id,
//                    'device_id' => $device->device_id,
//                    'fiscal_day_no' => $status['lastFiscalDayNo'] ?? 1,
//                    'status' => 'opened',
//                    'opened_at' => now(),
//                    'open_response' => $status,
//                    'opened_by' => $openedBy,
//                ]);
//            }
//
//            if ($fdmsStatus !== 'FiscalDayClosed' && $fdmsStatus !== null) {
//                throw new \Exception('Cannot open fiscal day. ZIMRA status: ' . $fdmsStatus);
//            }
//
//            $lastNo = (int) ($status['lastFiscalDayNo'] ?? 0);
//            $nextNo = $lastNo > 0 ? $lastNo + 1 : 1;
//            $openedAt = now()->format('Y-m-d\TH:i:s');
//
//            $response = $this->client->post($device, 'OpenDay', [
//                'fiscalDayNo' => $nextNo,
//                'fiscalDayOpened' => $openedAt,
//            ]);
//
//            if (!$response->successful()) {
//                throw new \Exception('ZIMRA openDay failed: ' . $response->body());
//            }
//
//            return FiscalDay::create([
//                'company_id' => $device->company_id,
//                'branch_id' => $device->branch_id,
//                'fiscal_device_id' => $device->id,
//                'device_id' => $device->device_id,
//                'fiscal_day_no' => $response->json('fiscalDayNo') ?: $nextNo,
//                'status' => 'opened',
//                'opened_at' => $openedAt,
//                'open_operation_id' => $response->json('operationID'),
//                'open_response' => $response->json(),
//                'opened_by' => $openedBy,
//            ]);
//        });
//    }
//
//    public function close(FiscalDay $fiscalDay, ?string $closedBy = null): FiscalDay
//    {
//        return DB::transaction(function () use ($fiscalDay, $closedBy) {
//            $fiscalDay = FiscalDay::where('id', $fiscalDay->id)
//                ->lockForUpdate()
//                ->firstOrFail();
//
//            if (!in_array($fiscalDay->status, ['opened', 'close_failed'], true)) {
//                throw new \Exception('Fiscal day is not open.');
//            }
//
//            $pendingReceipts = ZimraReceipt::where('fiscal_day_id', $fiscalDay->id)
//                ->where('status', '!=', 'submitted')
//                ->count();
//
//            if ($pendingReceipts > 0) {
//                throw new \Exception("Cannot close fiscal day. {$pendingReceipts} receipt(s) are not submitted.");
//            }
//
//            $device = FiscalDevice::where('id', $fiscalDay->fiscal_device_id)
//                ->lockForUpdate()
//                ->firstOrFail();
//
//            $this->counterService->rebuildDay($fiscalDay);
//            $counters = $this->counterService->buildCloseDayCounters($fiscalDay);
//
//            $signature = $this->signatureService->fiscalDaySignature(
//                device: $device,
//                fiscalDayNo: (int) $fiscalDay->fiscal_day_no,
//                fiscalDayOpenedDate: $fiscalDay->opened_at->format('Y-m-d'),
//                counters: $counters
//            );
//
//            $payload = [
//                'fiscalDayNo' => (int) $fiscalDay->fiscal_day_no,
//                'fiscalDayCounters' => $counters,
//                'fiscalDayDeviceSignature' => [
//                    'hash' => $signature['hash'],
//                    'signature' => $signature['signature'],
//                ],
//                'receiptCounter' => (int) $fiscalDay->receipt_counter,
//            ];
//
//            $debug = [
//                'close_payload' => $payload,
//                'counter_string' => $signature['counter_string'],
//                'signature_source' => $signature['string_used_for_hash'],
//                'signature_method' => $signature['signature_method'],
//                'fiscal_day_date' => $signature['fiscal_day_date'],
//                'hash' => $signature['hash'],
//            ];
//
//            $response = $this->client->post($device, 'CloseDay', $payload);
//
//            if (!$response->successful()) {
//                $fiscalDay->update([
//                    'status' => 'close_failed',
//                    'close_response' => $response->json() ?: ['body' => $response->body()],
//                    'closing_error_code' => $response->json('errorCode'),
//                    'last_status_response' => [
//                        ...$debug,
//                        'fdms_error_body' => $response->body(),
//                        'fdms_error_json' => $response->json(),
//                    ],
//                ]);
//
//                throw new \Exception('ZIMRA closeDay failed: ' . $response->body());
//            }
//
//            $fiscalDay->update([
//                'status' => 'close_initiated',
//                'close_operation_id' => $response->json('operationID'),
//                'close_response' => [
//                    'response' => $response->json(),
//                    ...$debug,
//                ],
//                'closed_by' => $closedBy,
//            ]);
//
//            return $fiscalDay->fresh();
//        });
//    }
//
//
//    public function poll(FiscalDay $fiscalDay): FiscalDay
//    {
//        $device = FiscalDevice::findOrFail($fiscalDay->fiscal_device_id);
//        $response = $this->client->get($device, 'GetStatus');
//
//        if (!$response->successful()) {
//            throw new \Exception('ZIMRA getStatus failed: ' . $response->body());
//        }
//
//        $status = $response->json() ?: [];
//        $updates = ['last_status_response' => $status];
//
//        if (($status['fiscalDayStatus'] ?? null) === 'FiscalDayClosed') {
//            $updates['status'] = 'closed';
//            $updates['closed_at'] = $status['fiscalDayClosed'] ?? now();
//            $updates['server_signature'] = $status['fiscalDayServerSignature'] ?? null;
//        }
//
//        if (($status['fiscalDayStatus'] ?? null) === 'FiscalDayCloseFailed') {
//            $updates['status'] = 'close_failed';
//            $updates['closing_error_code'] = $status['fiscalDayClosingErrorCode'] ?? null;
//        }
//
//        $fiscalDay->update($updates);
//
//        return $fiscalDay->fresh();
//    }
//
//    private function buildCounters(FiscalDay $fiscalDay): array
//    {
//        $receipts = ZimraReceipt::where('fiscal_day_id', $fiscalDay->id)->where('status', 'submitted')->get();
//        $counters = [];
//
//        foreach ($receipts as $receipt) {
//            $r = $receipt->payload['receipt'] ?? [];
//
//            foreach (($r['receiptTaxes'] ?? []) as $tax) {
//                $key = 'SaleByTax|' . ($r['receiptCurrency'] ?? '') . '|' . ($tax['taxID'] ?? '') . '|' . ($tax['taxPercent'] ?? '');
//                if (!isset($counters[$key])) {
//                    $counters[$key] = [
//                        'fiscalCounterType' => 'SaleByTax',
//                        'fiscalCounterCurrency' => $r['receiptCurrency'],
//                        'fiscalCounterTaxID' => $tax['taxID'],
//                        'fiscalCounterTaxPercent' => $tax['taxPercent'],
//                        'fiscalCounterValue' => 0,
//                    ];
//                }
//                $counters[$key]['fiscalCounterValue'] += (float) $tax['salesAmountWithTax'];
//            }
//
//            foreach (($r['receiptPayments'] ?? []) as $payment) {
//                $key = 'BalanceByMoneyType|' . ($r['receiptCurrency'] ?? '') . '|' . ($payment['moneyTypeCode'] ?? '');
//                if (!isset($counters[$key])) {
//                    $counters[$key] = [
//                        'fiscalCounterType' => 'BalanceByMoneyType',
//                        'fiscalCounterCurrency' => $r['receiptCurrency'],
//                        'fiscalCounterMoneyType' => $payment['moneyTypeCode'],
//                        'fiscalCounterValue' => 0,
//                    ];
//                }
//                $counters[$key]['fiscalCounterValue'] += (float) $payment['paymentAmount'];
//            }
//        }
//
//        return array_values(array_filter($counters, fn ($counter) => (float) $counter['fiscalCounterValue'] != 0.0));
//    }
//}


namespace App\Services\Zimra;

use Illuminate\Support\Facades\DB;
use Modules\FiscalDevice\Models\FiscalDevice;
use Modules\Sales\Models\FiscalDay;
use Modules\ZimraIntegration\Models\ZimraReceipt;

class ZimraFiscalDayService
{
    public function __construct(
        private ZimraClient               $client,
        private ZimraSignatureService     $signatureService,
        private ZimraFiscalCounterService $counterService
    )
    {
    }

    public function open(FiscalDevice $device, ?string $openedBy = null): FiscalDay
    {
        return DB::transaction(function () use ($device, $openedBy) {
            $device = FiscalDevice::where('id', $device->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = FiscalDay::where('fiscal_device_id', $device->id)
                ->whereIn('status', ['opened', 'close_failed', 'close_initiated'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $statusResponse = $this->client->get($device, 'GetStatus');

            if (!$statusResponse->successful()) {
                throw new \Exception('ZIMRA getStatus failed: ' . $statusResponse->body());
            }

            $status = $statusResponse->json() ?: [];
            $fdmsStatus = $status['fiscalDayStatus'] ?? null;

            if ($fdmsStatus === 'FiscalDayOpened') {
                return FiscalDay::create([
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                    'fiscal_device_id' => $device->id,
                    'device_id' => $device->device_id,
                    'fiscal_day_no' => $status['lastFiscalDayNo'] ?? 1,
                    'status' => 'opened',
                    'opened_at' => now(),
                    'open_response' => $status,
                    'opened_by' => $openedBy,
                ]);
            }

            if ($fdmsStatus !== 'FiscalDayClosed' && $fdmsStatus !== null) {
                throw new \Exception('Cannot open fiscal day. ZIMRA status: ' . $fdmsStatus);
            }

            $lastNo = (int)($status['lastFiscalDayNo'] ?? 0);
            $nextNo = $lastNo > 0 ? $lastNo + 1 : 1;
            $openedAt = now()->format('Y-m-d\TH:i:s');

            $response = $this->client->post($device, 'OpenDay', [
                'fiscalDayNo' => $nextNo,
                'fiscalDayOpened' => $openedAt,
            ]);

            if (!$response->successful()) {
                throw new \Exception('ZIMRA openDay failed: ' . $response->body());
            }

            return FiscalDay::create([
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'fiscal_device_id' => $device->id,
                'device_id' => $device->device_id,
                'fiscal_day_no' => $response->json('fiscalDayNo') ?: $nextNo,
                'status' => 'opened',
                'opened_at' => $openedAt,
                'open_operation_id' => $response->json('operationID'),
                'open_response' => $response->json(),
                'opened_by' => $openedBy,
            ]);
        });
    }

    public function close(FiscalDay $fiscalDay, ?string $closedBy = null): FiscalDay
    {
        return DB::transaction(function () use ($fiscalDay, $closedBy) {
            $fiscalDay = FiscalDay::where('id', $fiscalDay->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($fiscalDay->status, ['opened', 'close_failed'], true)) {
                throw new \Exception('Fiscal day is not open.');
            }

            $pendingReceipts = ZimraReceipt::where('fiscal_day_id', $fiscalDay->id)
                ->where('status', '!=', 'submitted')
                ->count();

            if ($pendingReceipts > 0) {
                throw new \Exception("Cannot close fiscal day. {$pendingReceipts} receipt(s) are not submitted.");
            }

            $device = FiscalDevice::where('id', $fiscalDay->fiscal_device_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Rebuild the fiscal counter table from submitted FDMS receipts before close.
             * Then build the closeDay counter payload from the counter table.
             */
            $this->counterService->rebuildDay($fiscalDay);
            $counters = $this->counterService->buildCloseDayCounters($fiscalDay);

            $signature = $this->signatureService->fiscalDaySignature(
                device: $device,
                fiscalDayNo: (int)$fiscalDay->fiscal_day_no,
                fiscalDayOpenedDate: $fiscalDay->opened_at->format('Y-m-d'),
                counters: $counters
            );

            $payload = [
                'fiscalDayNo' => (int)$fiscalDay->fiscal_day_no,
                'fiscalDayCounters' => $counters,
                'fiscalDayDeviceSignature' => [
                    'hash' => $signature['hash'],
                    'signature' => $signature['signature'],
                ],
                'receiptCounter' => (int)$fiscalDay->receipt_counter,
            ];

            $debug = [
                'close_payload' => $payload,
                'counter_string' => $signature['counter_string'] ?? null,
                'signature_source' => $signature['string_used_for_hash'] ?? $signature['source'] ?? null,
                'signature_method' => $signature['signature_method'] ?? null,
                'fiscal_day_date' => $signature['fiscal_day_date'] ?? $fiscalDay->opened_at->format('Y-m-d'),
                'hash' => $signature['hash'] ?? null,
            ];

            $response = $this->client->post($device, 'CloseDay', $payload);

            if (!$response->successful()) {
                $fiscalDay->update([
                    'status' => 'close_failed',
                    'close_response' => $response->json() ?: ['body' => $response->body()],
                    'closing_error_code' => $response->json('errorCode'),
                    'last_status_response' => [
                        ...$debug,
                        'fdms_error_body' => $response->body(),
                        'fdms_error_json' => $response->json(),
                    ],
                ]);

                throw new \Exception('ZIMRA closeDay failed: ' . $response->body());
            }

            $fiscalDay->update([
                'status' => 'close_initiated',
                'close_operation_id' => $response->json('operationID'),
                'close_response' => [
                    'response' => $response->json(),
                    ...$debug,
                ],
                'closed_by' => $closedBy,
            ]);

            return $fiscalDay->fresh();
        });
    }

    public function poll(FiscalDay $fiscalDay): FiscalDay
    {
        $device = FiscalDevice::findOrFail($fiscalDay->fiscal_device_id);
        $response = $this->client->get($device, 'GetStatus');

        if (!$response->successful()) {
            throw new \Exception('ZIMRA getStatus failed: ' . $response->body());
        }

        $status = $response->json() ?: [];
        $updates = [
            'last_status_response' => $status,
        ];

        if (($status['fiscalDayStatus'] ?? null) === 'FiscalDayClosed') {
            $updates['status'] = 'closed';
            $updates['closed_at'] = $status['fiscalDayClosed'] ?? now();
            $updates['server_signature'] = $status['fiscalDayServerSignature'] ?? null;
        }

        if (($status['fiscalDayStatus'] ?? null) === 'FiscalDayCloseFailed') {
            $updates['status'] = 'close_failed';
            $updates['closing_error_code'] = $status['fiscalDayClosingErrorCode'] ?? null;
        }

        $fiscalDay->update($updates);

        return $fiscalDay->fresh();
    }
}
