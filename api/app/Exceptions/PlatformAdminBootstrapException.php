<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deliberately non-sensitive platform bootstrap failure that may be shown by the CLI.
 */
class PlatformAdminBootstrapException extends RuntimeException
{
}
