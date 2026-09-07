<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\SaleAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    public function __construct(private readonly SaleAdjustmentService $adjustments) {}

    /**
     * Manual discount: on one line (line_id given) or spread across the
     * whole basket. A discount at or above the configured threshold
     * requires a manager PIN.
     */
    public function manual(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'line_id' => ['nullable', 'string', 'exists:sale_lines,id'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $line = null;

        if (! empty($data['line_id'])) {
            $line = SaleLine::findOrFail($data['line_id']);

            if ($line->sale_id !== $sale->id) {
                throw ValidationException::withMessages(['line_id' => ['This line does not belong to the given sale.']]);
            }
        }

        $sale = $this->adjustments->applyManualDiscount(
            $sale,
            $line,
            $data['type'],
            (string) $data['value'],
            $data['reason'],
            $data['manager_pin'] ?? null,
            $request->user(),
        );

        return response()->json(['sale' => $sale->load('lines.item')]);
    }

    /**
     * Coupon redemption: validated against authenticity, date window and
     * usage limit, then applied as a basket-level discount.
     */
    public function coupon(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate(['code' => ['required', 'string']]);

        $sale = $this->adjustments->applyCoupon($sale, $data['code'], $request->user());

        return response()->json(['sale' => $sale->load('lines.item', 'coupon')]);
    }

    /**
     * Loyalty redemption: spends the member's points as a discount against
     * the sale, committed with the sale total.
     */
    public function redeemLoyalty(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate(['points' => ['required', 'integer', 'min:1']]);

        $sale = $this->adjustments->redeemLoyalty($sale, $data['points'], $request->user());

        return response()->json(['sale' => $sale->load('lines.item', 'customer')]);
    }

    private function assertOwnedByCashier(Request $request, Sale $sale): void
    {
        if ($sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This sale does not belong to you.']]);
        }
    }
}
