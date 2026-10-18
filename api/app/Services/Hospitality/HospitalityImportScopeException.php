<?php

namespace App\Services\Hospitality;

use RuntimeException;

final class HospitalityImportScopeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode
    ) {
        parent::__construct($message);
    }
}
