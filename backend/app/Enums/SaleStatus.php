<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Priced = 'priced';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Committed = 'committed';
    case Completed = 'completed';

    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case PaymentUnknown = 'payment_unknown';
    case Failed = 'failed';
    case Voided = 'voided';
    case Returned = 'returned';
}
