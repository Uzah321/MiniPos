<?php

namespace App\Services\Sync;

use RuntimeException;

class SyncRunAlreadyInProgress extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A synchronization run is already in progress for this edge node.');
    }
}
