<?php

namespace App\Payments\Gateways;

interface PaymentGatewayInterface
{
    /**
     * Authorise a card or mobile/QR payment attempt.
     *
     * @param  string  $simulate  Test hook only: 'decline' forces a decline, 'timeout' forces an unknown outcome.
     */
    public function authorize(string $tenderType, string $reference, float $amount, ?string $simulate = null): PaymentGatewayResult;

    /**
     * Query a previously unknown/timed-out attempt by its original reference.
     *
     * @param  string  $resolve  Test hook only: 'approved' or 'declined' resolves the enquiry; omitted stays unknown.
     */
    public function inquire(string $reference, ?string $resolve = null): PaymentGatewayResult;

    /**
     * Reverse a previously approved authorisation (used when the sale-side
     * commit fails after the provider already approved the payment).
     */
    public function reverse(string $reference): void;

    /**
     * Refund a previously captured card or mobile/QR payment, identified by
     * its original provider reference.
     *
     * @param  string  $simulate  Test hook only: 'decline' forces a failed refund, 'timeout' forces an unknown outcome.
     */
    public function refund(string $reference, float $amount, ?string $simulate = null): PaymentGatewayResult;
}
