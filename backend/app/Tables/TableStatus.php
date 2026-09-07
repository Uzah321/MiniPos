<?php

namespace App\Tables;

enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case BillRequested = 'bill_requested';
    case PaymentPending = 'payment_pending';
    case Closed = 'closed';

    case Transferred = 'transferred';
    case Merged = 'merged';
    case Split = 'split';
    case BlockedForReview = 'blocked_for_review';
}
