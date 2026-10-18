<?php

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Exceptions\HospitalityTableScopeException;
use Modules\Sales\Models\HospitalityTableOrder;
use Modules\Sales\Models\KitchenPrintJob;

/**
 * Durable QZ dispatch boundary for pre-payment Hospitality orders.
 *
 * A ticket is uniquely identified by the table order's content revision. A
 * client retry therefore returns the existing job, while a genuine cart edit
 * produces an update ticket at the next content revision.
 */
class KitchenPrintDispatchService
{
    public function __construct(
        private readonly KitchenOrderPrintBuilder $builder,
        private readonly HospitalityOutputConfigurationService $output,
        private readonly HospitalityOrderNumberService $numbers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function enqueueTableOrder(
        HospitalityTableOrder $order,
        PosTill $till,
        PosUser $actor,
        string $commandId
    ): array {
        return DB::transaction(function () use ($order, $till, $actor, $commandId): array {
            /** @var HospitalityTableOrder $locked */
            $locked = HospitalityTableOrder::query()
                ->whereKey($order->id)
                ->where('company_id', $order->company_id)
                ->where('branch_id', $order->branch_id)
                ->lockForUpdate()
                ->firstOrFail();

            $shopId = $till->shop_id ?: Shop::query()
                ->where('company_id', $till->company_id)
                ->where('branch_id', $till->branch_id)
                ->where('status', 'active')
                ->value('id');
            if ((string) $locked->company_id !== (string) $till->company_id
                || (string) $locked->branch_id !== (string) $till->branch_id
                || ! $till->is_active
                || $till->trashed()
                || ! $shopId
                || (string) $locked->shop_id !== (string) $shopId) {
                throw new HospitalityTableScopeException(
                    'The table order and kitchen printer do not belong to the same company, shop and till.'
                );
            }

            $locked->loadMissing(['table', 'lines']);
            $commandReplay = KitchenPrintJob::query()
                ->where('company_id', $locked->company_id)
                ->where('branch_id', $locked->branch_id)
                ->where('hospitality_table_order_id', $locked->id)
                ->where('command_id', $commandId)
                ->lockForUpdate()
                ->first();
            if ($commandReplay) {
                return $this->envelope(
                    $locked,
                    $till,
                    $commandReplay,
                    $this->output->forTill($till),
                    true
                );
            }

            $contentRevision = max(1, (int) ($locked->content_revision ?: 1));
            $dispatchKey = hash('sha256', implode('|', [
                'hospitality_table_order',
                $locked->company_id,
                $locked->branch_id,
                $locked->id,
                $contentRevision,
                'kitchen',
            ]));

            $existing = KitchenPrintJob::query()
                ->where('dispatch_key', $dispatchKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $this->envelope(
                    $locked,
                    $till,
                    $existing,
                    $this->output->forTill($till),
                    true
                );
            }

            $orderNumber = $this->ensureOrderNumber($locked);
            $ticketKind = $locked->last_kitchen_content_revision
                ? 'order_update'
                : 'new_order';
            $payload = $this->tableOrderPayload($locked, $till, $actor, $orderNumber, $ticketKind);
            $runtime = $this->output->forTill($till);
            $printer = $runtime['printer'] ?? null;

            $job = KitchenPrintJob::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $locked->company_id,
                'branch_id' => $locked->branch_id,
                'till_id' => $till->id,
                'sales_transaction_id' => null,
                'hospitality_table_order_id' => $locked->id,
                'dispatch_revision' => $contentRevision,
                'dispatch_key' => $dispatchKey,
                'command_id' => $commandId,
                'ticket_kind' => $ticketKind,
                'order_number' => $orderNumber,
                'target_printing' => 'kitchen',
                'idempotency_key' => $dispatchKey,
                'printer_id' => $printer['id'] ?? null,
                'printer_name' => $printer['printer_name'] ?? null,
                'status' => ($runtime['ready'] ?? false) ? 'pending' : 'pending_configuration',
                'attempt_count' => 0,
                'payload' => $payload,
                'metadata' => [
                    'source' => 'hospitality_table_order',
                    'source_id' => $locked->id,
                    'content_revision' => $contentRevision,
                    'command_id' => $commandId,
                    'created_by' => $actor->id,
                ],
            ]);

            return $this->envelope($locked, $till, $job, $runtime, false);
        }, 3);
    }

    private function ensureOrderNumber(HospitalityTableOrder $order): string
    {
        if (trim((string) $order->order_number) !== '') {
            return (string) $order->order_number;
        }

        [$businessDate, $tradingDayId] = $this->tradingDay(
            (string) $order->company_id,
            (string) $order->branch_id
        );
        $allocation = $this->numbers->allocate(
            (string) $order->company_id,
            (string) $order->branch_id,
            $businessDate,
            $tradingDayId
        );

        $order->forceFill([
            'order_number' => $allocation['order_number'],
            'order_sequence' => $allocation['order_sequence'],
            'order_business_date' => $allocation['business_date'],
            'trading_day_id' => $allocation['trading_day_id'],
        ])->save();

        return (string) $allocation['order_number'];
    }

    /** @return array{0: string, 1: ?string} */
    private function tradingDay(string $companyId, string $branchId): array
    {
        if (! Schema::hasTable('trading_days')) {
            return [now()->toDateString(), null];
        }

        $day = DB::table('trading_days')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['open', 'day_end_running'])
            ->orderByRaw("CASE WHEN status = 'day_end_running' THEN 0 ELSE 1 END")
            ->orderByDesc('trading_date')
            ->first();

        if (! $day) {
            return [now()->toDateString(), null];
        }

        $date = $day->business_date ?? $day->trading_date ?? now()->toDateString();

        return [Carbon::parse($date)->toDateString(), (string) $day->id];
    }

    /** @return array<string, mixed> */
    private function tableOrderPayload(
        HospitalityTableOrder $order,
        PosTill $till,
        PosUser $actor,
        string $orderNumber,
        string $ticketKind
    ): array {
        $businessDate = $order->order_business_date;
        if ($businessDate instanceof \DateTimeInterface) {
            $businessDate = $businessDate->format('Y-m-d');
        }

        return [
            'type' => 'kitchen_order',
            'ticket_kind' => $ticketKind,
            'order_number' => $orderNumber,
            'invoice_number' => null,
            'business_date' => $businessDate ? (string) $businessDate : null,
            'transaction_date' => now()->format('Y-m-d H:i:s'),
            'branch' => [
                'id' => $order->branch_id,
                'name' => null,
            ],
            'till' => [
                'id' => $till->id,
                'code' => $till->till_code,
                'name' => $till->till_name,
            ],
            'cashier' => $actor->name ?: $actor->username ?: 'Cashier',
            'customer' => 'Walk-in Customer',
            'service_type' => $order->service_type,
            'table' => [
                'id' => $order->hospitality_table_id,
                'name' => $order->table?->name,
            ],
            'hospitality_table_order_id' => $order->id,
            'content_revision' => (int) $order->content_revision,
            'notes' => $order->notes,
            'items' => $order->lines->map(function ($line): array {
                $metadata = is_array($line->metadata) ? $line->metadata : [];

                return [
                    'line_id' => $line->id,
                    'line_no' => (int) $line->line_no,
                    'product_id' => $line->product_id,
                    'name' => $line->line_name,
                    'quantity' => (float) $line->quantity,
                    'uom' => data_get(
                        $metadata,
                        'uom_code',
                        data_get($metadata, 'pos_line.unit', data_get($metadata, 'uom', 'Each'))
                    ),
                    'prep_messages' => data_get(
                        $metadata,
                        'kitchen.prep_messages',
                        data_get(
                            $metadata,
                            'pos_line.prep_messages',
                            data_get($metadata, 'prep_messages', [])
                        )
                    ) ?: [],
                    'prep_note' => data_get(
                        $metadata,
                        'kitchen.prep_note',
                        data_get(
                            $metadata,
                            'pos_line.prep_note',
                            data_get($metadata, 'prep_note')
                        )
                    ),
                    'modifiers' => collect(data_get(
                        $metadata,
                        'kitchen.modifiers',
                        data_get(
                            $metadata,
                            'pos_line.modifiers',
                            data_get($metadata, 'modifiers', [])
                        )
                    ) ?: [])->filter(
                        fn ($modifier): bool => is_array($modifier)
                            && ($modifier['print_on_kitchen'] ?? true) === true
                    )->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function envelope(
        HospitalityTableOrder $order,
        PosTill $till,
        KitchenPrintJob $job,
        array $runtime,
        bool $replayed
    ): array {
        $payload = is_array($job->payload) ? $job->payload : [];
        $printer = $runtime['printer'] ?? null;

        return [
            'enabled' => true,
            'mode' => $runtime['mode'],
            'auto_print' => (bool) ($runtime['ready'] ?? false) && $job->status === 'pending',
            'ready' => (bool) ($runtime['ready'] ?? false),
            'copies' => (int) ($runtime['copies'] ?? 1),
            'dispatch_on' => 'order_submit',
            'target_printing' => 'kitchen',
            'capabilities' => $runtime['capabilities'] ?? [],
            'printer' => $printer,
            'qz' => $runtime['qz'] ?? [
                'certificate_configured' => false,
                'transport' => 'qz',
            ],
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'attempt_count' => (int) ($job->attempt_count ?? 0),
                'source' => 'hospitality_table_order',
                'source_id' => $order->id,
                'dispatch_revision' => (int) $job->dispatch_revision,
                'ticket_kind' => $job->ticket_kind,
                'idempotent_replay' => $replayed,
            ],
            'payload' => $payload,
            'html' => $this->builder->renderKitchenHtml($payload, [
                'paper_size' => $printer['paper_size'] ?? '80mm',
            ]),
            'raw_text' => $this->builder->renderKitchenText($payload),
        ];
    }
}
