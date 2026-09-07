<?php

namespace App\Payments;

use App\Payments\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Payments\Payment;
use App\Sales\Sale;
use App\Payments\SaleCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly SaleCheckoutService $checkout) {}

    public function index(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        return response()->json(['payments' => $sale->payments()->get()]);
    }

    /**
     * Take a payment attempt against a sale: cash, card or mobile/QR, single
     * or split tender. Idempotent by client-supplied idempotency_key; once
     * the sum of applied payments reaches the sale total, the sale commits
     * and a receipt number is allocated atomically.
     */
    public function store(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'tender_type' => ['required', 'in:cash,card,mobile_qr,store_credit'],
            'idempotency_key' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'amount_tendered' => ['required_if:tender_type,cash', 'nullable', 'numeric', 'gte:0'],
            'simulate' => ['nullable', 'in:decline,timeout,commit_failure'],
        ]);

        $result = $this->checkout->recordPayment($sale, $request->user(), $data);

        return $this->respond($result);
    }

    /**
     * Payment timeout/unknown: re-query the provider by the payment's
     * original reference. Safe to call repeatedly while unresolved.
     */
    public function query(Request $request, Sale $sale, Payment $payment)
    {
        $this->assertOwnedByCashier($request, $sale);
        $this->assertPaymentBelongsToSale($sale, $payment);

        $data = $request->validate([
            'resolve' => ['nullable', 'in:approved,declined'],
        ]);

        $result = $this->checkout->queryPayment($sale, $payment, $request->user(), $data['resolve'] ?? null);

        return $this->respond($result);
    }

    /**
     * Payment cancellation: only while an attempt is still unresolved
     * (pending or unknown) Ã¢â‚¬â€ a captured or declined attempt is already final.
     */
    public function cancel(Request $request, Sale $sale, Payment $payment)
    {
        $this->assertOwnedByCashier($request, $sale);
        $this->assertPaymentBelongsToSale($sale, $payment);

        $data = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $payment = $this->checkout->cancelPayment($sale, $payment, $request->user(), $data['reason'] ?? null);

        return response()->json(['payment' => $payment]);
    }

    /**
     * @param  array{payment: Payment, sale: Sale, change: ?string}  $result
     */
    private function respond(array $result)
    {
        $status = match ($result['payment']->status) {
            PaymentStatus::Declined => 422,
            PaymentStatus::Unknown => 202,
            PaymentStatus::Reversed => 409,
            default => 201,
        };

        return response()->json([
            'payment' => $result['payment'],
            'sale' => $result['sale']->load('lines.item', 'payments'),
            'change' => $result['change'],
        ], $status);
    }

    private function assertPaymentBelongsToSale(Sale $sale, Payment $payment): void
    {
        if ($payment->sale_id !== $sale->id) {
            throw ValidationException::withMessages(['payment' => ['This payment does not belong to the given sale.']]);
        }
    }

    private function assertOwnedByCashier(Request $request, Sale $sale): void
    {
        if ($sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This sale does not belong to you.']]);
        }
    }
}
