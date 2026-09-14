<?php

namespace Modules\Shifts;

enum TillStatus: string
{
    case Open = 'open';
    case Counting = 'counting';
    case Closed = 'closed';
}
