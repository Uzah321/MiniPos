<?php

namespace App\Kitchen;

use App\Models\User;
use App\Sales\Sale;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Drives a kitchen order and its lines through the guide's Section 8 state
 * model. Line status only ever advances Sent -> ... -> ServedOrCollected;
 * the order's own status is derived from its active lines, except for the
 * final Completed state, which is only ever set by an explicit close().
 */
class KitchenOrderService
{
    private const ADVANCE_SEQUENCE = [
        KitchenOrderStatus::Sent,
        KitchenOrderStatus::Acknowledged,
        KitchenOrderStatus::Accepted,
        KitchenOrderStatus::Preparing,
        KitchenOrderStatus::Ready,
        KitchenOrderStatus::ServedOrCollected,
    ];

    private const TERMINAL_LINE_STATUSES = [KitchenOrderStatus::Cancelled, KitchenOrderStatus::Voided];

    private const EXCEPTION_LINE_STATUSES = [
        KitchenOrderStatus::Held, KitchenOrderStatus::Unavailable, KitchenOrderStatus::ChangePending,
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Send: create the order if needed, then send only the sale-line
     * quantity not already represented by an active kitchen line for that
     * item, so a second call after adding items sends just the new lines.
     */
    public function send(Sale $sale, User $actor): KitchenOrder
    {
        $order = KitchenOrder::query()
            ->where('sale_id', $sale->id)
            ->whereNotIn('status', [KitchenOrderStatus::Completed, KitchenOrderStatus::Cancelled])
            ->first();

        if (! $order) {
            $order = KitchenOrder::create([
                'sale_id' => $sale->id,
                'table_id' => $sale->table_id,
                'status' => KitchenOrderStatus::Draft,
            ]);
        }

        $alreadySent = $order->lines()
            ->whereNotIn('status', self::TERMINAL_LINE_STATUSES)
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $lines) => $lines->sum('quantity'));

        $newLines = [];

        foreach ($sale->lines()->with('item')->get() as $saleLine) {
            $outstanding = bcsub((string) $saleLine->quantity, (string) ($alreadySent->get($saleLine->item_id) ?? '0'), 4);

            if (bccomp($outstanding, '0', 4) > 0) {
                $newLines[] = $order->lines()->create([
                    'item_id' => $saleLine->item_id,
                    'station' => $saleLine->item->station ?? 'general',
                    'status' => KitchenOrderStatus::Sent,
                    'quantity' => $outstanding,
                ]);
            }
        }

        if (empty($newLines)) {
            throw ValidationException::withMessages([
                'sale' => ['There is nothing new to send to the kitchen.'],
            ]);
        }

        $this->recompute($order);

        $this->auditLogger->log($actor, 'kitchen_order.sent', $order, after: ['lines_sent' => count($newLines)]);

        return $order->fresh(['lines.item']);
    }

    public function advanceLine(KitchenOrderLine $line, User $actor): KitchenOrderLine
    {
        $index = array_search($line->status, self::ADVANCE_SEQUENCE, true);

        if ($index === false || $index === count(self::ADVANCE_SEQUENCE) - 1) {
            throw ValidationException::withMessages([
                'line' => ["This line cannot be advanced from its current status ({$line->status->value})."],
            ]);
        }

        $before = $line->status;
        $line->update(['status' => self::ADVANCE_SEQUENCE[$index + 1]]);

        $this->recompute($line->kitchenOrder);

        $this->auditLogger->log($actor, 'kitchen_order.line.advanced', $line, before: ['status' => $before->value], after: ['status' => $line->status->value]);

        return $line;
    }

    public function voidLine(KitchenOrderLine $line, string $reason, User $manager): KitchenOrderLine
    {
        if (in_array($line->status, self::TERMINAL_LINE_STATUSES, true)) {
            throw ValidationException::withMessages(['line' => ['This line has already been cancelled or voided.']]);
        }

        $before = $line->toArray();
        $line->update(['status' => KitchenOrderStatus::Voided]);

        $this->recompute($line->kitchenOrder);

        $this->auditLogger->log($manager, 'kitchen_order.line.voided', $line, before: $before, reason: $reason);

        return $line;
    }

    /**
     * Resend or reprint: creates a new line rather than mutating the one
     * already sent, so the station sees a clearly marked duplicate instead
     * of a silently repeated ticket.
     */
    public function resendLine(KitchenOrderLine $line, User $actor): KitchenOrderLine
    {
        $duplicate = $line->kitchenOrder->lines()->create([
            'item_id' => $line->item_id,
            'station' => $line->station,
            'status' => KitchenOrderStatus::Sent,
            'quantity' => $line->quantity,
            'notes' => $line->notes,
            'is_duplicate' => true,
        ]);

        $this->recompute($line->kitchenOrder);

        $this->auditLogger->log($actor, 'kitchen_order.line.resent', $duplicate, after: ['original_line_id' => $line->id]);

        return $duplicate;
    }

    /**
     * Handover: every active line must already be ready; moves them all to
     * served/collected together, matching "hand over all components".
     */
    public function handover(KitchenOrder $order, User $actor): KitchenOrder
    {
        $activeLines = $order->lines()->whereNotIn('status', self::TERMINAL_LINE_STATUSES)->get();

        if ($activeLines->isEmpty() || $activeLines->contains(fn (KitchenOrderLine $line) => $line->status !== KitchenOrderStatus::Ready)) {
            throw ValidationException::withMessages([
                'order' => ['Not every item is ready for handover yet.'],
            ]);
        }

        $order->lines()->where('status', KitchenOrderStatus::Ready)->update(['status' => KitchenOrderStatus::ServedOrCollected]);

        $this->recompute($order);

        $this->auditLogger->log($actor, 'kitchen_order.handed_over', $order);

        return $order->fresh(['lines.item']);
    }

    /**
     * Close: the deliberate final step once every line is served/collected
     * or cancelled/voided. Nothing but close() ever sets Completed.
     */
    public function close(KitchenOrder $order, User $actor): KitchenOrder
    {
        $unsettled = $order->lines()
            ->whereNotIn('status', [...self::TERMINAL_LINE_STATUSES, KitchenOrderStatus::ServedOrCollected])
            ->exists();

        if ($unsettled) {
            throw ValidationException::withMessages([
                'order' => ['This order still has items that are not served, collected, cancelled or voided.'],
            ]);
        }

        $order->update(['status' => KitchenOrderStatus::Completed]);

        $this->auditLogger->log($actor, 'kitchen_order.closed', $order);

        return $order;
    }

    /**
     * The order's status mirrors its least-advanced active line, so it only
     * reports Ready once every station is ready. An exception status on any
     * active line (held/unavailable/change pending) takes priority so it
     * surfaces at the order level too.
     */
    private function recompute(KitchenOrder $order): void
    {
        $activeLines = $order->lines()->whereNotIn('status', self::TERMINAL_LINE_STATUSES)->get();

        if ($activeLines->isEmpty()) {
            $order->update(['status' => KitchenOrderStatus::Cancelled]);

            return;
        }

        $exception = $activeLines->first(fn (KitchenOrderLine $line) => in_array($line->status, self::EXCEPTION_LINE_STATUSES, true));

        if ($exception) {
            $order->update(['status' => $exception->status]);

            return;
        }

        $leastAdvanced = $activeLines
            ->map(fn (KitchenOrderLine $line) => array_search($line->status, self::ADVANCE_SEQUENCE, true))
            ->min();

        $order->update(['status' => self::ADVANCE_SEQUENCE[$leastAdvanced]]);
    }
}
