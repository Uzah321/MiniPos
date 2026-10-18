<?php

namespace Modules\Sales\Exceptions;

use RuntimeException;

class HospitalityTableConflictException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $currentRevision = null)
    {
        parent::__construct($message);
    }
}
