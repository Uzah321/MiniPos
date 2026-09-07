<?php

namespace App\Enums;

enum TillStatus: string
{
    case Open = 'open';
    case Counting = 'counting';
    case Closed = 'closed';
}
