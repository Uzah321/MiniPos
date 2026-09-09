<?php

namespace App\Payments\Gateways;

use App\Payments\PaymentStatus;
use Illuminate\Support\Str;

/**
 * Scaffold-stage stand-in for a real card/mobile-money provider. It always
 * approves unless the caller explicitly asks it to simulate a decline or a
 * timeout, so the checkout flow (including its exception paths) can be
 * built and tested end-to-end before a real provider integration lands in
 * a later sprint.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    public function authorize(string $tenderType, string $reference, float $amount, ?string $simulate = null): PaymentGatewayResult
    {
        return match ($simulate) {
            'decline' => new PaymentGatewayResult(PaymentStatus::Declined, 'FAKE-DECLINED-'.Str::upper(Str::random(8))),
            'timeout' => new PaymentGatewayResult(PaymentStatus::Unknown, 'FAKE-PENDING-'.Str::upper(Str::random(8))),
            default => new PaymentGatewayResult(PaymentStatus::Captured, 'FAKE-'.Str::upper(Str::random(12))),
        };
    }

    public function inquire(string $reference, ?string $resolve = null): PaymentGatewayResult
    {
        return match ($resolve) {
            'approved' => new PaymentGatewayResult(PaymentStatus::Captured, $reference),
            'declined' => new PaymentGatewayResult(PaymentStatus::Declined, $reference),
            default => new PaymentGatewayResult(PaymentStatus::Unknown, $reference),
        };
    }

    public function reverse(string $reference): void
    {
        // No real provider to call back in this scaffold; a production gateway
        // implementation would issue the reversal request here.
    }

    public function refund(string $reference, float $amount, ?string $simulate = null): PaymentGatewayResult
    {
        return match ($simulate) {
            'decline' => new PaymentGatewayResult(PaymentStatus::Declined, 'FAKE-REFUND-DECLINED-'.Str::upper(Str::random(8))),
            'timeout' => new PaymentGatewayResult(PaymentStatus::Unknown, 'FAKE-REFUND-PENDING-'.Str::upper(Str::random(8))),
            default => new PaymentGatewayResult(PaymentStatus::Captured, 'FAKE-REFUND-'.Str::upper(Str::random(12))),
        };
    }
}
