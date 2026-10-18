<?php

namespace App\Services\Sync;

use RuntimeException;

final class MobileSaleSnapshotException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}
