<?php

namespace App\Payments\Gateways;

use App\Payments\PaymentStatus;

final class PaymentGatewayResult
{
    /**
     * @param  PaymentStatus  $status  One of Captured (approved), Declined or Unknown (timeout/no response).
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly string $providerReference,
    ) {}
}
