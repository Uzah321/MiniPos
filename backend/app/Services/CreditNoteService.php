<?php

namespace App\Services;

use App\Enums\CreditNoteStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\TillStatus;
use App\Models\CashMovement;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Store;
use App\Models\Till;
use App\Models\User;
use App\Services\Payments\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Section 7 of the guide: credit note, return and refund-to-complete.
 * Handles full/partial returns against a completed sale, no-receipt
 * returns, rejection, and refunding via cash, card/mobile (through the
 * same provider abstraction as payments) or store credit — the last of
 * which also completes the "exchange" workflow when paired with
 * SaleCheckoutService's store_credit tender.
 */
class CreditNoteService
{
    private const SCALE = 4;

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly AuditLogger $auditLogger,
        private readonly ManagerVerifier $managerVerifier,
    ) {}

    /**
     * @param  array{sale_id: string, lines: array<int, array{sale_line_id: string, quantity: string, condition?: ?string, disposition?: ?string}>, reason: string, refund_method?: ?string, allow_alternate_method?: bool, manager_pin?: ?string}  $data
     */
    public function initiateReturn(array $data, User $actor): CreditNote
    {
        return DB::transaction(function () use ($data, $actor) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($data['sale_id']);

            if ($sale->store_id !== $actor->store_id) {
                throw ValidationException::withMessages(['sale_id' => ['This sale belongs to a different store.']]);
            }

            if ($sale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale_id' => ['Only a completed sale can be returned.']]);
            }

            $periodDays = (int) config('pos.return_period_days');

            if ($sale->completed_at && $sale->completed_at->lt(now()->subDays($periodDays))) {
                throw ValidationException::withMessages([
                    'sale_id' => ["The return period of {$periodDays} days has expired for this sale."],
                ]);
            }

            $lines = [];
            $total = '0.0000';

            foreach ($data['lines'] as $requested) {
                $saleLine = SaleLine::query()->where('id', $requested['sale_line_id'])->where('sale_id', $sale->id)->first();

                if (! $saleLine) {
                    throw ValidationException::withMessages(['lines' => ['One of the given lines does not belong to this sale.']]);
                }

                $quantity = (string) $requested['quantity'];
                $available = $this->availableToReturn($saleLine);

                if (bccomp($quantity, '0', self::SCALE) <= 0 || bccomp($quantity, $available, self::SCALE) > 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Requested quantity for line {$saleLine->id} exceeds what is still returnable ({$available})."],
                    ]);
                }

                $proportion = bcdiv($quantity, (string) $saleLine->quantity, 8);
                $amount = bcmul((string) $saleLine->line_total, $proportion, self::SCALE);
                $taxAmount = bcmul((string) $saleLine->tax_amount, $proportion, self::SCALE);

                $lines[] = [
                    'sale_line_id' => $saleLine->id,
                    'item_id' => $saleLine->item_id,
                    'quantity' => $quantity,
                    'condition' => $requested['condition'] ?? null,
                    'amount' => $amount,
                    'tax_amount' => $taxAmount,
                    'disposition' => $requested['disposition'] ?? 'restock',
                ];

                $total = bcadd($total, $amount, self::SCALE);
            }

            if (empty($lines)) {
                throw ValidationException::withMessages(['lines' => ['Select at least one line to return.']]);
            }

            $refundMethod = $this->resolveRefundMethod($sale, $data['refund_method'] ?? null, (bool) ($data['allow_alternate_method'] ?? false));

            $manager = null;

            if (bccomp($total, (string) config('pos.return_manager_threshold'), self::SCALE) >= 0) {
                $manager = $this->managerVerifier->verify($data['manager_pin'] ?? null);
            }

            $creditNote = CreditNote::create([
                'store_id' => $sale->store_id,
                'original_sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'status' => CreditNoteStatus::Approved,
                'reason' => $data['reason'],
                'refund_method' => $refundMethod,
                'total' => $total,
                'approved_by' => $manager?->id,
            ]);

            foreach ($lines as $line) {
                $creditNote->lines()->create($line);
            }

            $this->auditLogger->log($manager ?? $actor, 'credit_note.approved', $creditNote, after: $creditNote->toArray());

            return $creditNote->fresh('lines');
        });
    }

    /**
     * No-receipt return: identifies the customer and item directly (there is
     * no sale to look up), always requires manager approval, values the
     * return at the item's current price, and only ever pays out as store
     * credit — a lower-trust path since the original tender is unknown.
     *
     * @param  array{customer_query: string, item_id: int, quantity: string, reason: string, condition?: ?string, disposition?: ?string, manager_pin: ?string}  $data
     */
    public function initiateNoReceiptReturn(array $data, User $actor): CreditNote
    {
        if (! $actor->store_id) {
            throw ValidationException::withMessages(['store_id' => ['The cashier is not assigned to a store.']]);
        }

        $customer = Customer::query()
            ->where('loyalty_id', $data['customer_query'])
            ->orWhere('phone', $data['customer_query'])
            ->orWhere('email', $data['customer_query'])
            ->when(is_numeric($data['customer_query']), fn ($query) => $query->orWhere('id', (int) $data['customer_query']))
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages(['customer_query' => ['No matching customer profile was found.']]);
        }

        $item = Item::query()->where('id', $data['item_id'])->where('active', true)->first();

        if (! $item) {
            throw ValidationException::withMessages(['item_id' => ['Item not found or not available for sale.']]);
        }

        $manager = $this->managerVerifier->verify($data['manager_pin'] ?? null);

        $quantity = (string) $data['quantity'];
        $lineSubtotal = bcmul($quantity, (string) $item->price, self::SCALE);
        $taxAmount = bcmul($lineSubtotal, (string) $item->tax_rate, self::SCALE);
        $amount = bcadd($lineSubtotal, $taxAmount, self::SCALE);

        $creditNote = CreditNote::create([
            'store_id' => $actor->store_id,
            'original_sale_id' => null,
            'customer_id' => $customer->id,
            'status' => CreditNoteStatus::Approved,
            'reason' => $data['reason'],
            'refund_method' => 'store_credit',
            'total' => $amount,
            'approved_by' => $manager->id,
        ]);

        $creditNote->lines()->create([
            'sale_line_id' => null,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'condition' => $data['condition'] ?? null,
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'disposition' => $data['disposition'] ?? 'quarantine',
        ]);

        $this->auditLogger->log($manager, 'credit_note.no_receipt.approved', $creditNote, after: $creditNote->toArray());

        return $creditNote->fresh('lines');
    }

    /**
     * Return rejection: only before any refund has been processed, and
     * moves no inventory or money.
     */
    public function rejectReturn(CreditNote $creditNote, string $reason, User $actor): CreditNote
    {
        if ($creditNote->status !== CreditNoteStatus::Approved) {
            throw ValidationException::withMessages([
                'credit_note' => ['Only an approved, not-yet-refunded return can be rejected.'],
            ]);
        }

        $creditNote->update(['status' => CreditNoteStatus::Rejected]);
        $this->auditLogger->log($actor, 'credit_note.rejected', $creditNote, reason: $reason);

        return $creditNote->fresh();
    }

    public function processRefund(CreditNote $creditNote, User $actor, ?string $simulate = null): CreditNote
    {
        if ($creditNote->status !== CreditNoteStatus::Approved) {
            throw ValidationException::withMessages(['credit_note' => ['Only an approved return can be refunded.']]);
        }

        return match ($creditNote->refund_method) {
            'cash' => $this->refundCash($creditNote, $actor),
            'store_credit' => $this->refundStoreCredit($creditNote, $actor),
            default => $this->refundViaProvider($creditNote, $actor, $simulate),
        };
    }

    /**
     * Refund failure/unknown: re-query the provider by the credit note's
     * refund reference. Only ever resolves a RefundUnknown credit note.
     */
    public function queryRefund(CreditNote $creditNote, User $actor, ?string $resolve): CreditNote
    {
        if ($creditNote->status !== CreditNoteStatus::RefundUnknown) {
            throw ValidationException::withMessages([
                'credit_note' => ['Only a credit note with an unknown refund outcome can be queried.'],
            ]);
        }

        $result = $this->gateway->inquire($creditNote->refund_reference, $resolve);

        if ($result->status === PaymentStatus::Unknown) {
            return $creditNote->fresh();
        }

        if ($result->status === PaymentStatus::Declined) {
            $creditNote->update(['status' => CreditNoteStatus::Failed]);
            $this->auditLogger->log($actor, 'credit_note.refund.failed', $creditNote);

            return $creditNote->fresh();
        }

        return $this->completeReturn($creditNote, $actor, $result->providerReference);
    }

    public function reprintCreditNote(CreditNote $creditNote, User $actor, ?string $reason): CreditNote
    {
        if ($creditNote->status !== CreditNoteStatus::Completed) {
            throw ValidationException::withMessages(['credit_note' => ['Only a completed credit note can be reprinted.']]);
        }

        $creditNote->increment('reprint_count');

        $this->auditLogger->log(
            $actor,
            'credit_note.reprint',
            $creditNote,
            after: ['reprint_count' => $creditNote->reprint_count],
            reason: $reason,
        );

        return $creditNote->fresh();
    }

    private function refundCash(CreditNote $creditNote, User $actor): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $actor) {
            $till = $creditNote->originalSale?->shift?->tills()->where('status', TillStatus::Open)->latest()->first();

            if (! $till) {
                throw ValidationException::withMessages(['refund_method' => ['No open till was found to pay out this cash refund.']]);
            }

            if (bccomp($this->expectedCash($till), (string) $creditNote->total, self::SCALE) < 0) {
                throw ValidationException::withMessages(['refund_method' => ['The till does not have enough cash on hand for this refund.']]);
            }

            $till->cashMovements()->create([
                'type' => 'refund',
                'amount' => $creditNote->total,
                'reason' => 'Cash refund',
                'approved_by' => $creditNote->approved_by,
            ]);

            return $this->completeReturn($creditNote, $actor);
        });
    }

    private function refundStoreCredit(CreditNote $creditNote, User $actor): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $actor) {
            if (! $creditNote->customer_id) {
                throw ValidationException::withMessages(['refund_method' => ['A customer must be identified for a store-credit refund.']]);
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($creditNote->customer_id);
            $customer->increment('store_credit_balance', (string) $creditNote->total);

            return $this->completeReturn($creditNote, $actor);
        });
    }

    private function refundViaProvider(CreditNote $creditNote, User $actor, ?string $simulate): CreditNote
    {
        $originalPayment = $creditNote->originalSale?->payments()
            ->where('tender_type', $creditNote->refund_method)
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Settled])
            ->latest()
            ->first();

        if (! $originalPayment) {
            throw ValidationException::withMessages(['refund_method' => ['No matching original payment was found to refund.']]);
        }

        $result = $this->gateway->refund($originalPayment->provider_reference, (float) $creditNote->total, $simulate);

        if ($result->status === PaymentStatus::Declined) {
            $creditNote->update(['status' => CreditNoteStatus::Failed, 'refund_reference' => $result->providerReference]);
            $this->auditLogger->log($actor, 'credit_note.refund.failed', $creditNote, after: ['reference' => $result->providerReference]);

            return $creditNote->fresh();
        }

        if ($result->status === PaymentStatus::Unknown) {
            $creditNote->update(['status' => CreditNoteStatus::RefundUnknown, 'refund_reference' => $result->providerReference]);
            $this->auditLogger->log($actor, 'credit_note.refund.unknown', $creditNote, after: ['reference' => $result->providerReference]);

            return $creditNote->fresh();
        }

        return $this->completeReturn($creditNote, $actor, $result->providerReference);
    }

    private function completeReturn(CreditNote $creditNote, User $actor, ?string $refundReference = null): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $actor, $refundReference) {
            $store = Store::query()->lockForUpdate()->findOrFail($creditNote->store_id);
            $creditNoteNumber = sprintf('%s-CN-%06d', $store->code, $store->next_credit_note_number);
            $store->increment('next_credit_note_number');

            $creditNote->update([
                'status' => CreditNoteStatus::Completed,
                'credit_note_number' => $creditNoteNumber,
                'issued_at' => now(),
                'refund_reference' => $refundReference ?? $creditNote->refund_reference,
            ]);

            foreach ($creditNote->lines()->whereNotNull('sale_line_id')->get() as $line) {
                $line->saleLine?->increment('returned_quantity', $line->quantity);
            }

            $sale = $creditNote->originalSale;

            if ($sale) {
                $sale->refresh();
                $fullyReturned = $sale->lines()->get()->every(
                    fn (SaleLine $line) => bccomp((string) $line->returned_quantity, (string) $line->quantity, self::SCALE) >= 0
                );

                if ($fullyReturned) {
                    $sale->update(['status' => SaleStatus::Returned]);
                }

                $this->reverseLoyaltyEarn($sale, $creditNote, $actor);
            }

            $this->auditLogger->log($actor, 'credit_note.completed', $creditNote, after: $creditNote->toArray());

            return $creditNote->fresh('lines');
        });
    }

    private function reverseLoyaltyEarn(Sale $sale, CreditNote $creditNote, User $actor): void
    {
        if (! $sale->customer_id || $sale->loyalty_points_earned <= 0 || bccomp((string) $sale->total, '0', self::SCALE) <= 0) {
            return;
        }

        $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
        $share = (float) $creditNote->total / (float) $sale->total;
        $reversedPoints = min((int) floor($share * $sale->loyalty_points_earned), $customer->loyalty_points_balance);

        if ($reversedPoints > 0) {
            $customer->decrement('loyalty_points_balance', $reversedPoints);
            $this->auditLogger->log($actor, 'credit_note.loyalty_reversed', $creditNote, after: ['points' => $reversedPoints]);
        }
    }

    private function availableToReturn(SaleLine $saleLine): string
    {
        $reservedByInFlightReturns = CreditNoteLine::query()
            ->where('sale_line_id', $saleLine->id)
            ->whereHas('creditNote', fn ($query) => $query->whereIn('status', [
                CreditNoteStatus::Approved, CreditNoteStatus::RefundPending, CreditNoteStatus::RefundUnknown,
            ]))
            ->get()
            ->reduce(fn (string $carry, CreditNoteLine $line) => bcadd($carry, (string) $line->quantity, self::SCALE), '0.0000');

        return bcsub(
            bcsub((string) $saleLine->quantity, (string) $saleLine->returned_quantity, self::SCALE),
            $reservedByInFlightReturns,
            self::SCALE
        );
    }

    private function resolveRefundMethod(Sale $sale, ?string $requested, bool $allowAlternate): string
    {
        $tenderTypes = $sale->payments()
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Settled])
            ->get()
            ->groupBy('tender_type')
            ->map(fn ($payments) => $payments->reduce(fn (string $carry, $payment) => bcadd($carry, (string) $payment->amount, self::SCALE), '0.0000'))
            ->sortDesc();

        $originalMethod = $tenderTypes->keys()->first() ?? 'cash';

        if (! $requested) {
            return $originalMethod;
        }

        if ($requested !== $originalMethod && ! $allowAlternate) {
            throw ValidationException::withMessages([
                'refund_method' => ["The original tender was {$originalMethod}. Set allow_alternate_method to refund via {$requested} instead."],
            ]);
        }

        return $requested;
    }

    private function expectedCash(Till $till): string
    {
        return $till->cashMovements()
            ->get()
            ->reduce(function (string $carry, CashMovement $movement) {
                $sign = in_array($movement->type, ['refund', 'paid_out'], true) ? '-1' : '1';

                return bcadd($carry, bcmul((string) $movement->amount, $sign, self::SCALE), self::SCALE);
            }, '0.0000');
    }
}
