<?php

namespace App\Enums;

enum TerminalStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
