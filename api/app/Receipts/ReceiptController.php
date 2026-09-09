<?php

namespace App\Receipts;

use App\Http\Controllers\Controller;
use App\Payments\PaymentStatus;
use App\Payments\SaleCheckoutService;
use App\Sales\Sale;
use App\Sales\SaleStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function __construct(private readonly SaleCheckoutService $checkout) {}

    public function show(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        if ($sale->status !== SaleStatus::Completed) {
            return response()->json(['message' => 'This sale does not have a receipt yet.'], 422);
        }

        return response()->json(['receipt' => $this->receiptPayload($sale)]);
    }

    /**
     * Receipt reprint: only ever available for a completed sale, always
     * marked and audited so it can never be mistaken for the original.
     */
    public function reprint(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $sale = $this->checkout->reprintReceipt($sale, $request->user(), $data['reason'] ?? null);

        return response()->json(['receipt' => $this->receiptPayload($sale, reprint: true)]);
    }

    private function receiptPayload(Sale $sale, bool $reprint = false): array
    {
        $sale->load('lines.item', 'payments', 'store', 'customer', 'coupon');

        return [
            'receipt_number' => $sale->receipt_number,
            'issued_at' => $sale->receipt_issued_at,
            'is_reprint' => $reprint,
            'reprint_count' => $sale->reprint_count,
            'store' => $sale->store?->only(['name', 'code']),
            'customer' => $sale->customer?->only(['name', 'loyalty_id']),
            'lines' => $sale->lines->map(fn ($line) => [
                'item' => $line->item?->name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'promotion_discount_amount' => $line->promotion_discount_amount,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ]),
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'coupon' => $sale->coupon ? ['code' => $sale->coupon->code, 'amount' => $sale->coupon_discount_amount] : null,
            'loyalty' => $sale->customer ? [
                'points_earned' => $sale->loyalty_points_earned,
                'points_redeemed' => $sale->loyalty_points_redeemed,
                'redeemed_amount' => $sale->loyalty_discount_amount,
                'balance' => $sale->customer->loyalty_points_balance,
            ] : null,
            'tax_total' => $sale->tax_total,
            'total' => $sale->total,
            'payments' => $sale->payments
                ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Settled])
                ->map(fn ($payment) => [
                    'tender_type' => $payment->tender_type,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                ])
                ->values(),
        ];
    }

    private function assertOwnedByCashier(Request $request, Sale $sale): void
    {
        if ($sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This sale does not belong to you.']]);
        }
    }
}
