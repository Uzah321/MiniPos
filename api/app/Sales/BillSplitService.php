<?php

namespace App\Sales;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Moves lines between sales that share a table: splitting one open bill
 * into several, or moving a single item from one guest's bill to another.
 * Every move is expressed as remove-then-add through BasketService so
 * pricing, tax and promotion evaluation stay correct on both sides.
 */
class BillSplitService
{
    private const SCALE = 4;

    public function __construct(
        private readonly BasketService $basketService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Split: creates a new sale on the same table and moves the requested
     * quantity of each given line into it, leaving the remainder (if any)
     * on the original sale.
     *
     * @param  array<int, array{sale_line_id: string, quantity?: string}>  $lineMoves
     * @return array{source: Sale, new: Sale}
     */
    public function split(Sale $sale, array $lineMoves, User $actor): array
    {
        $this->basketService->assertMutable($sale);

        if (empty($lineMoves)) {
            throw ValidationException::withMessages(['lines' => ['Select at least one item to move to the new bill.']]);
        }

        $newSale = Sale::create([
            'store_id' => $sale->store_id,
            'terminal_id' => $sale->terminal_id,
            'shift_id' => $sale->shift_id,
            'cashier_id' => $sale->cashier_id,
            'table_id' => $sale->table_id,
            'status' => SaleStatus::Draft,
        ]);

        foreach ($lineMoves as $move) {
            $line = $sale->lines()->whereKey($move['sale_line_id'])->firstOrFail();
            $quantity = (string) ($move['quantity'] ?? $line->quantity);

            $this->moveQuantity($sale, $newSale, $line, $quantity);
        }

        $this->auditLogger->log($actor, 'sale.split', $sale, after: ['new_sale_id' => $newSale->id]);

        return ['source' => $sale->fresh(), 'new' => $newSale->fresh()];
    }

    /**
     * Move item between guests: moves the given quantity of one line from
     * its current sale to another open sale on the same table.
     *
     * @return array{from: Sale, to: Sale}
     */
    public function moveLine(Sale $from, Sale $to, SaleLine $line, ?string $quantity, User $actor): array
    {
        $this->basketService->assertMutable($from);
        $this->basketService->assertMutable($to);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_sale_id' => ['Choose a different bill to move this item to.']]);
        }

        if ($to->table_id === null || $to->table_id !== $from->table_id) {
            throw ValidationException::withMessages(['to_sale_id' => ['The destination bill must be on the same table.']]);
        }

        if ($line->sale_id !== $from->id) {
            throw ValidationException::withMessages(['line' => ['This line does not belong to the given bill.']]);
        }

        $this->moveQuantity($from, $to, $line, $quantity ?? (string) $line->quantity);

        $this->auditLogger->log($actor, 'sale.line.moved', $line, after: ['from_sale_id' => $from->id, 'to_sale_id' => $to->id]);

        return ['from' => $from->fresh(), 'to' => $to->fresh()];
    }

    private function moveQuantity(Sale $from, Sale $to, SaleLine $line, string $quantity): void
    {
        if (bccomp($quantity, '0', self::SCALE) <= 0 || bccomp($quantity, (string) $line->quantity, self::SCALE) > 0) {
            throw ValidationException::withMessages([
                'quantity' => ["Quantity must be greater than zero and no more than {$line->quantity} for this item."],
            ]);
        }

        $item = $line->item;
        $remaining = bcsub((string) $line->quantity, $quantity, self::SCALE);

        if (bccomp($remaining, '0', self::SCALE) > 0) {
            $this->basketService->updateLineQuantity($from, $line, $remaining);
        } else {
            $this->basketService->removeLine($from, $line);
        }

        $this->basketService->addLine($to, $item, $quantity);
    }
}
