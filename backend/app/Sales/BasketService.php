<?php

namespace App\Sales;

use App\Sales\SaleStatus;
use App\Items\Item;
use App\Discounts\Promotion;
use App\Sales\Sale;
use App\Sales\SaleLine;
use Illuminate\Validation\ValidationException;

/**
 * Builds and maintains a sale's basket: adding, changing and removing lines,
 * evaluating automatic promotions, and keeping the sale's subtotal/tax/total
 * in sync after every mutation. Money is computed with bcmath at 4 decimal
 * places to match the schema's decimal(12,4) columns and the guide's
 * fixed-precision-money control.
 *
 * Discounts are layered in two places: each line carries its own manual
 * discount_amount (set by SaleAdjustmentService) and an automatically
 * evaluated promotion_discount_amount (set here); both reduce the line's
 * taxable base before tax is computed. Coupon and loyalty discounts are
 * basket-level and are folded into the sale's discount_total by whichever
 * service applies them, then combined here on every recalculate.
 */
class BasketService
{
    private const SCALE = 4;

    public function addLine(Sale $sale, Item $item, string $quantity): SaleLine
    {
        $this->assertMutable($sale);
        $this->assertPositiveQuantity($quantity);

        $line = $sale->lines()->where('item_id', $item->id)->first();

        if ($line) {
            $line->quantity = bcadd((string) $line->quantity, $quantity, self::SCALE);
        } else {
            $line = $sale->lines()->make([
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_price' => (string) $item->price,
            ]);
        }

        $this->priceLine($line, $item);
        $line->save();

        $this->recalculate($sale);

        return $line->refresh();
    }

    public function updateLineQuantity(Sale $sale, SaleLine $line, string $quantity): SaleLine
    {
        $this->assertMutable($sale);
        $this->assertLineBelongsToSale($sale, $line);
        $this->assertPositiveQuantity($quantity);

        $line->quantity = $quantity;
        $this->priceLine($line, $line->item);
        $line->save();

        $this->recalculate($sale);

        return $line->refresh();
    }

    /**
     * Set (replacing any previous) manual discount amount on one line.
     */
    public function setLineDiscount(Sale $sale, SaleLine $line, string $discountAmount): SaleLine
    {
        $this->assertMutable($sale);
        $this->assertLineBelongsToSale($sale, $line);

        $line->discount_amount = $discountAmount;
        $this->priceLine($line, $line->item);
        $line->save();

        $this->recalculate($sale);

        return $line->refresh();
    }

    /**
     * Spread a basket-level manual discount proportionally across every
     * line's pre-discount subtotal, replacing each line's previous manual
     * discount_amount.
     */
    public function setBasketDiscount(Sale $sale, string $totalDiscountAmount): Sale
    {
        $this->assertMutable($sale);

        $lines = $sale->lines()->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'discount' => ['Add at least one item before applying a basket discount.'],
            ]);
        }

        $subtotal = $lines->reduce(
            fn (string $carry, SaleLine $line) => bcadd($carry, bcmul((string) $line->quantity, (string) $line->unit_price, self::SCALE), self::SCALE),
            '0.0000'
        );

        if (bccomp($subtotal, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages(['discount' => ['This basket has no value to discount.']]);
        }

        foreach ($lines as $line) {
            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, self::SCALE);
            $share = bcdiv(bcmul($lineSubtotal, $totalDiscountAmount, self::SCALE), $subtotal, self::SCALE);
            $line->discount_amount = $share;
            $this->priceLine($line, $line->item);
            $line->save();
        }

        return $this->recalculate($sale);
    }

    public function removeLine(Sale $sale, SaleLine $line): void
    {
        $this->assertMutable($sale);
        $this->assertLineBelongsToSale($sale, $line);

        $line->delete();

        $this->recalculate($sale);
    }

    public function recalculate(Sale $sale): Sale
    {
        $subtotal = '0.0000';
        $tax = '0.0000';
        $lineDiscounts = '0.0000';

        foreach ($sale->lines()->get() as $line) {
            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, self::SCALE);
            $subtotal = bcadd($subtotal, $lineSubtotal, self::SCALE);
            $tax = bcadd($tax, (string) $line->tax_amount, self::SCALE);
            $lineDiscounts = bcadd($lineDiscounts, bcadd((string) $line->discount_amount, (string) $line->promotion_discount_amount, self::SCALE), self::SCALE);
        }

        $discountTotal = bcadd(
            $lineDiscounts,
            bcadd((string) $sale->coupon_discount_amount, (string) $sale->loyalty_discount_amount, self::SCALE),
            self::SCALE
        );
        $total = bcsub(bcadd($subtotal, $tax, self::SCALE), $discountTotal, self::SCALE);

        if (bccomp($total, '0', self::SCALE) < 0) {
            $total = '0.0000';
        }

        $sale->update([
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'discount_total' => $discountTotal,
            'total' => $total,
            'status' => $sale->lines()->exists() ? SaleStatus::Priced : SaleStatus::Draft,
        ]);

        return $sale->fresh();
    }

    public function assertMutable(Sale $sale): void
    {
        if (! in_array($sale->status, [SaleStatus::Draft, SaleStatus::Priced], true)) {
            throw ValidationException::withMessages([
                'sale' => ["The basket can no longer be changed once the sale is {$sale->status->value}."],
            ]);
        }
    }

    private function priceLine(SaleLine $line, Item $item): void
    {
        $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, self::SCALE);

        $promotion = $this->evaluatePromotion($item, (string) $line->quantity, $lineSubtotal);
        $line->promotion_id = $promotion['promotion']?->id;
        $line->promotion_discount_amount = $promotion['amount'];

        $taxableBase = bcsub(
            bcsub($lineSubtotal, (string) ($line->discount_amount ?: '0.0000'), self::SCALE),
            $promotion['amount'],
            self::SCALE
        );

        if (bccomp($taxableBase, '0', self::SCALE) < 0) {
            $taxableBase = '0.0000';
        }

        $line->tax_amount = bcmul($taxableBase, (string) $item->tax_rate, self::SCALE);
        $line->line_total = bcadd($taxableBase, $line->tax_amount, self::SCALE);
    }

    /**
     * @return array{promotion: ?Promotion, amount: string}
     */
    private function evaluatePromotion(Item $item, string $quantity, string $lineSubtotal): array
    {
        $promotion = Promotion::query()
            ->where('item_id', $item->id)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->first();

        if (! $promotion || bccomp($quantity, (string) $promotion->min_quantity, self::SCALE) < 0) {
            return ['promotion' => null, 'amount' => '0.0000'];
        }

        $amount = $promotion->discount_type === 'percent'
            ? bcmul($lineSubtotal, bcdiv((string) $promotion->discount_value, '100', self::SCALE), self::SCALE)
            : bcmul($quantity, (string) $promotion->discount_value, self::SCALE);

        if (bccomp($amount, $lineSubtotal, self::SCALE) > 0) {
            $amount = $lineSubtotal;
        }

        return ['promotion' => $promotion, 'amount' => $amount];
    }

    private function assertPositiveQuantity(string $quantity): void
    {
        if (bccomp($quantity, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be greater than zero.'],
            ]);
        }
    }

    private function assertLineBelongsToSale(Sale $sale, SaleLine $line): void
    {
        if ($line->sale_id !== $sale->id) {
            throw ValidationException::withMessages([
                'line' => ['This line does not belong to the given sale.'],
            ]);
        }
    }
}
