<?php

namespace Modules\Terminals;

enum TerminalStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
