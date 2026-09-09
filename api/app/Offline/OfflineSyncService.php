<?php

namespace App\Offline;

use App\Items\Item;
use App\Models\User;
use App\Payments\SaleCheckoutService;
use App\Sales\BasketService;
use App\Sales\Sale;
use App\Sales\SaleStatus;
use App\Services\AuditLogger;
use App\Shifts\Shift;
use App\Shifts\TillStatus;
use App\Tables\RestaurantTable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Section 12 of the guide: offline sale and offline synchronisation. Each
 * queued transaction is replayed through the normal basket + cash-payment
 * pipeline (so pricing, tax and receipt numbering stay server-authoritative
 * rather than trusting a client-cached total), keyed for idempotency by the
 * client's own locally-generated reference. Only cash is accepted, since a
 * card/mobile tender cannot have been genuinely authorised while offline.
 */
class OfflineSyncService
{
    private const SCALE = 4;

    public function __construct(
        private readonly BasketService $basketService,
        private readonly SaleCheckoutService $checkout,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Processes each queued transaction in the given order, so an earlier
     * failure never blocks the ones that follow.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array<int, array<string, mixed>>
     */
    public function syncBatch(array $transactions, User $actor): array
    {
        return array_map(fn (array $transaction) => $this->syncOne($transaction, $actor), $transactions);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncOne(array $data, User $actor): array
    {
        $clientReference = $data['client_reference'];

        $existing = Sale::where('client_reference', $clientReference)->first();

        if ($existing) {
            return ['client_reference' => $clientReference, 'status' => 'already_synced', 'sale' => $existing];
        }

        try {
            $sale = $this->ingest($data, $actor);

            return ['client_reference' => $clientReference, 'status' => 'created', 'sale' => $sale];
        } catch (ValidationException $e) {
            return ['client_reference' => $clientReference, 'status' => 'failed', 'errors' => $e->errors()];
        }
    }

    private function ingest(array $data, User $actor): Sale
    {
        $shift = Shift::query()->where('id', $data['shift_id'])->where('user_id', $actor->id)->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift_id' => ['This offline sale does not belong to one of your shifts.'],
            ]);
        }

        $till = $shift->tills()->where('status', TillStatus::Open)->latest()->first();

        if (! $till) {
            throw ValidationException::withMessages([
                'shift_id' => ["This shift's till has already been closed; this offline sale needs manual reconciliation."],
            ]);
        }

        $table = ! empty($data['table_id']) ? RestaurantTable::find($data['table_id']) : null;

        $sale = Sale::create([
            'store_id' => $shift->terminal->store_id,
            'terminal_id' => $shift->terminal_id,
            'shift_id' => $shift->id,
            'cashier_id' => $actor->id,
            'table_id' => $table?->id,
            'status' => SaleStatus::Draft,
            'client_reference' => $data['client_reference'],
            'is_offline' => true,
        ]);

        foreach ($data['lines'] as $line) {
            $item = Item::find($line['item_id']);

            if (! $item || ! $item->active) {
                throw ValidationException::withMessages(['lines' => ["Item {$line['item_id']} is not available for sale."]]);
            }

            $this->basketService->addLine($sale, $item, (string) ($line['quantity'] ?? 1));
        }

        $sale = $sale->fresh();

        if (bccomp((string) $sale->total, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages(['lines' => ['This offline sale has no value to record.']]);
        }

        $result = $this->checkout->recordPayment($sale, $actor, [
            'tender_type' => 'cash',
            'idempotency_key' => $data['client_reference'],
            'amount' => (string) $sale->total,
            'amount_tendered' => isset($data['amount_tendered']) ? (string) $data['amount_tendered'] : null,
        ]);

        $sale = $result['sale'];

        if (! empty($data['occurred_at'])) {
            $sale->update(['completed_at' => Carbon::parse($data['occurred_at'])]);
        }

        $this->auditLogger->log($actor, 'sale.offline_synced', $sale, after: ['client_reference' => $data['client_reference']]);

        return $sale->fresh(['lines.item', 'payments']);
    }
}
