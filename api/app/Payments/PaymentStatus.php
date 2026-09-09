<?php

namespace App\Payments;

enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Authorised = 'authorised';
    case Captured = 'captured';
    case Settled = 'settled';

    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
    case Reversed = 'reversed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
