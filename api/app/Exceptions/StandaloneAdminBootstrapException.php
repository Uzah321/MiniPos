<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deliberately non-sensitive bootstrap failure that may be shown by the CLI.
 */
class StandaloneAdminBootstrapException extends RuntimeException
{
}
