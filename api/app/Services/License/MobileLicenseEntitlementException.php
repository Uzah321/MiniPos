<?php

namespace App\Services\License;

use RuntimeException;

final class MobileLicenseEntitlementException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}
