<?php

namespace App\Shifts;

enum TillStatus: string
{
    case Open = 'open';
    case Counting = 'counting';
    case Closed = 'closed';
}
