<?php

namespace App\Shifts;

enum ShiftStatus: string
{
    case Opening = 'opening';
    case Open = 'open';
    case Counting = 'counting';
    case Reconciling = 'reconciling';
    case Closed = 'closed';

    case Suspended = 'suspended';
    case VarianceUnderReview = 'variance_under_review';
    case Reopened = 'reopened';
}
