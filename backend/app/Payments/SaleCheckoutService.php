<?php

namespace App\Payments;

use App\Payments\PaymentStatus;
use App\Sales\SaleStatus;
use App\Shifts\TillStatus;
use App\Customers\Customer;
use App\Payments\Payment;
use App\Sales\Sale;
use App\Models\Store;
use App\Models\User;
use App\Payments\Gateways\PaymentGatewayInterface;
use App\Payments\Gateways\PaymentGatewayResult;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the sale-to-complete lifecycle from Section 2 & 5 of the guide:
 * recording a payment attempt (cash, card or mobile/QR, single or split
 * tender), committing the sale once it is fully paid, and reprinting the
 * resulting receipt. Every attempt is idempotent by client-supplied key,
 * and a sale is only ever marked completed inside the same atomic
 * transaction that allocates its receipt number.
 *
 * Card/mobile attempts call the provider outside any local transaction
 * (a real provider round-trip cannot be held inside a DB lock), then
 * re-validate and persist the result in a second, short transaction. If
 * the provider approved but that second step is no longer valid Ã¢â‚¬â€ the
 * remaining balance changed underneath it, or a fault is simulated for
 * testing Ã¢â‚¬â€ the authorisation is reversed with the provider rather than
 * silently discarded, per the guide's "authorisation reversal" control.
 */
class SaleCheckoutService
{
    private const SCALE = 4;

    private const APPLIED_STATUSES = [PaymentStatus::Captured, PaymentStatus::Settled];

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{tender_type: string, idempotency_key: string, amount: string, amount_tendered?: string|null, simulate?: string|null}  $data
     * @return array{payment: Payment, sale: Sale, change: ?string}
     */
    public function recordPayment(Sale $sale, User $actor, array $data): array
    {
        $prepared = $this->prepareAttempt($sale, $data);

        if (isset($prepared['existing'])) {
            return ['payment' => $prepared['existing'], 'sale' => $prepared['sale']->fresh(), 'change' => null];
        }

        $sale = $prepared['sale'];
        $amount = $prepared['amount'];

        if ($data['tender_type'] === 'cash') {
            return $this->recordCashPayment($sale, $actor, $data, $amount);
        }

        if ($data['tender_type'] === 'store_credit') {
            return $this->recordStoreCreditPayment($sale, $actor, $data, $amount);
        }

        $result = $this->gateway->authorize($data['tender_type'], $data['idempotency_key'], (float) $amount, $data['simulate'] ?? null);

        return match ($result->status) {
            PaymentStatus::Declined, PaymentStatus::Unknown => $this->persistTerminalOutcome($sale, $actor, $data, $amount, $result),
            default => $this->finalizeApproval($sale, $actor, $data, $amount, $result),
        };
    }

    /**
     * Payment timeout/unknown: re-query the provider by the original
     * reference. Only ever resolves an attempt already marked Unknown, and
     * is safe to call repeatedly while it stays unresolved.
     *
     * @return array{payment: Payment, sale: Sale, change: ?string}
     */
    public function queryPayment(Sale $sale, Payment $payment, User $actor, ?string $resolve): array
    {
        if ($payment->status !== PaymentStatus::Unknown) {
            throw ValidationException::withMessages([
                'payment' => ['Only a payment with an unknown outcome can be queried.'],
            ]);
        }

        $result = $this->gateway->inquire($payment->provider_reference, $resolve);

        if ($result->status === PaymentStatus::Unknown) {
            return ['payment' => $payment->fresh(), 'sale' => $sale->fresh(), 'change' => null];
        }

        if ($result->status === PaymentStatus::Declined) {
            $payment->update(['status' => PaymentStatus::Declined]);
            $this->auditLogger->log($actor, 'payment.declined', $payment, after: $payment->toArray());

            return ['payment' => $payment->fresh(), 'sale' => $sale->fresh(), 'change' => null];
        }

        $data = ['tender_type' => $payment->tender_type, 'idempotency_key' => $payment->idempotency_key, 'simulate' => null];

        return $this->finalizeApproval($sale, $actor, $data, (string) $payment->amount, $result, existing: $payment);
    }

    /**
     * Payment cancellation: only while an attempt is still unresolved
     * (Pending or Unknown) Ã¢â‚¬â€ a captured/declined attempt is already final.
     * For split tender this also covers "partial payment cancellation":
     * cancelling one unresolved leg leaves the sale's other captured
     * payments, and its recoverable basket, untouched.
     */
    public function cancelPayment(Sale $sale, Payment $payment, User $actor, ?string $reason): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Unknown], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Only a pending or unresolved payment attempt can be cancelled.'],
            ]);
        }

        $payment->update(['status' => PaymentStatus::Cancelled]);
        $this->auditLogger->log($actor, 'payment.cancelled', $payment, reason: $reason);

        return $payment->fresh();
    }

    public function reprintReceipt(Sale $sale, ?User $actor, ?string $reason): Sale
    {
        if ($sale->status !== SaleStatus::Completed) {
            throw ValidationException::withMessages([
                'sale' => ['Only a completed sale has a receipt to reprint.'],
            ]);
        }

        $sale->increment('reprint_count');

        $this->auditLogger->log(
            $actor,
            'sale.receipt.reprint',
            $sale,
            after: ['reprint_count' => $sale->reprint_count],
            reason: $reason,
        );

        return $sale->fresh();
    }

    /**
     * Validate the attempt and reserve its amount under a short lock,
     * checked-and-released before any external provider call is made.
     *
     * @return array{existing: Payment, sale: Sale}|array{sale: Sale, amount: string}
     */
    private function prepareAttempt(Sale $sale, array $data): array
    {
        return DB::transaction(function () use ($sale, $data) {
            /** @var Sale $sale */
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            $existing = Payment::query()
                ->where('sale_id', $sale->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return ['existing' => $existing, 'sale' => $sale];
            }

            if (! in_array($sale->status, [SaleStatus::Priced, SaleStatus::PaymentPending], true)) {
                throw ValidationException::withMessages([
                    'sale' => ["This sale cannot accept payment while it is {$sale->status->value}."],
                ]);
            }

            $remaining = bcsub((string) $sale->total, $this->paidTotal($sale), self::SCALE);

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages(['sale' => ['This sale is already fully paid.']]);
            }

            $amount = (string) $data['amount'];

            if (bccomp($amount, '0', self::SCALE) <= 0 || bccomp($amount, $remaining, self::SCALE) > 0) {
                throw ValidationException::withMessages([
                    'amount' => ["Amount must be greater than zero and no more than the remaining balance of {$remaining}."],
                ]);
            }

            return ['sale' => $sale, 'amount' => $amount];
        });
    }

    /**
     * @return array{payment: Payment, sale: Sale, change: string}
     */
    private function recordCashPayment(Sale $sale, User $actor, array $data, string $amount): array
    {
        return DB::transaction(function () use ($sale, $actor, $data, $amount) {
            $tendered = (string) ($data['amount_tendered'] ?? $amount);

            if (bccomp($tendered, $amount, self::SCALE) < 0) {
                throw ValidationException::withMessages([
                    'amount_tendered' => ['Cash tendered is less than the amount being applied to this sale.'],
                ]);
            }

            $change = bcsub($tendered, $amount, self::SCALE);

            $payment = $sale->payments()->create([
                'idempotency_key' => $data['idempotency_key'],
                'tender_type' => 'cash',
                'status' => PaymentStatus::Captured,
                'amount' => $amount,
            ]);

            $this->recordCashMovement($sale, $amount, $actor);
            $this->auditLogger->log($actor, 'payment.captured', $payment, after: $payment->toArray());

            $sale = $this->settle($sale, $actor);

            return ['payment' => $payment->fresh(), 'sale' => $sale, 'change' => $change];
        });
    }

    /**
     * Store credit as a tender: pays for a sale out of the attached
     * customer's balance (built up via a store-credit refund). This is what
     * makes an exchange work Ã¢â‚¬â€ refund the returned item to store credit,
     * then spend that balance on the replacement sale.
     *
     * @return array{payment: Payment, sale: Sale, change: null}
     */
    private function recordStoreCreditPayment(Sale $sale, User $actor, array $data, string $amount): array
    {
        return DB::transaction(function () use ($sale, $actor, $data, $amount) {
            if (! $sale->customer_id) {
                throw ValidationException::withMessages([
                    'tender_type' => ['A customer must be attached to this sale to pay by store credit.'],
                ]);
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);

            if (bccomp((string) $customer->store_credit_balance, $amount, self::SCALE) < 0) {
                throw ValidationException::withMessages([
                    'amount' => ['This customer does not have enough store credit for that amount.'],
                ]);
            }

            $customer->decrement('store_credit_balance', $amount);

            $payment = $sale->payments()->create([
                'idempotency_key' => $data['idempotency_key'],
                'tender_type' => 'store_credit',
                'status' => PaymentStatus::Captured,
                'amount' => $amount,
            ]);

            $this->auditLogger->log($actor, 'payment.captured', $payment, after: $payment->toArray());

            $sale = $this->settle($sale, $actor);

            return ['payment' => $payment->fresh(), 'sale' => $sale, 'change' => null];
        });
    }

    /**
     * Persist a Declined or Unknown outcome. Neither touches the sale: a
     * decline keeps it unpaid, and an unknown result waits to be resolved
     * by queryPayment or explicitly cancelled.
     *
     * @return array{payment: Payment, sale: Sale, change: null}
     */
    private function persistTerminalOutcome(Sale $sale, User $actor, array $data, string $amount, PaymentGatewayResult $result): array
    {
        $payment = $sale->payments()->create([
            'idempotency_key' => $data['idempotency_key'],
            'tender_type' => $data['tender_type'],
            'status' => $result->status,
            'amount' => $amount,
            'provider_reference' => $result->providerReference,
        ]);

        $this->auditLogger->log(
            $actor,
            $result->status === PaymentStatus::Unknown ? 'payment.unknown' : 'payment.declined',
            $payment,
            after: $payment->toArray(),
        );

        return ['payment' => $payment->fresh(), 'sale' => $sale->fresh(), 'change' => null];
    }

    /**
     * Persist (or resolve, if $existing is given) a provider-approved
     * attempt. Re-locks the sale and re-validates the amount still fits the
     * remaining balance Ã¢â‚¬â€ if it no longer does, or a commit failure is
     * simulated for testing, the authorisation is reversed instead of
     * being committed.
     *
     * @return array{payment: Payment, sale: Sale, change: null}
     */
    private function finalizeApproval(Sale $sale, User $actor, array $data, string $amount, PaymentGatewayResult $result, ?Payment $existing = null): array
    {
        return DB::transaction(function () use ($sale, $actor, $data, $amount, $result, $existing) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            // $existing (an Unknown attempt being resolved) is never counted by paidTotal(),
            // so this reflects the balance still outstanding regardless of which path called us.
            $remaining = bcsub((string) $sale->total, $this->paidTotal($sale), self::SCALE);
            $overCommitted = bccomp($amount, $remaining, self::SCALE) > 0;
            $forcedFailure = ($data['simulate'] ?? null) === 'commit_failure';

            if ($overCommitted || $forcedFailure) {
                $this->gateway->reverse($result->providerReference);

                $payment = $existing
                    ? tap($existing)->update(['status' => PaymentStatus::Reversed, 'provider_reference' => $result->providerReference])
                    : $sale->payments()->create([
                        'idempotency_key' => $data['idempotency_key'],
                        'tender_type' => $data['tender_type'],
                        'status' => PaymentStatus::Reversed,
                        'amount' => $amount,
                        'provider_reference' => $result->providerReference,
                    ]);

                $this->auditLogger->log(
                    $actor,
                    'payment.reversed',
                    $payment,
                    reason: $overCommitted
                        ? 'The remaining balance changed after authorisation; the payment was reversed.'
                        : 'Authorisation succeeded but the sale commit failed; the payment was reversed.',
                );

                return ['payment' => $payment->fresh(), 'sale' => $sale->fresh(), 'change' => null];
            }

            $payment = $existing
                ? tap($existing)->update(['status' => PaymentStatus::Captured, 'provider_reference' => $result->providerReference])
                : $sale->payments()->create([
                    'idempotency_key' => $data['idempotency_key'],
                    'tender_type' => $data['tender_type'],
                    'status' => PaymentStatus::Captured,
                    'amount' => $amount,
                    'provider_reference' => $result->providerReference,
                ]);

            $this->auditLogger->log($actor, 'payment.captured', $payment, after: $payment->toArray());

            $sale = $this->settle($sale, $actor);

            return ['payment' => $payment->fresh(), 'sale' => $sale, 'change' => null];
        });
    }

    private function settle(Sale $sale, User $actor): Sale
    {
        $paid = $this->paidTotal($sale);

        if (bccomp($paid, (string) $sale->total, self::SCALE) < 0) {
            $sale->update(['status' => SaleStatus::PaymentPending]);

            return $sale->fresh();
        }

        $store = Store::query()->lockForUpdate()->findOrFail($sale->store_id);
        $receiptNumber = sprintf('%s-%06d', $store->code, $store->next_receipt_number);
        $store->increment('next_receipt_number');

        $earnedPoints = 0;

        if ($sale->customer_id) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($sale->customer_id);
            $earnedPoints = (int) floor((float) $sale->total * (float) config('pos.loyalty_earn_rate'));

            if ($earnedPoints > 0) {
                $customer->increment('loyalty_points_balance', $earnedPoints);
            }
        }

        $sale->update([
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
            'receipt_number' => $receiptNumber,
            'receipt_issued_at' => now(),
            'loyalty_points_earned' => $earnedPoints,
        ]);

        $this->auditLogger->log($actor, 'sale.completed', $sale, after: $sale->toArray());

        return $sale->fresh();
    }

    private function paidTotal(Sale $sale): string
    {
        return $sale->payments()
            ->whereIn('status', self::APPLIED_STATUSES)
            ->get()
            ->reduce(fn (string $carry, Payment $payment) => bcadd($carry, (string) $payment->amount, self::SCALE), '0.0000');
    }

    private function recordCashMovement(Sale $sale, string $amount, User $actor): void
    {
        $till = $sale->shift?->tills()->where('status', TillStatus::Open)->latest()->first();

        if (! $till) {
            throw ValidationException::withMessages([
                'tender_type' => ['No open till was found for this sale\'s shift to accept a cash payment.'],
            ]);
        }

        $till->cashMovements()->create([
            'type' => 'sale',
            'amount' => $amount,
            'reason' => 'Cash sale',
            'approved_by' => null,
        ]);
    }
}
