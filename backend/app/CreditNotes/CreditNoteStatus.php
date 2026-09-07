<?php

namespace App\CreditNotes;

enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Approved = 'approved';
    case RefundPending = 'refund_pending';
    case Issued = 'issued';
    case Completed = 'completed';

    case Rejected = 'rejected';
    case RefundUnknown = 'refund_unknown';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
