<?php

namespace App\Shifts;

use App\Models\User;
use App\Payments\Payment;
use App\Payments\PaymentStatus;
use App\Sales\Sale;
use App\Sales\SaleStatus;
use App\Services\AuditLogger;
use App\Services\ManagerVerifier;
use Illuminate\Validation\ValidationException;

/**
 * Section 11 of the guide: cash drawer and shift close. Every movement that
 * touches the physical drawer without a sale or refund behind it (no-sale,
 * paid-in, paid-out, cash drop) always requires manager approval, per the
 * guide's wording for each of those workflows.
 */
class CashDrawerService
{
    private const SCALE = 4;

    private const OPEN_SALE_STATUSES = [
        SaleStatus::Draft, SaleStatus::Priced, SaleStatus::PaymentPending, SaleStatus::PaymentUnknown,
    ];

    public function __construct(
        private readonly ManagerVerifier $managerVerifier,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Sum of every cash movement on the till, signed so refunds, paid-outs
     * and cash drops reduce it. This is the amount the drawer should hold
     * before it is physically counted.
     */
    public function expectedCash(Till $till): string
    {
        return $till->cashMovements()
            ->get()
            ->reduce(function (string $carry, CashMovement $movement) {
                $sign = in_array($movement->type, ['refund', 'paid_out', 'cash_drop'], true) ? '-1' : '1';

                return bcadd($carry, bcmul((string) $movement->amount, $sign, self::SCALE), self::SCALE);
            }, '0.0000');
    }

    public function noSale(Till $till, string $reason, ?string $managerPin, User $actor): CashMovement
    {
        $manager = $this->managerVerifier->verify($managerPin);

        $movement = $till->cashMovements()->create([
            'type' => 'no_sale',
            'amount' => '0.0000',
            'reason' => $reason,
            'approved_by' => $manager->id,
        ]);

        $this->auditLogger->log($actor, 'till.no_sale', $movement, reason: $reason);

        return $movement;
    }

    public function paidIn(Till $till, string $amount, string $reason, ?string $managerPin, User $actor): CashMovement
    {
        return $this->recordApprovedMovement($till, 'paid_in', $amount, $reason, $managerPin, $actor);
    }

    public function paidOut(Till $till, string $amount, string $reason, ?string $managerPin, User $actor): CashMovement
    {
        return $this->recordApprovedMovement($till, 'paid_out', $amount, $reason, $managerPin, $actor);
    }

    public function cashDrop(Till $till, string $amount, ?string $managerPin, User $actor): CashMovement
    {
        return $this->recordApprovedMovement($till, 'cash_drop', $amount, 'Cash drop to safe', $managerPin, $actor);
    }

    /**
     * Count: stops the till at its counted total and computes the
     * variance. A variance at or beyond the configured threshold blocks
     * the shift on manager review before the till can close.
     */
    public function count(Till $till, string $countedAmount, User $actor): Till
    {
        if ($till->status !== TillStatus::Open) {
            throw ValidationException::withMessages(['till' => ['Only an open till can be counted.']]);
        }

        $expected = $this->expectedCash($till);
        $variance = bcsub($countedAmount, $expected, self::SCALE);
        $threshold = (string) config('pos.till_variance_manager_threshold');

        $till->update([
            'status' => TillStatus::Counting,
            'closing_count' => $countedAmount,
            'variance' => $variance,
        ]);

        $shift = $till->shift;
        $withinThreshold = bccomp(self::abs($variance), $threshold, self::SCALE) < 0;
        $shift->update(['status' => $withinThreshold ? ShiftStatus::Reconciling : ShiftStatus::VarianceUnderReview]);

        $this->auditLogger->log($actor, 'till.counted', $till, after: ['counted' => $countedAmount, 'expected' => $expected, 'variance' => $variance]);

        return $till->fresh();
    }

    public function resolveVariance(Till $till, string $explanation, ?string $managerPin, User $actor): Till
    {
        if ($till->shift->status !== ShiftStatus::VarianceUnderReview) {
            throw ValidationException::withMessages(['till' => ['This till has no variance under review.']]);
        }

        $manager = $this->managerVerifier->verify($managerPin);

        $till->update(['manager_verified_by' => $manager->id]);
        $till->shift->update(['status' => ShiftStatus::Reconciling]);

        $this->auditLogger->log($actor, 'till.variance_resolved', $till, reason: $explanation, after: ['variance' => $till->variance]);

        return $till->fresh();
    }

    public function closeTill(Till $till, User $actor): Till
    {
        if ($till->shift->status !== ShiftStatus::Reconciling) {
            throw ValidationException::withMessages([
                'till' => ['The till must be counted, and any variance resolved, before it can be closed.'],
            ]);
        }

        $till->update(['status' => TillStatus::Closed]);

        $this->auditLogger->log($actor, 'till.closed', $till, after: ['closing_count' => $till->closing_count, 'variance' => $till->variance]);

        return $till->fresh();
    }

    public function closeShift(Shift $shift, User $actor): Shift
    {
        if ($shift->status !== ShiftStatus::Reconciling) {
            throw ValidationException::withMessages(['shift' => ['This shift is not ready to close yet.']]);
        }

        if ($shift->tills()->where('status', '!=', TillStatus::Closed)->exists()) {
            throw ValidationException::withMessages(['shift' => ['Every till on this shift must be closed first.']]);
        }

        $unsettled = Sale::query()
            ->where('shift_id', $shift->id)
            ->whereNull('exception_reviewed_by')
            ->where(function ($query) {
                $query->whereIn('status', self::OPEN_SALE_STATUSES)
                    ->orWhereHas('payments', fn ($paymentQuery) => $paymentQuery->where('status', PaymentStatus::Unknown));
            })
            ->exists();

        if ($unsettled) {
            throw ValidationException::withMessages([
                'shift' => ['This shift still has an active basket or unresolved payment. Place it on exception hold to close anyway.'],
            ]);
        }

        $shift->update(['status' => ShiftStatus::Closed, 'closed_at' => now()]);

        $this->auditLogger->log($actor, 'shift.closed', $shift);

        return $shift->fresh();
    }

    /**
     * Interim totals for the till's shift, without stopping new sales or
     * revealing anything the actual count endpoint hasn't already computed.
     *
     * @return array<string, mixed>
     */
    public function xReport(Till $till): array
    {
        $movementTotals = $till->cashMovements()
            ->get()
            ->groupBy('type')
            ->map(fn ($movements) => $movements->reduce(fn (string $carry, CashMovement $m) => bcadd($carry, (string) $m->amount, self::SCALE), '0.0000'));

        $paymentTotals = Payment::query()
            ->whereIn('sale_id', $till->shift->sales()->pluck('id'))
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Settled])
            ->get()
            ->groupBy('tender_type')
            ->map(fn ($payments) => $payments->reduce(fn (string $carry, Payment $p) => bcadd($carry, (string) $p->amount, self::SCALE), '0.0000'));

        return [
            'opening_float' => (string) $till->opening_float,
            'expected_cash' => $this->expectedCash($till),
            'cash_movements' => $movementTotals,
            'payments_by_tender' => $paymentTotals,
            'sales_count' => $till->shift->sales()->whereIn('status', [SaleStatus::Completed])->count(),
        ];
    }

    private function recordApprovedMovement(Till $till, string $type, string $amount, string $reason, ?string $managerPin, User $actor): CashMovement
    {
        if ($till->status !== TillStatus::Open) {
            throw ValidationException::withMessages(['till' => ['This till is not open.']]);
        }

        $manager = $this->managerVerifier->verify($managerPin);

        $movement = $till->cashMovements()->create([
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'approved_by' => $manager->id,
        ]);

        $this->auditLogger->log($actor, "till.{$type}", $movement, reason: $reason, after: ['amount' => $amount]);

        return $movement;
    }

    private static function abs(string $value): string
    {
        return bccomp($value, '0', self::SCALE) < 0 ? bcmul($value, '-1', self::SCALE) : $value;
    }
}
